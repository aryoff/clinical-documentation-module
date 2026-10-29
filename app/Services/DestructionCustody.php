<?php

declare(strict_types=1);

namespace Modules\ClinicalDocumentation\Services;

use Modules\ClinicalDocumentation\Exceptions\CapabilityUnavailable;
use Modules\ClinicalDocumentation\Models\DiagnosticResultEvidence;
use Modules\ClinicalDocumentation\Support\CapabilityGateway;
use Modules\ClinicalDocumentation\Support\RetentionDestruction;

/**
 * The custodians a destruction touches: Laboratory (reached by capability ID) and this module's own copies of the
 * findings, the Diagnostic Result Evidence projected from Laboratory's results.
 */
final class DestructionCustody
{
    public const LABORATORY = 'laboratory';

    public const OWN = 'clinicaldocumentation';

    public const OWN_EVIDENCE = 'diagnostic_result_evidence';

    private const LABORATORY_CAPABILITY = 'laboratory.record-custody';

    public function __construct(private readonly CapabilityGateway $gateway) {}

    /** Whether Laboratory can be asked what it holds. Its absence is not the same as it holding nothing. */
    public function laboratoryAvailable(): bool
    {
        return $this->gateway->provides(self::LABORATORY_CAPABILITY);
    }

    /**
     * Every copy held for the patient, destroyed ones included, as custodian / type / id / state.
     *
     * @return list<array{custodian: string, ref_type: string, ref_id: string, state: string}>
     */
    public function inventory(string $patientId): array
    {
        $items = [];
        $resultRefs = [];
        $inventory = $this->gateway->callIfAvailable(self::LABORATORY_CAPABILITY, 'inventory', [$patientId]);
        foreach (($inventory['items'] ?? []) as $item) {
            $items[] = ['custodian' => self::LABORATORY, 'ref_type' => $item['ref_type'], 'ref_id' => $item['ref_id'], 'state' => $item['state']];
            if ($item['ref_type'] === 'result_version') {
                $resultRefs[] = $item['result_reference_id'] ?? $item['ref_id'];
            }
        }
        // Found by the stable source reference, never by a pointer stored on the source side.
        if ($resultRefs !== []) {
            foreach (DiagnosticResultEvidence::query()->where('source_owner', 'laboratory')->whereIn('result_reference_id', $resultRefs)->orderBy('id')->get() as $evidence) {
                $items[] = ['custodian' => self::OWN, 'ref_type' => self::OWN_EVIDENCE, 'ref_id' => $evidence->id, 'state' => $evidence->destroyed_at === null ? 'live' : 'destroyed'];
            }
        }

        return $items;
    }

    /**
     * Destroy one copy. Idempotent: a repeat returns the same receipt.
     *
     * @param  array{authorization_id: string, patient_id: string, ref_type: string, ref_id: string, actor_id: string}  $step
     * @return array{receipt_id: string}
     */
    public function destroy(string $custodian, array $step): array
    {
        if ($custodian === self::LABORATORY) {
            $receipt = $this->gateway->callIfAvailable(self::LABORATORY_CAPABILITY, 'destroy', [$step]);
            // The copy is Laboratory's to destroy: with no Laboratory composed there is nobody who can, and no receipt to invent.
            if (! is_array($receipt)) {
                throw CapabilityUnavailable::required(self::LABORATORY_CAPABILITY);
            }

            return ['receipt_id' => (string) $receipt['receipt_id']];
        }

        $evidence = DiagnosticResultEvidence::query()->findOrFail($step['ref_id']);
        if ($evidence->destroyed_at === null) {
            RetentionDestruction::run(fn () => $evidence->update(['summary' => null, 'destroyed_at' => now()]));
        }

        // This module is its own custodian: the receipt is the step itself, stable for a given destruction and copy.
        return ['receipt_id' => 'own-'.substr(hash('sha256', $step['authorization_id'].':'.$step['ref_id']), 0, 40)];
    }
}
