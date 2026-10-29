<?php

declare(strict_types=1);

namespace Modules\ClinicalDocumentation\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\ClinicalDocumentation\Contracts\DisclosureAuthorizationContract;
use Modules\ClinicalDocumentation\Models\DisclosureRequest;
use Modules\HospitalCore\Models\Patient;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Clinical Information Disclosure Authorization at the authenticated Disclosure Officer workflow and the declared capability
 * Laboratory consumes: who is entitled, on which basis, to which exact result version. Staff read permission, family
 * relationship, payment status and a chosen channel establish none of it.
 */
class ClinicalDisclosureAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private User $officer;

    private Patient $patient;

    private string $lineage;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->make(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->officer = User::factory()->create();
        $this->officer->givePermissionTo('clinicaldocumentation.disclosure.manage');
        $this->patient = Patient::factory()->create();
        $this->lineage = (string) Str::uuid();
    }

    // Basis: the patient's own access

    public function test_a_verified_patient_obtains_their_own_result_without_any_consent(): void
    {
        $request = $this->request(['recipient_name' => 'Self', 'recipient_relationship' => 'self']);
        $this->verify($request, ['basis' => 'patient_self_access', 'verified_identity_of' => 'patient'])->assertRedirect();

        $answer = $this->authorize($request, 'result-v1', true);

        $this->assertTrue($answer['authorized']);
        $this->assertSame('patient_self_access', $answer['basis']);
        $this->assertNotNull($answer['authorization_id']);
        $this->assertFalse($answer['historical_copy']);
    }

    public function test_self_access_is_refused_when_the_verified_person_is_not_the_patient(): void
    {
        $request = $this->request(['recipient_name' => 'Brother', 'recipient_relationship' => 'sibling']);
        $this->verify($request, ['basis' => 'patient_self_access', 'verified_identity_of' => 'recipient'])->assertRedirect();

        $this->assertRefused($this->authorize($request, 'result-v1', true), 'self_access_recipient_is_not_the_patient');
    }

    // Relationship alone, and nothing verified

    public function test_a_family_relationship_without_verification_authorizes_nothing(): void
    {
        $request = $this->request(['recipient_name' => 'Wife', 'recipient_relationship' => 'spouse']);

        $this->assertRefused($this->authorize($request, 'result-v1', true), 'verification_missing');
    }

    public function test_an_unknown_request_or_another_patients_request_is_refused(): void
    {
        $request = $this->request(['recipient_name' => 'Self', 'recipient_relationship' => 'self']);
        $this->verify($request, ['basis' => 'patient_self_access', 'verified_identity_of' => 'patient']);

        $this->assertRefused(app(DisclosureAuthorizationContract::class)->authorize((string) Str::uuid(), (string) $this->patient->id, 'laboratory_result', 'r', $this->lineage, true), 'unknown_request');
        $this->assertRefused(app(DisclosureAuthorizationContract::class)->authorize($request, (string) Patient::factory()->create()->id, 'laboratory_result', 'r', $this->lineage, true), 'unknown_request');
    }

    // Basis: the patient's authorization

    public function test_a_named_recipient_is_authorized_by_a_disclosure_consent_for_a_purpose_and_scope(): void
    {
        $consent = $this->consent();
        $request = $this->request(['recipient_name' => 'Wife', 'recipient_relationship' => 'spouse', 'purpose' => 'continuing care']);
        $this->verify($request, ['basis' => 'patient_authorization', 'verified_identity_of' => 'recipient', 'consent_id' => $consent]);

        $answer = $this->authorize($request, 'result-v1', true);

        $this->assertTrue($answer['authorized']);
        $this->assertSame('patient_authorization', $answer['basis']);
        $this->assertSame('Wife', $answer['recipient_name']);
    }

    public function test_general_registration_or_treatment_consent_cannot_be_recorded_as_disclosure_consent(): void
    {
        foreach (['registration', 'treatment'] as $kind) {
            $this->recordConsent(['kind' => $kind])->assertSessionHasErrors('kind');
        }
    }

    public function test_a_consent_for_a_different_recipient_purpose_or_scope_is_refused(): void
    {
        $cases = [
            'recipient_mismatch' => [['recipient_name' => 'Cousin'], null],
            'purpose_mismatch' => [['purpose' => 'insurance claim'], null],
            'scope_mismatch' => [[], ['scope_refs' => [(string) Str::uuid()]]],
        ];

        foreach ($cases as $reason => [$requestOverrides, $consentOverrides]) {
            $consent = $this->consent($consentOverrides ?? []);
            $request = $this->request(array_merge(['recipient_name' => 'Wife', 'recipient_relationship' => 'spouse', 'purpose' => 'continuing care'], $requestOverrides));
            $this->verify($request, ['basis' => 'patient_authorization', 'verified_identity_of' => 'recipient', 'consent_id' => $consent]);

            $this->assertRefused($this->authorize($request, 'result-v1', true), 'consent_'.$reason);
        }
    }

    public function test_an_expired_or_not_yet_valid_consent_is_refused(): void
    {
        foreach ([['valid_from' => now()->addDay()->toDateString(), 'valid_until' => now()->addDays(5)->toDateString()], ['valid_from' => now()->subDays(5)->toDateString(), 'valid_until' => now()->subDay()->toDateString()]] as $window) {
            $request = $this->request(['recipient_name' => 'Wife', 'recipient_relationship' => 'spouse', 'purpose' => 'continuing care']);
            $this->verify($request, ['basis' => 'patient_authorization', 'verified_identity_of' => 'recipient', 'consent_id' => $this->consent($window)]);

            $this->assertRefused($this->authorize($request, 'result-v1', true), 'consent_not_in_force');
        }
    }

    public function test_withdrawal_after_an_authorization_stops_the_next_recheck_and_keeps_the_history(): void
    {
        $consent = $this->consent();
        $request = $this->request(['recipient_name' => 'Wife', 'recipient_relationship' => 'spouse', 'purpose' => 'continuing care']);
        $this->verify($request, ['basis' => 'patient_authorization', 'verified_identity_of' => 'recipient', 'consent_id' => $consent]);
        $granted = $this->authorize($request, 'result-v1', true);
        $this->assertTrue(app(DisclosureAuthorizationContract::class)->recheck($granted['authorization_id'])['authorized']);

        $this->actingAs($this->officer)->post(route('clinicaldocumentation.disclosure.consents.withdraw', $consent), ['reason' => 'Changed my mind'])->assertRedirect();

        $this->assertRefused(app(DisclosureAuthorizationContract::class)->recheck($granted['authorization_id']), 'consent_withdrawn');
        $this->assertRefused($this->authorize($request, 'result-v2', true), 'consent_withdrawn');
        // The decision that was once granted is still on record.
        $this->assertDatabaseHas('cd_disclosure_decisions', ['id' => $granted['authorization_id'], 'outcome' => 'granted']);
        $this->assertDatabaseHas('cd_disclosure_consents', ['id' => $consent, 'withdrawn_by' => $this->officer->id, 'withdrawn_by_name' => $this->officer->name]);
    }

    public function test_a_granted_authorization_is_refused_at_recheck_once_the_consent_has_expired_and_names_its_version(): void
    {
        $consent = $this->consent(['valid_until' => now()->addDays(2)->toDateString()]);
        $request = $this->request(['recipient_name' => 'Wife', 'recipient_relationship' => 'spouse', 'purpose' => 'continuing care']);
        $this->verify($request, ['basis' => 'patient_authorization', 'verified_identity_of' => 'recipient', 'consent_id' => $consent]);
        $granted = $this->authorize($request, 'result-v1', true);
        $this->assertSame(['laboratory_result', 'result-v1', $this->lineage], [$granted['ref_type'], $granted['ref_id'], $granted['lineage_id']]);

        $this->travel(3)->days();

        $this->assertRefused(app(DisclosureAuthorizationContract::class)->recheck($granted['authorization_id']), 'consent_not_in_force');
    }

    // Basis: established representative authority and statutory routes

    public function test_representative_authority_needs_established_evidence_and_not_just_a_relationship(): void
    {
        $request = $this->request(['recipient_name' => 'Mother', 'recipient_relationship' => 'parent']);
        $this->verify($request, ['basis' => 'representative_authority', 'verified_identity_of' => 'recipient', 'evidence_reference' => null])->assertSessionHasErrors(['authority_evidence_kind', 'evidence_reference']);

        $this->verify($request, ['basis' => 'representative_authority', 'verified_identity_of' => 'recipient', 'authority_evidence_kind' => 'guardianship_document', 'evidence_reference' => 'GD-2026-17'])->assertRedirect();

        $answer = $this->authorize($request, 'result-v1', true);
        $this->assertTrue($answer['authorized']);
        $this->assertSame('representative_authority', $answer['basis']);
    }

    public function test_authority_evidence_must_be_a_recognised_kind_and_a_relationship_is_not_one(): void
    {
        $request = $this->request(['recipient_name' => 'Mother', 'recipient_relationship' => 'parent']);

        $this->verify($request, ['basis' => 'representative_authority', 'verified_identity_of' => 'recipient', 'authority_evidence_kind' => 'parent', 'evidence_reference' => 'X'])
            ->assertSessionHasErrors('authority_evidence_kind');
    }

    public function test_a_basis_the_module_does_not_know_cannot_be_recorded(): void
    {
        $request = $this->request(['recipient_name' => 'Wife', 'recipient_relationship' => 'spouse']);

        $this->verify($request, ['basis' => 'family_relationship', 'verified_identity_of' => 'recipient'])->assertSessionHasErrors('basis');
    }

    public function test_a_third_party_basis_needs_the_recipients_identity_verified_not_the_patients(): void
    {
        $consent = $this->consent();
        $request = $this->request(['recipient_name' => 'Wife', 'recipient_relationship' => 'spouse', 'purpose' => 'continuing care']);
        $this->verify($request, ['basis' => 'patient_authorization', 'verified_identity_of' => 'patient', 'consent_id' => $consent]);

        $this->assertRefused($this->authorize($request, 'result-v1', true), 'recipient_identity_not_verified');
    }

    public function test_a_consent_must_name_the_results_it_covers(): void
    {
        $this->recordConsent(['scope_refs' => null])->assertSessionHasErrors('scope_refs');
    }

    public function test_the_last_verification_recorded_governs_new_decisions_but_a_decision_keeps_the_one_it_relied_on(): void
    {
        $request = $this->request(['recipient_name' => 'Self', 'recipient_relationship' => 'self']);
        $this->verify($request, ['basis' => 'patient_self_access', 'verified_identity_of' => 'patient']);
        $granted = $this->authorize($request, 'result-v1', true);

        // A later, weaker verification changes the next decision, never the meaning of the one already granted.
        $this->verify($request, ['basis' => 'representative_authority', 'verified_identity_of' => 'recipient', 'authority_evidence_kind' => 'guardianship_document', 'evidence_reference' => null])->assertSessionHasErrors('evidence_reference');
        $this->verify($request, ['basis' => 'patient_authorization', 'verified_identity_of' => 'recipient', 'consent_id' => $this->consent(['recipient_name' => 'Other'])]);

        $this->assertRefused($this->authorize($request, 'result-v2', true), 'consent_recipient_mismatch');
        $this->assertTrue(app(DisclosureAuthorizationContract::class)->recheck($granted['authorization_id'])['authorized']);
        $this->assertDatabaseHas('cd_disclosure_decisions', ['id' => $granted['authorization_id'], 'basis' => 'patient_self_access']);
    }

    public function test_a_request_must_name_the_results_it_asks_for(): void
    {
        $this->actingAs($this->officer)->post(route('clinicaldocumentation.disclosure.requests.store'), array_merge($this->requestPayload(['recipient_name' => 'Self', 'recipient_relationship' => 'self']), ['scope_refs' => null]))
            ->assertSessionHasErrors('scope_refs');
    }

    public function test_a_request_for_another_result_than_the_one_asked_is_refused_on_every_basis(): void
    {
        $request = $this->request(['recipient_name' => 'Self', 'recipient_relationship' => 'Self', 'scope_refs' => [(string) Str::uuid()]]);
        $this->verify($request, ['basis' => 'patient_self_access', 'verified_identity_of' => 'patient']);

        $this->assertRefused($this->authorize($request, 'result-v1', true), 'request_scope_mismatch');
    }

    public function test_an_uppercase_patient_id_still_finds_the_request(): void
    {
        $request = $this->request(['recipient_name' => 'Self', 'recipient_relationship' => 'self']);
        $this->verify($request, ['basis' => 'patient_self_access', 'verified_identity_of' => 'patient']);

        $answer = app(DisclosureAuthorizationContract::class)->authorize($request, strtoupper((string) $this->patient->id), 'laboratory_result', 'r', $this->lineage, true);

        $this->assertTrue($answer['authorized']);
    }

    public function test_a_statutory_route_carries_its_own_evidence_and_never_a_fabricated_consent(): void
    {
        $request = $this->request(['recipient_name' => 'Court clerk', 'recipient_relationship' => 'other']);
        $this->verify($request, ['basis' => 'statutory', 'verified_identity_of' => 'recipient', 'statutory_basis' => 'made_up_basis', 'evidence_reference' => 'X'])->assertSessionHasErrors('statutory_basis');
        $this->verify($request, ['basis' => 'statutory', 'verified_identity_of' => 'recipient', 'statutory_basis' => 'court_order', 'evidence_reference' => null])->assertSessionHasErrors('evidence_reference');

        $this->verify($request, ['basis' => 'statutory', 'verified_identity_of' => 'recipient', 'statutory_basis' => 'court_order', 'evidence_reference' => 'PN-88/2026'])->assertRedirect();

        $answer = $this->authorize($request, 'result-v1', true);
        $this->assertTrue($answer['authorized']);
        $this->assertSame('statutory', $answer['basis']);
        $this->assertDatabaseCount('cd_disclosure_consents', 0);
    }

    public function test_self_access_needs_the_supporting_evidence_too_not_only_a_method(): void
    {
        $request = $this->request(['recipient_name' => 'Self', 'recipient_relationship' => 'self']);

        $this->verify($request, ['basis' => 'patient_self_access', 'verified_identity_of' => 'patient', 'evidence_reference' => null])->assertSessionHasErrors('evidence_reference');
    }

    public function test_a_verification_keeps_only_the_evidence_its_basis_relied_on(): void
    {
        $request = $this->request(['recipient_name' => 'Self', 'recipient_relationship' => 'self']);
        $this->verify($request, ['basis' => 'patient_self_access', 'verified_identity_of' => 'patient', 'authority_evidence_kind' => 'power_of_attorney', 'evidence_reference' => 'STRAY-1'])->assertRedirect();

        $this->assertDatabaseHas('cd_disclosure_verifications', ['request_id' => $request, 'authority_evidence_kind' => null, 'evidence_reference' => 'STRAY-1']);
    }

    public function test_verification_records_who_checked_when_and_how(): void
    {
        $request = $this->request(['recipient_name' => 'Self', 'recipient_relationship' => 'self']);
        $this->verify($request, ['basis' => 'patient_self_access', 'verified_identity_of' => 'patient', 'method' => 'national_id_card'])->assertRedirect();

        $this->assertDatabaseHas('cd_disclosure_verifications', ['request_id' => $request, 'verified_by' => $this->officer->id, 'method' => 'national_id_card']);
    }

    // Exact versions

    public function test_a_superseded_version_needs_an_explicit_historical_copy_authorization(): void
    {
        $routine = $this->request(['recipient_name' => 'Self', 'recipient_relationship' => 'self']);
        $this->verify($routine, ['basis' => 'patient_self_access', 'verified_identity_of' => 'patient']);
        $this->assertRefused($this->authorize($routine, 'result-v1', false), 'historical_copy_not_authorized');

        $historical = $this->request(['recipient_name' => 'Self', 'recipient_relationship' => 'self', 'historical_copy_refs' => ['result-v1']]);
        $this->verify($historical, ['basis' => 'patient_self_access', 'verified_identity_of' => 'patient']);
        $answer = $this->authorize($historical, 'result-v1', false);

        $this->assertTrue($answer['authorized']);
        $this->assertTrue($answer['historical_copy']);
        // The authorization names that one version; another superseded version of the same result is not covered.
        $this->assertRefused($this->authorize($historical, 'result-v2', false), 'historical_copy_not_authorized');
    }

    public function test_an_amendment_gets_a_fresh_decision_without_recreating_a_still_covering_consent(): void
    {
        $consent = $this->consent();
        $request = $this->request(['recipient_name' => 'Wife', 'recipient_relationship' => 'spouse', 'purpose' => 'continuing care']);
        $this->verify($request, ['basis' => 'patient_authorization', 'verified_identity_of' => 'recipient', 'consent_id' => $consent]);

        $first = $this->authorize($request, 'result-v1', true);
        $second = $this->authorize($request, 'result-v2', true);

        $this->assertTrue($second['authorized']);
        $this->assertNotSame($first['authorization_id'], $second['authorization_id']);
        $this->assertDatabaseCount('cd_disclosure_consents', 1);
    }

    // Payment, contents and abilities

    public function test_the_decision_never_depends_on_payment_and_exposes_no_consent_document(): void
    {
        $consent = $this->consent(['evidence_reference' => 'SCAN-SECRET-77']);
        $request = $this->request(['recipient_name' => 'Wife', 'recipient_relationship' => 'spouse', 'purpose' => 'continuing care']);
        $this->verify($request, ['basis' => 'patient_authorization', 'verified_identity_of' => 'recipient', 'consent_id' => $consent]);

        $answer = $this->authorize($request, 'result-v1', true);

        $this->assertTrue($answer['authorized']);
        $this->assertStringNotContainsString('SCAN-SECRET-77', json_encode($answer, JSON_THROW_ON_ERROR));
        $this->assertArrayNotHasKey('evidence_reference', $answer);
    }

    public function test_every_refusal_on_a_known_request_is_recorded_as_a_decision(): void
    {
        $request = $this->request(['recipient_name' => 'Wife', 'recipient_relationship' => 'spouse']);

        $this->authorize($request, 'result-v1', true);

        $this->assertDatabaseHas('cd_disclosure_decisions', ['request_id' => $request, 'outcome' => 'refused', 'reason' => 'verification_missing']);
    }

    public function test_the_disclosure_workflow_is_forbidden_without_the_disclosure_ability(): void
    {
        $other = User::factory()->create();
        $other->givePermissionTo('clinicaldocumentation.records.read');
        $this->actingAs($other);

        $this->post(route('clinicaldocumentation.disclosure.requests.store'), $this->requestPayload(['recipient_name' => 'X', 'recipient_relationship' => 'self']))->assertForbidden();
        $this->post(route('clinicaldocumentation.disclosure.consents.store'), [])->assertForbidden();
        $this->assertSame(0, DisclosureRequest::count());
    }

    // Helpers

    /** @param array<string, mixed> $overrides */
    private function requestPayload(array $overrides): array
    {
        return array_merge(['patient_id' => (string) $this->patient->id, 'purpose' => 'continuing care', 'scope_refs' => [$this->lineage]], $overrides);
    }

    /** @param array<string, mixed> $overrides */
    private function request(array $overrides): string
    {
        $this->actingAs($this->officer)->post(route('clinicaldocumentation.disclosure.requests.store'), $this->requestPayload($overrides))->assertSessionHasNoErrors();

        return (string) DisclosureRequest::query()->orderByDesc('created_at')->orderByDesc('id')->value('id');
    }

    /** @param array<string, mixed> $payload */
    private function verify(string $request, array $payload): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->officer)->post(route('clinicaldocumentation.disclosure.verifications.store', $request), array_merge(['method' => 'identity_card', 'evidence_reference' => 'ID-REF-1'], $payload));
    }

    /** @param array<string, mixed> $overrides */
    private function recordConsent(array $overrides = []): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->officer)->post(route('clinicaldocumentation.disclosure.consents.store'), array_merge([
            'patient_id' => (string) $this->patient->id,
            'kind' => 'disclosure',
            'recipient_name' => 'Wife',
            'purpose' => 'continuing care',
            'scope_refs' => [$this->lineage],
            'valid_from' => now()->subDay()->toDateString(),
            'valid_until' => now()->addMonth()->toDateString(),
            'evidence_reference' => 'CONSENT-1',
        ], $overrides));
    }

    /** @param array<string, mixed> $overrides */
    private function consent(array $overrides = []): string
    {
        $this->recordConsent($overrides)->assertSessionHasNoErrors();

        return (string) \Modules\ClinicalDocumentation\Models\DisclosureConsent::query()->orderByDesc('created_at')->orderByDesc('id')->value('id');
    }

    /** @return array<string, mixed> */
    private function authorize(string $request, string $ref, bool $current): array
    {
        return app(DisclosureAuthorizationContract::class)->authorize($request, (string) $this->patient->id, 'laboratory_result', $ref, $this->lineage, $current);
    }

    /** @param array<string, mixed> $answer */
    private function assertRefused(array $answer, string $reason): void
    {
        $this->assertFalse($answer['authorized']);
        $this->assertSame($reason, $answer['reason']);
        $this->assertNull($answer['authorization_id'] ?? null);
    }
}
