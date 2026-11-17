<?php

declare(strict_types=1);

namespace Modules\ClinicalDocumentation\Services;

use Modules\ClinicalDocumentation\Exceptions\CapabilityUnavailable;
use Modules\ClinicalDocumentation\Models\DiagnosticResultEvidence;
use Modules\ClinicalDocumentation\Support\CapabilityGateway;
use Modules\ClinicalDocumentation\Support\RetentionDestruction;

/**
 * The custodians a destruction touches: the diagnostic custodian (reached by capability ID, which answers for itself and for every
 * specialty registered with it) and this module's own copies of the findings, the Diagnostic Result Evidence projected from a specialty's results.
 */
final class DestructionCustody
{
    public const OWN = 'clinicaldocumentation';

    public const OWN_EVIDENCE = 'diagnostic_result_evidence';

    private const CAPABILITY = 'diagnostics.record-custody';

    public function __construct(private readonly CapabilityGateway $gateway) {}

    /** Whether the diagnostic custodian can be asked what it holds. Its absence is not the same as it holding nothing. */
    public function available(): bool
    {
        return $this->gateway->provides(self::CAPABILITY);
    }

    /**
     * What is held for the patient, and whether the custodian could say all of it.
     *
     * Every copy is listed, destroyed ones included, as custodian / type / id / state. `complete` is false when the diagnostic custodian is
     * not composed, did not answer in the documented shape, or reports a specialty it could not ask or no longer has: that is incomplete
     * custody, never an empty one.
     *
     * @return array{items: list<array{custodian: string, ref_type: string, ref_id: string, state: string}>, complete: bool, blockers: list<array{custodian: string, reason: string}>}
     */
    public function inventory(string $patientId): array
    {
        $items = [];
        $resultRefs = [];
        $complete = true;
        $blockers = [];

        $inventory = $this->gateway->callIfAvailable(self::CAPABILITY, 'inventory', [$patientId]);
        if ($inventory !== null && (! is_array($inventory) || ! is_array($inventory['items'] ?? null) || ! is_bool($inventory['complete'] ?? null))) {
            $complete = false;
            $blockers[] = ['custodian' => 'diagnostics', 'reason' => 'custody_malformed'];
            $inventory = null;
        }
        foreach (($inventory['items'] ?? []) as $item) {
            $items[] = ['custodian' => (string) $item['custodian'], 'ref_type' => (string) $item['ref_type'], 'ref_id' => (string) $item['ref_id'], 'state' => (string) $item['state']];
            if (isset($item['result_reference_id'])) {
                $resultRefs[(string) $item['custodian']][] = (string) $item['result_reference_id'];
            }
        }
        if ($inventory !== null && $inventory['complete'] !== true) {
            $complete = false;
            array_push($blockers, ...array_values(array_filter((array) ($inventory['blockers'] ?? []), 'is_array')));
        }
        // Found by the stable source reference, never by a pointer stored on the source side; the specialty's service kind is the evidence's owner.
        foreach ($resultRefs as $owner => $refs) {
            foreach (DiagnosticResultEvidence::query()->where('source_owner', $owner)->whereIn('result_reference_id', $refs)->orderBy('id')->get() as $evidence) {
                $items[] = ['custodian' => self::OWN, 'ref_type' => self::OWN_EVIDENCE, 'ref_id' => $evidence->id, 'state' => $evidence->destroyed_at === null ? 'live' : 'destroyed'];
            }
        }

        return ['items' => $items, 'complete' => $complete, 'blockers' => $blockers];
    }

    /**
     * Destroy one copy. Idempotent: a repeat returns the same receipt.
     *
     * @param  array{authorization_id: string, patient_id: string, ref_type: string, ref_id: string, actor_id: string}  $step
     * @return array{receipt_id: string}
     */
    public function destroy(string $custodian, array $step): array
    {
        if ($custodian !== self::OWN) {
            $receipt = $this->gateway->callIfAvailable(self::CAPABILITY, 'destroy', [['custodian' => $custodian] + $step]);
            // The copy is a custodian's to destroy: with none composed there is nobody who can, and no receipt to invent.
            if (! is_array($receipt) || ! is_string($receipt['receipt_id'] ?? null) || $receipt['receipt_id'] === '') {
                throw CapabilityUnavailable::required(self::CAPABILITY);
            }

            return ['receipt_id' => $receipt['receipt_id']];
        }

        $evidence = DiagnosticResultEvidence::query()->findOrFail($step['ref_id']);
        if ($evidence->destroyed_at === null) {
            RetentionDestruction::run(fn () => $evidence->update(['summary' => null, 'destroyed_at' => now()]));
        }

        // This module is its own custodian: the receipt is the step itself, stable for a given destruction and copy.
        return ['receipt_id' => 'own-'.substr(hash('sha256', $step['authorization_id'].':'.$step['ref_id']), 0, 40)];
    }
}
