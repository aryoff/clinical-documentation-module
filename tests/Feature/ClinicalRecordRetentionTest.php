<?php

declare(strict_types=1);

namespace Modules\ClinicalDocumentation\Tests\Feature;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\ClinicalDocumentation\Contracts\PatientLastVisitPort;
use Modules\ClinicalDocumentation\Listeners\ReassignReconciledPatient;
use Modules\ClinicalDocumentation\Models\RetentionSchedule;
use Modules\HospitalCore\Contracts\AttendanceEvidenceContract;
use Modules\HospitalCore\Models\Patient;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Clinical Record Retention at the authenticated records workflow: versioned schedules, the legal-hold register and the
 * review that says whether a patient's records may even be considered for destruction. Nothing here can destroy
 * anything; the review is reporting only, and destruction stays disabled.
 */
class ClinicalRecordRetentionTest extends TestCase
{
    use RefreshDatabase;

    private User $officer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->make(PermissionRegistrar::class)->forgetCachedPermissions();
        config(['hospitalcore.attendance.expected_providers' => ['laboratory']]);
        $this->officer = User::factory()->create();
        foreach (['view', 'schedule', 'hold', 'extend'] as $ability) {
            $this->officer->givePermissionTo('clinicaldocumentation.retention.'.$ability);
        }
    }

    // Schedules

    public function test_the_first_schedule_is_version_one_with_a_twenty_five_year_floor(): void
    {
        $this->schedule(25)->assertRedirect();

        $schedule = RetentionSchedule::sole();
        $this->assertSame(1, $schedule->version);
        $this->assertSame(25, $schedule->minimum_years);
        $this->assertSame((string) $this->officer->id, (string) $schedule->created_by);
    }

    public function test_a_schedule_below_the_floor_is_refused(): void
    {
        $this->schedule(24)->assertSessionHasErrors('minimum_years');

        $this->assertSame(0, RetentionSchedule::count());
    }

    public function test_a_new_version_may_extend_but_never_shorten_protection(): void
    {
        $this->schedule(30)->assertRedirect();

        $this->schedule(26)->assertSessionHasErrors('minimum_years');
        $this->schedule(40)->assertRedirect();

        $this->assertSame([1, 2], RetentionSchedule::orderBy('version')->pluck('version')->all());
        $this->assertSame(40, RetentionSchedule::orderByDesc('version')->first()->minimum_years);
    }

    public function test_a_recorded_schedule_version_cannot_be_edited_or_deleted(): void
    {
        $this->schedule(25);
        $schedule = RetentionSchedule::sole();

        try {
            $schedule->update(['minimum_years' => 1]);
            $this->fail('A schedule version is immutable.');
        } catch (\LogicException) {
            $this->assertSame(25, $schedule->refresh()->minimum_years);
        }
        $this->expectException(\LogicException::class);
        $schedule->delete();
    }

    // Review

    public function test_a_patient_whose_protection_has_lapsed_with_complete_evidence_may_be_reviewed(): void
    {
        $this->schedule(25);
        $patient = $this->patientWhoLastVisited('2000-01-01 09:00:00');

        $review = $this->review($patient);

        $this->assertTrue($review['eligible_for_review']);
        $this->assertSame([], $review['blockers']);
        $this->assertSame(1, $review['schedule_version']);
        $this->assertTrue($review['coverage_complete']);
        $this->assertNotNull($review['coverage_through']);
        $this->assertFalse($review['destruction_enabled']);
    }

    public function test_expiry_permits_review_only_and_is_never_a_destruction_enablement(): void
    {
        $this->schedule(25);
        $patient = $this->patientWhoLastVisited('2000-01-01 09:00:00');

        $this->assertFalse($this->review($patient)['destruction_enabled']);
        config(['clinicaldocumentation.retention.destruction_enabled' => true]);
        $this->assertFalse($this->review($patient)['destruction_enabled']);
    }

    public function test_a_later_visit_moves_the_protection_of_the_old_records(): void
    {
        $this->schedule(25);
        $patient = $this->patientWhoLastVisited('2000-01-01 09:00:00');
        $this->attend($patient, 'lab-late', '2015-06-01 09:00:00');
        $this->vouchCoverage();

        $review = $this->review($patient);

        $this->assertFalse($review['eligible_for_review']);
        $this->assertContains('retention_not_expired', $review['blockers']);
        $this->assertSame(CarbonImmutable::parse('2040-06-01 09:00:00')->toDateString(), CarbonImmutable::parse($review['protected_until'])->toDateString());
    }

    public function test_a_recent_visit_is_still_protected(): void
    {
        $this->schedule(25);
        $patient = $this->patientWhoLastVisited(CarbonImmutable::now()->subYears(2)->toDateTimeString());

        $review = $this->review($patient);

        $this->assertContains('retention_not_expired', $review['blockers']);
        $this->assertFalse($review['eligible_for_review']);
    }

    public function test_the_boundary_is_the_twenty_fifth_anniversary_of_the_visit(): void
    {
        $this->schedule(25);
        $expired = $this->patientWhoLastVisited(CarbonImmutable::now()->subYears(25)->subDay()->toDateTimeString());
        $notYet = $this->patientWhoLastVisited(CarbonImmutable::now()->subYears(25)->addDay()->toDateTimeString());

        $this->assertTrue($this->review($expired)['eligible_for_review']);
        $this->assertContains('retention_not_expired', $this->review($notYet)['blockers']);
    }

    public function test_a_stricter_schedule_version_applies_to_existing_records(): void
    {
        $this->schedule(25);
        $patient = $this->patientWhoLastVisited(CarbonImmutable::now()->subYears(30)->toDateTimeString());
        $this->assertTrue($this->review($patient)['eligible_for_review']);

        $this->schedule(40);

        $review = $this->review($patient);
        $this->assertSame(2, $review['schedule_version']);
        $this->assertContains('retention_not_expired', $review['blockers']);
    }

    public function test_without_a_schedule_nothing_is_reviewable(): void
    {
        $patient = $this->patientWhoLastVisited('2000-01-01 09:00:00');

        $review = $this->review($patient);

        $this->assertFalse($review['eligible_for_review']);
        $this->assertContains('no_retention_schedule', $review['blockers']);
    }

    public function test_ongoing_attendance_and_incomplete_evidence_block_with_their_reasons(): void
    {
        $this->schedule(25);
        $patient = Patient::factory()->create();
        app(AttendanceEvidenceContract::class)->recordAttendanceStarted([
            'provider' => 'laboratory', 'source_reference' => 'lab-open', 'patient_id' => $patient->id,
            'kind' => 'diagnostic', 'started_at' => '2000-01-01 08:00:00',
        ]);

        $review = $this->review($patient);

        $this->assertFalse($review['eligible_for_review']);
        $this->assertContains('ongoing_attendance', $review['blockers']);
        $this->assertContains('no_attendance_evidence', $review['blockers']);
        $this->assertContains('provider_coverage_missing:laboratory', $review['blockers']);
    }

    public function test_an_unknown_last_visit_is_never_taken_from_an_old_result_date(): void
    {
        $this->schedule(25);
        $patient = Patient::factory()->create();
        $this->vouchCoverage();

        $review = $this->review($patient);

        $this->assertNull($review['last_visit_ended_at']);
        $this->assertFalse($review['eligible_for_review']);
        $this->assertContains('no_attendance_evidence', $review['blockers']);
    }

    public function test_without_a_last_visit_provider_the_review_says_so_rather_than_passing(): void
    {
        $this->schedule(25);
        $patient = Patient::factory()->create();
        $this->app->instance(PatientLastVisitPort::class, new class implements PatientLastVisitPort
        {
            public function lastVisit(string $patientId, string $asOf): ?array
            {
                return null;
            }
        });

        $review = $this->review($patient);

        $this->assertFalse($review['eligible_for_review']);
        $this->assertContains('last_visit_unavailable', $review['blockers']);
    }

    /** @dataProvider terseProviderAnswers */
    #[\PHPUnit\Framework\Attributes\DataProvider('terseProviderAnswers')]
    public function test_a_terse_provider_answer_never_turns_uncertainty_into_eligibility(array $answer, string $blocker): void
    {
        $this->schedule(25);
        $patient = Patient::factory()->create();
        $this->app->instance(PatientLastVisitPort::class, new class($answer) implements PatientLastVisitPort
        {
            public function __construct(private readonly array $answer) {}

            public function lastVisit(string $patientId, string $asOf): ?array
            {
                return ['patient_id' => $patientId, 'history_version' => 1] + $this->answer;
            }
        });

        $review = $this->review($patient);

        $this->assertFalse($review['eligible_for_review']);
        $this->assertContains($blocker, $review['blockers']);
    }

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function terseProviderAnswers(): array
    {
        $old = '2000-01-01T09:00:00+00:00';

        return [
            'ongoing but no listed blocker' => [['last_visit_ended_at' => $old, 'ongoing' => true, 'coverage' => ['complete' => true, 'through' => $old, 'blockers' => []]], 'ongoing_attendance'],
            'incomplete but no listed blocker' => [['last_visit_ended_at' => $old, 'ongoing' => false, 'coverage' => ['complete' => false, 'through' => $old, 'blockers' => []]], 'attendance_evidence_incomplete'],
            'unknown last visit' => [['last_visit_ended_at' => null, 'ongoing' => false, 'coverage' => ['complete' => true, 'through' => $old, 'blockers' => []]], 'no_attendance_evidence'],
        ];
    }

    // Continuing usefulness

    public function test_continuing_usefulness_is_an_explicit_attributable_extension(): void
    {
        $this->schedule(25);
        $patient = $this->patientWhoLastVisited('2000-01-01 09:00:00');
        $until = CarbonImmutable::now()->addYears(5)->toDateString();

        $this->actingAs($this->officer)->post(route('clinicaldocumentation.retention.extensions.store'), [
            'patient_id' => $patient->id, 'retain_until' => $until, 'reason' => 'Ongoing research follow-up',
        ])->assertRedirect();

        $review = $this->review($patient);
        $this->assertContains('continuing_usefulness_extension', $review['blockers']);
        $this->assertSame((string) $this->officer->id, (string) DB::table('cd_retention_extensions')->value('decided_by'));
    }

    public function test_an_extension_needs_a_reason_and_a_future_date(): void
    {
        $patient = Patient::factory()->create();

        $this->actingAs($this->officer)->post(route('clinicaldocumentation.retention.extensions.store'), [
            'patient_id' => $patient->id, 'retain_until' => '2001-01-01', 'reason' => '',
        ])->assertSessionHasErrors(['retain_until', 'reason']);
    }

    // Holds

    public function test_a_hold_blocks_review_until_it_is_lifted_and_keeps_its_history(): void
    {
        $this->schedule(25);
        $patient = $this->patientWhoLastVisited('2000-01-01 09:00:00');

        $this->placeHold($patient, 'Complaint under investigation')->assertRedirect();
        $hold = DB::table('cd_legal_holds')->sole();
        $this->assertContains('active_legal_hold', $this->review($patient)['blockers']);

        $this->actingAs($this->officer)->post(route('clinicaldocumentation.retention.holds.lift', $hold->id), ['reason' => 'Closed'])->assertRedirect();

        $this->assertTrue($this->review($patient)['eligible_for_review']);
        $this->assertSame(['placed', 'lifted'], DB::table('cd_legal_hold_events')->where('hold_id', $hold->id)->orderBy('occurred_at')->orderBy('id')->pluck('action')->all());
    }

    public function test_time_never_ends_a_hold(): void
    {
        $this->schedule(25);
        $patient = $this->patientWhoLastVisited('2000-01-01 09:00:00');
        $this->placeHold($patient, 'Litigation hold');

        $this->travel(30)->years();

        $this->assertContains('active_legal_hold', $this->review($patient)['blockers']);
    }

    public function test_overlapping_holds_are_independent(): void
    {
        $this->schedule(25);
        $patient = $this->patientWhoLastVisited('2000-01-01 09:00:00');
        $this->placeHold($patient, 'Audit');
        $this->placeHold($patient, 'Litigation');
        $first = DB::table('cd_legal_holds')->where('reason', 'Audit')->value('id');

        $this->actingAs($this->officer)->post(route('clinicaldocumentation.retention.holds.lift', $first), ['reason' => 'Audit closed']);

        $review = $this->review($patient);
        $this->assertContains('active_legal_hold', $review['blockers']);
        $this->assertCount(1, $review['holds']);
        $this->assertFalse($review['eligible_for_review']);
    }

    public function test_a_lifted_hold_cannot_be_lifted_again_or_edited(): void
    {
        $patient = Patient::factory()->create();
        $this->placeHold($patient, 'Audit');
        $hold = DB::table('cd_legal_holds')->sole();
        $this->actingAs($this->officer)->post(route('clinicaldocumentation.retention.holds.lift', $hold->id), ['reason' => 'Done']);

        $this->actingAs($this->officer)->post(route('clinicaldocumentation.retention.holds.lift', $hold->id), ['reason' => 'Again'])
            ->assertSessionHasErrors('hold');
        $this->assertSame(2, DB::table('cd_legal_hold_events')->count());
    }

    public function test_a_hold_needs_a_reason_and_a_scope(): void
    {
        $patient = Patient::factory()->create();

        $this->actingAs($this->officer)->post(route('clinicaldocumentation.retention.holds.store'), [
            'patient_id' => $patient->id, 'scope' => 'record_set', 'reason' => '',
        ])->assertSessionHasErrors(['reason', 'record_refs']);
    }

    public function test_a_hold_over_a_record_set_names_exactly_those_records(): void
    {
        $patient = Patient::factory()->create();

        $this->actingAs($this->officer)->post(route('clinicaldocumentation.retention.holds.store'), [
            'patient_id' => $patient->id, 'scope' => 'record_set', 'reason' => 'Subpoena',
            'record_refs' => [['type' => 'laboratory.result', 'id' => 'result-1']],
        ])->assertRedirect();

        $hold = DB::table('cd_legal_holds')->sole();
        $this->assertSame('record_set', $hold->scope);
        $this->assertSame([['type' => 'laboratory.result', 'id' => 'result-1']], json_decode($hold->record_refs, true));
    }

    public function test_reconciliation_keeps_every_outstanding_hold_and_extension_on_the_survivor(): void
    {
        $canonical = Patient::factory()->create();
        $superseded = Patient::factory()->create();
        $this->placeHold($superseded, 'Audit');
        $this->actingAs($this->officer)->post(route('clinicaldocumentation.retention.extensions.store'), [
            'patient_id' => $superseded->id, 'retain_until' => CarbonImmutable::now()->addYears(3)->toDateString(), 'reason' => 'Research',
        ]);

        app(ReassignReconciledPatient::class)->handle([
            'canonical_patient_id' => $canonical->id, 'superseded_patient_id' => $superseded->id,
            'canonical_nocm' => $canonical->nocm, 'superseded_nocm' => $superseded->nocm,
            'reconciled_by' => (string) $this->officer->id, 'reason' => 'Same person', 'reconciled_at' => now()->toIso8601String(),
        ]);

        $this->assertSame($canonical->id, DB::table('cd_legal_holds')->value('patient_id'));
        $this->assertSame($canonical->id, DB::table('cd_retention_extensions')->value('patient_id'));
        $this->assertNull(DB::table('cd_legal_holds')->value('lifted_at'));
    }

    // Authorization at the HTTP boundary

    public function test_every_retention_action_is_forbidden_without_its_ability(): void
    {
        $stranger = User::factory()->create();
        $patient = Patient::factory()->create();
        $this->placeHold($patient, 'Audit');
        $hold = DB::table('cd_legal_holds')->sole();

        $this->actingAs($stranger)->get(route('clinicaldocumentation.retention.index'))->assertForbidden();
        $this->actingAs($stranger)->get(route('clinicaldocumentation.retention.review', $patient->id))->assertForbidden();
        $this->actingAs($stranger)->post(route('clinicaldocumentation.retention.schedules.store'), ['minimum_years' => 30, 'rationale' => 'x'])->assertForbidden();
        $this->actingAs($stranger)->post(route('clinicaldocumentation.retention.holds.store'), ['patient_id' => $patient->id, 'scope' => 'patient', 'reason' => 'x'])->assertForbidden();
        $this->actingAs($stranger)->post(route('clinicaldocumentation.retention.holds.lift', $hold->id), ['reason' => 'x'])->assertForbidden();
        $this->actingAs($stranger)->post(route('clinicaldocumentation.retention.extensions.store'), ['patient_id' => $patient->id, 'retain_until' => '2099-01-01', 'reason' => 'x'])->assertForbidden();
        $this->assertSame(0, RetentionSchedule::count());
        $this->assertNull(DB::table('cd_legal_holds')->value('lifted_at'));
    }

    public function test_the_ordinary_record_abilities_confer_no_retention_authority(): void
    {
        $clinician = User::factory()->create();
        foreach (['records.read', 'documents.sign', 'documents.amend', 'archive.manage'] as $ability) {
            $clinician->givePermissionTo('clinicaldocumentation.'.$ability);
        }
        $patient = Patient::factory()->create();

        $this->actingAs($clinician)->get(route('clinicaldocumentation.retention.review', $patient->id))->assertForbidden();
        $this->actingAs($clinician)->post(route('clinicaldocumentation.retention.holds.store'), ['patient_id' => $patient->id, 'scope' => 'patient', 'reason' => 'x'])->assertForbidden();
    }

    public function test_the_overview_and_review_render_for_a_records_officer(): void
    {
        $this->schedule(25);
        $patient = $this->patientWhoLastVisited('2000-01-01 09:00:00');

        $this->actingAs($this->officer)->get(route('clinicaldocumentation.retention.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('ClinicalDocumentation::Retention/Index')
                ->where('destruction_enabled', false)
                ->has('schedules', 1));

        $this->actingAs($this->officer)->get(route('clinicaldocumentation.retention.review', $patient->id))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('ClinicalDocumentation::Retention/Review')
                ->where('review.eligible_for_review', true));
    }

    // Helpers

    private function schedule(int $years): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->officer)->post(route('clinicaldocumentation.retention.schedules.store'), [
            'minimum_years' => $years, 'rationale' => 'Facility retention policy',
        ]);
    }

    private function placeHold(Patient $patient, string $reason): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->officer)->post(route('clinicaldocumentation.retention.holds.store'), [
            'patient_id' => $patient->id, 'scope' => 'patient', 'reason' => $reason,
        ]);
    }

    /** @return array<string, mixed> */
    private function review(Patient $patient): array
    {
        $response = $this->actingAs($this->officer)->get(route('clinicaldocumentation.retention.review', $patient->id))->assertOk();

        return $response->viewData('page')['props']['review'];
    }

    private function patientWhoLastVisited(string $endedAt): Patient
    {
        $patient = Patient::factory()->create();
        $this->attend($patient, 'lab-'.$patient->id, $endedAt);
        $this->vouchCoverage();

        return $patient;
    }

    private function attend(Patient $patient, string $reference, string $endedAt): void
    {
        app(AttendanceEvidenceContract::class)->recordAttendanceEnded([
            'provider' => 'laboratory', 'source_reference' => $reference, 'patient_id' => $patient->id,
            'kind' => 'diagnostic', 'ended_at' => $endedAt,
        ]);
    }

    /** The provider vouches for everything up to a moment ago, which is as close to "now" as any provider can honestly get. */
    private function vouchCoverage(): void
    {
        app(AttendanceEvidenceContract::class)->reportCoverage([
            'provider' => 'laboratory', 'covered_through' => CarbonImmutable::now()->subMinute()->toIso8601String(),
        ]);
    }
}
