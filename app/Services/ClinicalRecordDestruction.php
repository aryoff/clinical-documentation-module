<?php

declare(strict_types=1);

namespace Modules\ClinicalDocumentation\Services;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\ClinicalDocumentation\Models\ClinicalArchivePackage;
use Modules\ClinicalDocumentation\Models\Destruction;
use Modules\ClinicalDocumentation\Models\DestructionItem;
use Modules\ClinicalDocumentation\Models\DiagnosisAssertion;
use Modules\ClinicalDocumentation\Models\DiagnosticResultEvidence;
use Modules\ClinicalDocumentation\Support\ProtectionLock;

/**
 * Destroying a patient's electronic findings once retention allows it (root ADR 0033, #473, #488).
 *
 * The mandatory duty is to keep records at least 25 years from the last visit; destruction afterwards is optional. It is
 * one authorized action that acknowledges no retained clinical record still needs the content, runs the custodians' steps
 * one at a time (each asked afresh whether it is still allowed) and leaves a processing history without the findings.
 * A worker or retry can finish what was started; nothing destroys on its own.
 */
final class ClinicalRecordDestruction
{
    public function __construct(
        private readonly ClinicalRecordRetentionReview $review,
        private readonly DestructionCustody $custody,
        private readonly DestructionEnablement $enablement,
    ) {}

    public function destroy(string $patientId, string $reason, bool $dependenciesAcknowledged, string $actorId): Destruction
    {
        if (! $this->enablement->enabled()) {
            throw $this->refusal('destruction', 'destruction_not_enabled');
        }
        if (! $dependenciesAcknowledged) {
            throw $this->refusal('dependencies_acknowledged', 'destruction_dependencies_unacknowledged');
        }
        ['items' => $items, 'blockers' => $blockers] = $this->assess($patientId);
        // A destruction already started for this patient and not finished: doing it again finishes that one, and takes
        // in any live copy the person has not yet seen, so a stopped destruction is never stuck behind a discovered copy.
        $open = Destruction::query()->where('patient_id', $patientId)->whereNull('completed_at')->orderBy('started_at')->first();
        if ($open !== null) {
            $blockers = array_values(array_diff($blockers, ['nothing_to_destroy']));
        }
        if ($blockers !== []) {
            throw $this->refusal('patient_id', 'destruction_not_permitted');
        }
        if ($open !== null) {
            $held = $open->items()->get()->keyBy(fn (DestructionItem $i): string => $i->custodian.'|'.$i->ref_type.'|'.$i->ref_id);
            foreach ($items as $item) {
                $existing = $held->get($item['custodian'].'|'.$item['ref_type'].'|'.$item['ref_id']);
                if ($existing === null) {
                    DestructionItem::create(['destruction_id' => $open->id] + array_intersect_key($item, array_flip(['custodian', 'ref_type', 'ref_id'])));
                } elseif ($existing->status === 'destroyed') {
                    // A copy this destruction emptied that holds content again (it is listed live): that is new content, taken in as a step to run again.
                    // Its receipt and the time of the earlier removal stay on the record (the same authorization gets the same receipt back); the time
                    // moves only when the step has run.
                    $existing->update(['status' => 'pending', 'failure' => null]);
                }
            }

            return $this->run($open);
        }

        $destruction = DB::transaction(function () use ($patientId, $reason, $actorId, $items): Destruction {
            $destruction = Destruction::create([
                'patient_id' => $patientId,
                'reason' => $reason,
                'dependencies_acknowledged' => true,
                'destroyed_by' => $actorId,
                'destroyed_by_name' => User::query()->whereKey($actorId)->value('name'),
                'started_at' => CarbonImmutable::now(),
            ]);
            foreach ($items as $item) {
                DestructionItem::create(['destruction_id' => $destruction->id] + array_intersect_key($item, array_flip(['custodian', 'ref_type', 'ref_id'])));
            }

            return $destruction;
        });

        return $this->run($destruction);
    }

    /** Finish or retry the remaining steps of a destruction that was started. Never starts one. */
    public function resume(string $destructionId): Destruction
    {
        if (! $this->enablement->enabled()) {
            throw $this->refusal('destruction', 'destruction_not_enabled');
        }

        $destruction = Str::isUuid($destructionId) ? Destruction::query()->find($destructionId) : null;
        if ($destruction === null) {
            throw $this->refusal('destruction', 'destruction_not_authorized');
        }

        return $this->run($destruction);
    }

    /** @return string pending, partial or complete */
    public function status(Destruction $destruction): string
    {
        return match (true) {
            $destruction->completed_at !== null => 'complete',
            $destruction->items()->whereIn('status', ['destroyed', 'failed'])->exists() => 'partial',
            default => 'pending',
        };
    }

    /**
     * Whether ONE step is still allowed, or why not. Asked by every custodian before each irreversible step: the
     * protection that allowed the destruction may have changed since it started.
     */
    public function problem(Destruction $destruction): ?string
    {
        if (! $this->enablement->enabled()) {
            return 'destruction_not_enabled';
        }
        if ($this->review->assess($destruction->patient_id)['blockers'] !== []) {
            return 'protection_changed';
        }
        // The custodian is asked once per check, and what it could not say is a change too: a specialty that stopped being asked mid-way is not "nothing more".
        $custody = $this->custody->inventory($destruction->patient_id);
        if ($this->dependencyBlockers($destruction->patient_id, $destruction->items()->pluck('ref_id')->all(), $custody['complete'], array_column($custody['blockers'], 'reason')) !== []) {
            return 'dependency_changed';
        }

        return $this->discovered($destruction, $custody['items']) ? 'copy_discovered' : null;
    }

    private function run(Destruction $destruction): Destruction
    {
        foreach ($destruction->items()->where('status', '!=', 'destroyed')->get() as $item) {
            $outcome = $this->step($destruction->refresh(), $item);
            if ($outcome === 'stale') {
                // What was destroyed stays destroyed; the rest stops and can be reassessed.
                throw $this->refusal('destruction', 'destruction_not_authorized');
            }
            if ($outcome === 'failed') {
                break;
            }
        }
        $destruction->refresh();
        if ($destruction->completed_at === null && ! $destruction->items()->where('status', '!=', 'destroyed')->exists()) {
            $destruction->update(['completed_at' => CarbonImmutable::now()]);
        }

        return $destruction->refresh();
    }

    /** @return string ok | failed | stale */
    private function step(Destruction $destruction, DestructionItem $item): string
    {
        $item->update(['attempts' => $item->attempts + 1]);
        try {
            // One step at a time against hold and extension writes for this patient: the check and the custodian call
            // happen under the same lock those writes take, so a hold cannot slip between them.
            $receipt = DB::transaction(function () use ($destruction, $item): ?array {
                ProtectionLock::forPatient($destruction->patient_id);
                if ($this->problem($destruction) !== null) {
                    return null;
                }

                return $this->custody->destroy($item->custodian, [
                    'authorization_id' => $destruction->id,
                    'patient_id' => $destruction->patient_id,
                    'ref_type' => $item->ref_type,
                    'ref_id' => $item->ref_id,
                    'actor_id' => (string) $destruction->destroyed_by,
                ]);
            });
        } catch (\Throwable $failure) {
            // The step may or may not have happened; the item says only that no receipt arrived, and a retry is safe
            // because every custodian answers a repeat with the receipt it already issued.
            $item->update(['status' => 'failed', 'failure' => mb_substr($failure->getMessage(), 0, 250)]);

            return 'failed';
        }
        if ($receipt === null) {
            return 'stale';
        }
        $item->update(['status' => 'destroyed', 'receipt_id' => $receipt['receipt_id'], 'failure' => null, 'destroyed_at' => CarbonImmutable::now()]);

        return 'ok';
    }

    /**
     * What a destruction for this patient would cover, and what stands in the way.
     *
     * @return array{items: list<array{custodian: string, ref_type: string, ref_id: string, state: string}>, blockers: list<string>}
     */
    private function assess(string $patientId): array
    {
        $blockers = $this->review->assess($patientId)['blockers'];
        $custody = $this->custody->inventory($patientId);
        $items = array_values(array_filter($custody['items'], fn (array $item): bool => $item['state'] === 'live'));
        array_push($blockers, ...$this->dependencyBlockers($patientId, array_column($items, 'ref_id'), $custody['complete'], array_column($custody['blockers'], 'reason')));
        if ($items === []) {
            $blockers[] = 'nothing_to_destroy';
        }

        return ['items' => $items, 'blockers' => $blockers];
    }

    /**
     * What a retained record or an unproven vault copy still holds, or an owner that cannot be asked at all.
     *
     * @param  list<string>  $itemRefs
     * @param  bool  $custodyComplete  whether the diagnostic custodian, when composed, could say everything it holds
     * @param  list<string>  $custodyReasons  why it could not, as it said (a specialty not registered, offering no custody, unreachable or malformed)
     * @return list<string>
     */
    private function dependencyBlockers(string $patientId, array $itemRefs, bool $custodyComplete, array $custodyReasons): array
    {
        $blockers = [];
        if ($this->citedEvidence($patientId, $itemRefs) !== []) {
            $blockers[] = 'cited_by_retained_record';
        }
        if (ClinicalArchivePackage::query()->where('patient_id', $patientId)->exists()) {
            $blockers[] = 'vault_custody_unproven';
        }
        // A specialty's findings were projected here (whether or not those copies were since destroyed) but its custodian cannot be asked what
        // else it holds: that history is not "nothing".
        if (! $this->custody->available() && DiagnosticResultEvidence::query()->where('patient_id', $patientId)->exists()) {
            $blockers[] = 'custody_unavailable';
        }
        // The custodian answered but could not say all of it (a specialty it cannot ask, offers no custody or no longer has): not "nothing" either.
        if (! $custodyComplete) {
            array_push($blockers, 'custody_incomplete', ...array_values(array_unique($custodyReasons)));
        }

        return $blockers;
    }

    /** @param list<string> $evidenceIds @return list<string> the ids a retained assertion cites */
    private function citedEvidence(string $patientId, array $evidenceIds): array
    {
        $cited = [];
        foreach (DiagnosisAssertion::query()->where('patient_id', $patientId)->whereNotNull('evidence_refs')->pluck('evidence_refs') as $refs) {
            $cited = [...$cited, ...array_intersect((array) $refs, $evidenceIds)];
        }

        return array_values(array_unique($cited));
    }

    /**
     * Anything held for the patient that the person did not see when they acted: a discovered copy.
     *
     * @param  list<array{custodian: string, ref_type: string, ref_id: string, state: string}>  $inventory
     */
    private function discovered(Destruction $destruction, array $inventory): bool
    {
        $items = $destruction->items()->get();
        $named = $items->map(fn (DestructionItem $i): string => $i->custodian.'|'.$i->ref_type.'|'.$i->ref_id)->all();
        // A copy this destruction already destroyed that holds content again was written to since: new content the person did not see.
        $emptied = $items->where('status', 'destroyed')->map(fn (DestructionItem $i): string => $i->custodian.'|'.$i->ref_type.'|'.$i->ref_id)->all();
        foreach ($inventory as $held) {
            $key = $held['custodian'].'|'.$held['ref_type'].'|'.$held['ref_id'];
            // A copy already destroyed (by this or an earlier destruction) is not new; only a live one the person did not see is.
            if ($held['state'] === 'live' && (! in_array($key, $named, true) || in_array($key, $emptied, true))) {
                return true;
            }
        }

        return false;
    }

    private function refusal(string $field, string $key): ValidationException
    {
        return ValidationException::withMessages([$field => __('clinicaldocumentation::messages.'.$key)]);
    }
}
