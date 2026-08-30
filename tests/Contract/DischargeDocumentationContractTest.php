<?php

declare(strict_types=1);

namespace Modules\ClinicalDocumentation\Tests\Contract;

use App\Models\User;
use App\Support\CapabilityRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Modules\ClinicalDocumentation\Contracts\ActiveClinicalRecordContract;
use Modules\ClinicalDocumentation\Contracts\DischargeDocumentationContract;
use Modules\ClinicalDocumentation\Models\DischargeReceipt;
use Tests\TestCase;

/**
 * Whether an episode's discharge documentation is actually complete.
 *
 * The load-bearing assertions are the ones that refuse. A capability that
 * answered "there is a document" would be trivially satisfiable and would let a
 * hospital certify a discharge summary with no follow-up plan, never explained
 * to anybody, written before the patient's last diagnosis.
 */
class DischargeDocumentationContractTest extends TestCase
{
    use RefreshDatabase;

    private const CAPABILITY_ID = 'clinicaldocumentation.discharge-documentation';

    private const REGISTRATION = 'f3a5e0ad-4f0d-4795-8e5f-d62441b2a513';

    private DischargeDocumentationContract $documentation;

    private ActiveClinicalRecordContract $records;

    private User $clinician;

    protected function setUp(): void
    {
        parent::setUp();

        $this->documentation = app(DischargeDocumentationContract::class);
        $this->records = app(ActiveClinicalRecordContract::class);
        $this->clinician = User::factory()->create(['name' => 'dr. Sri Handayani']);
    }

    public function test_the_manifest_declares_the_contract_and_its_evidence(): void
    {
        $provider = $this->declaredProvider();
        $modulePath = base_path('Modules/ClinicalDocumentation');

        $this->assertSame('1.0.0', $provider['version']);
        $this->assertSame(['sync'], $provider['modes']);
        $this->assertSame(DischargeDocumentationContract::class, $provider['binding']);
        $this->assertFileExists($modulePath.'/'.$provider['contracts']['sync']);
        $this->assertFileExists($modulePath.'/'.$provider['providerContractTest']);
    }

    public function test_the_required_elements_are_published_with_their_owners(): void
    {
        $elements = $this->documentation->requiredElements();

        foreach ([
            'admission_reason',
            'diagnoses',
            'findings',
            'procedures_and_treatment',
            'medication_changes',
            'take_home_medicines',
            'condition_at_discharge',
            'follow_up_plan',
            'final_instructions',
        ] as $element) {
            $this->assertArrayHasKey($element, $elements);
            $this->assertNotSame('', trim($elements[$element]), "[{$element}] names no owner.");
        }
    }

    // ── Presence is not completeness ───────────────────────────────────────

    public function test_a_summary_missing_an_element_cannot_be_signed_and_says_which(): void
    {
        $draft = $this->draftSummary($this->completePayload(['follow_up_plan' => null]));

        try {
            $this->documentation->signSummary($draft['document_id'], (string) $this->clinician->id);
            $this->fail('An incomplete discharge summary was signed.');
        } catch (\InvalidArgumentException $refused) {
            $this->assertStringContainsString('follow_up_plan', $refused->getMessage());
            // And who to chase for it.
            $this->assertStringContainsString('Responsible clinician', $refused->getMessage());
        }
    }

    /**
     * A key present and empty is missing.
     *
     * `"follow_up_plan": ""` passes any presence test and says nothing, which
     * is the failure a presence test cannot see.
     */
    public function test_an_element_present_but_empty_counts_as_missing(): void
    {
        $draft = $this->draftSummary($this->completePayload(['final_instructions' => '   ']));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('final_instructions');

        $this->documentation->signSummary($draft['document_id'], (string) $this->clinician->id);
    }

    public function test_a_complete_summary_signs_and_records_its_author_and_version(): void
    {
        $signed = $this->signedSummary();

        $this->assertSame('signed', $signed['status']);
        $this->assertSame('dr. Sri Handayani', $signed['signed_by_name']);
        $this->assertNotNull($signed['signed_at']);
        $this->assertSame('1.0', $signed['template_version']);
    }

    // ── Signing is not explaining ──────────────────────────────────────────

    public function test_a_signed_but_unexplained_summary_is_not_complete(): void
    {
        $this->signedSummary();

        $outcome = $this->describeCompletion();

        $this->assertFalse($outcome['complete']);
        $this->assertSame(
            ['explanation_receipt'],
            array_column($outcome['missing'], 'element'),
        );
    }

    public function test_a_draft_cannot_be_explained(): void
    {
        $draft = $this->draftSummary($this->completePayload());

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Only a signed discharge summary can be explained');

        $this->documentation->recordExplanation([
            'document_id' => $draft['document_id'],
            'recipient_kind' => DischargeReceipt::TO_PATIENT,
            'recipient_name' => 'Wayan Sudirja',
            'explanation_summary' => 'Explained at the bedside.',
        ], (string) $this->clinician->id);
    }

    public function test_an_explanation_to_the_patient_completes_the_documentation(): void
    {
        $signed = $this->signedSummary();
        $this->explain($signed['document_id']);

        $outcome = $this->describeCompletion();

        $this->assertTrue($outcome['complete']);
        $this->assertSame([], $outcome['missing']);
        $this->assertSame('patient', $outcome['receipt']['recipient_kind']);
        // The audit trail a release record cites.
        $this->assertSame($signed['document_id'], $outcome['document_id']);
        $this->assertSame('1.0', $outcome['template_version']);
        $this->assertNotNull($outcome['signed_at']);
    }

    public function test_an_explanation_to_a_representative_records_their_relationship(): void
    {
        $signed = $this->signedSummary();

        $this->explain($signed['document_id'], [
            'recipient_kind' => DischargeReceipt::TO_REPRESENTATIVE,
            'recipient_name' => 'Ni Made Sudirja',
            'recipient_relationship' => 'spouse',
        ]);

        $outcome = $this->describeCompletion();

        $this->assertTrue($outcome['complete']);
        $this->assertSame('spouse', $outcome['receipt']['recipient_relationship']);
    }

    public function test_a_representative_must_be_identified_by_their_relationship(): void
    {
        $signed = $this->signedSummary();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('relationship');

        $this->explain($signed['document_id'], [
            'recipient_kind' => DischargeReceipt::TO_REPRESENTATIVE,
            'recipient_name' => 'Somebody at the desk',
        ]);
    }

    /**
     * Whether the explanation actually reached the person.
     *
     * An explanation delivered in a language the recipient does not read is not
     * one, and the record has to be able to say which it was.
     */
    public function test_the_receipt_records_the_language_and_the_support_it_used(): void
    {
        $signed = $this->signedSummary();

        $this->explain($signed['document_id'], [
            'language' => 'Bahasa Bali',
            'interpreter_name' => 'Wayan Sukerti',
            'accessibility_support' => 'Large-print instructions; explanation repeated aloud.',
        ]);

        $receipt = $this->describeCompletion()['receipt'];

        $this->assertSame('Bahasa Bali', $receipt['language']);
        $this->assertSame('Wayan Sukerti', $receipt['interpreter_name']);
        $this->assertStringContainsString('Large-print', $receipt['accessibility_support']);
    }

    // ── Completeness expires ───────────────────────────────────────────────

    /**
     * Something clinical happened after the summary was written.
     *
     * A new signed document dated later means the summary no longer describes
     * the episode. The ward is told so, rather than discovering it when the
     * patient comes back.
     */
    public function test_a_later_signed_document_makes_the_documentation_stale(): void
    {
        $signed = $this->signedSummary();
        $this->explain($signed['document_id']);
        $this->assertTrue($this->describeCompletion()['complete']);

        Carbon::setTestNow(Carbon::now()->addHour());
        $this->signOtherDocument();
        Carbon::setTestNow();

        $outcome = $this->describeCompletion();

        $this->assertFalse($outcome['complete']);
        $this->assertTrue($outcome['superseded_by_later_evidence']);
    }

    /**
     * And a signed addendum brings it back — without the summary being
     * rewritten, which the immutability rule forbids anyway.
     */
    public function test_a_signed_addendum_restores_readiness(): void
    {
        $signed = $this->signedSummary();
        $this->explain($signed['document_id']);

        Carbon::setTestNow(Carbon::now()->addHour());
        $this->signOtherDocument();
        $this->assertFalse($this->describeCompletion()['complete']);

        Carbon::setTestNow(Carbon::now()->addHour());
        $addendum = $this->records->createAddendum([
            'document_id' => $signed['document_id'],
            'reason' => 'New diagnosis of atrial fibrillation added after the summary was signed.',
            'payload' => ['diagnoses' => 'Community-acquired pneumonia; new atrial fibrillation.'],
            'encountered_at' => Carbon::now()->toAtomString(),
        ], (string) $this->clinician->id);
        $this->records->signAddendum($addendum['addendum_id'], (string) $this->clinician->id);
        Carbon::setTestNow();

        $outcome = $this->describeCompletion();

        $this->assertTrue($outcome['complete']);
        $this->assertFalse($outcome['superseded_by_later_evidence']);
    }

    public function test_an_episode_with_no_summary_reports_every_element_as_missing(): void
    {
        $outcome = $this->documentation->describeCompletion(self::REGISTRATION);

        $this->assertFalse($outcome['complete']);
        $this->assertNull($outcome['document_id']);
        $this->assertCount(count($this->documentation->requiredElements()), $outcome['missing']);
    }

    // ── Fixtures ───────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function describeCompletion(): array
    {
        // Reached by capability ID, exactly as a consumer must reach it.
        foreach (app(CapabilityRegistry::class)->providerBindings(self::CAPABILITY_ID) as $binding) {
            if (app()->bound($binding)) {
                return app($binding)->describeCompletion(self::REGISTRATION);
            }
        }

        $this->fail('No enabled provider answers '.self::CAPABILITY_ID.'.');
    }

    /** @param array<string, mixed> $overrides */
    private function completePayload(array $overrides = []): array
    {
        return array_merge([
            'admission_reason' => 'Community-acquired pneumonia.',
            'diagnoses' => 'Community-acquired pneumonia, right lower lobe.',
            'findings' => 'Consolidation on chest film; CRP 180 falling to 22.',
            'procedures_and_treatment' => 'IV ceftriaxone five days, stepped down to oral.',
            'medication_changes' => 'Amlodipine held during admission, restarted on discharge.',
            'take_home_medicines' => 'Amoxicillin 500 mg three times daily for two days.',
            'condition_at_discharge' => 'Afebrile, saturating 97% on air, mobilising independently.',
            'follow_up_plan' => 'Chest clinic in six weeks; repeat film before the appointment.',
            'final_instructions' => 'Return if breathless, febrile, or coughing blood.',
        ], $overrides);
    }

    /** @param array<string, mixed> $payload */
    private function draftSummary(array $payload): array
    {
        $handoff = $this->records->acceptHandoff([
            'registration_id' => self::REGISTRATION,
            'patient_id' => 'e759fc76-5732-4fe1-a14d-a820cda7bbcb',
            'source_owner' => 'inpatient',
            'source_reference_id' => (string) \Illuminate\Support\Str::uuid(),
            'recipient_id' => (string) $this->clinician->id,
            'accepted_by' => (string) $this->clinician->id,
        ]);

        return $this->documentation->draftSummary([
            'handoff_id' => $handoff['handoff_id'],
            'template_version' => '1.0',
            'payload' => $payload,
        ], (string) $this->clinician->id);
    }

    /** @return array<string, mixed> */
    private function signedSummary(): array
    {
        $draft = $this->draftSummary($this->completePayload());

        return $this->documentation->signSummary($draft['document_id'], (string) $this->clinician->id);
    }

    /** @param array<string, mixed> $overrides */
    private function explain(string $documentId, array $overrides = []): void
    {
        $this->documentation->recordExplanation(array_merge([
            'document_id' => $documentId,
            'recipient_kind' => DischargeReceipt::TO_PATIENT,
            'recipient_name' => 'Wayan Sudirja',
            'explanation_summary' => 'Summary, medicines and follow-up explained at the bedside.',
        ], $overrides), (string) $this->clinician->id);
    }

    /** Something else clinical, signed, on the same journey. */
    private function signOtherDocument(): void
    {
        $handoff = $this->records->acceptHandoff([
            'registration_id' => self::REGISTRATION,
            'patient_id' => 'e759fc76-5732-4fe1-a14d-a820cda7bbcb',
            'source_owner' => 'inpatient',
            'source_reference_id' => (string) \Illuminate\Support\Str::uuid(),
            'recipient_id' => (string) $this->clinician->id,
            'accepted_by' => (string) $this->clinician->id,
        ]);

        $draft = $this->records->createDraft([
            'handoff_id' => $handoff['handoff_id'],
            'template' => 'soap',
            'template_version' => '1.0.0',
            'encountered_at' => Carbon::now()->toAtomString(),
            'payload' => ['assessment' => 'New atrial fibrillation on the pre-discharge ECG.'],
        ], (string) $this->clinician->id);

        $this->records->signDocument($draft['document_id'], (string) $this->clinician->id);
    }

    /** @return array<string, mixed> */
    private function declaredProvider(): array
    {
        $manifest = json_decode(
            file_get_contents(base_path('Modules/ClinicalDocumentation/module.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        foreach ($manifest['capabilities']['provides'] as $provider) {
            if ($provider['id'] === self::CAPABILITY_ID) {
                return $provider;
            }
        }

        $this->fail('The manifest declares no provider for '.self::CAPABILITY_ID.'.');
    }
}
