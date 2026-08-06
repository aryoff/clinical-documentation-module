<?php

declare(strict_types=1);

namespace Modules\ClinicalDocumentation\Tests\Contract;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\ClinicalDocumentation\Contracts\ActiveClinicalRecordContract;
use Tests\TestCase;

class MedicalRecordsCiphertextVaultConsumerTest extends TestCase
{
    use RefreshDatabase;

    public function test_unresolved_optional_vault_keeps_a_signed_document_in_sealed_local_retention(): void
    {
        $clinician = User::factory()->create();
        $records = app(ActiveClinicalRecordContract::class);
        $handoff = $records->acceptHandoff([
            'registration_id' => 'f3a5e0ad-4f0d-4795-8e5f-d62441b2a513',
            'patient_id' => 'e759fc76-5732-4fe1-a14d-a820cda7bbcb',
            'source_owner' => 'outpatient',
            'source_reference_id' => 'f0f4c31f-9c19-4d85-9a05-12f0725761db',
            'recipient_id' => (string) $clinician->id,
            'accepted_by' => (string) $clinician->id,
        ]);
        $draft = $records->createDraft([
            'handoff_id' => $handoff['handoff_id'],
            'template' => 'soap',
            'template_version' => '1.0.0',
            'encountered_at' => now()->toAtomString(),
            'payload' => ['assessment' => 'Safe for discharge.'],
        ], (string) $clinician->id);
        $signed = $records->signDocument($draft['document_id'], (string) $clinician->id);

        $archive = $records->archiveDocument($signed['document_id'], (string) $clinician->id);

        $this->assertSame('local_retention', $archive['custody_state']);
    }
}
