<?php

declare(strict_types=1);

namespace Modules\ClinicalDocumentation\Tests\Feature;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\ClinicalDocumentation\Contracts\DestructionAuthorizationContract;
use Modules\ClinicalDocumentation\Models\DiagnosticResultEvidence;
use Modules\ClinicalDocumentation\Services\ClinicalRecordRetention;
use Modules\HospitalCore\Contracts\AttendanceEvidenceContract;
use Modules\Laboratory\Contracts\RecordCustodyContract;
use Modules\Laboratory\Models\LabResult;
use Modules\Laboratory\Services\ResultService;
use Modules\Laboratory\Tests\Support\Walk;
use Tests\Support\ModuleUnderTest;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Destroying a patient's electronic findings once retention allows it: one authorized action, custodian steps with
 * receipts, a processing history without the findings. Driven at the authenticated HTTP boundary against the real
 * Laboratory custodian. The mandatory duty is only to keep records at least 25 years; this is the optional step after.
 */
class ClinicalRecordDestructionTest extends TestCase
{
    use RefreshDatabase;

    private User $officer;

    private string $patientId;

    /** @var array<string, mixed> */
    private array $walk;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->make(PermissionRegistrar::class)->forgetCachedPermissions();
        config(['hospitalcore.attendance.reporting_modules' => [], 'hospitalcore.attendance.expected_providers' => ['laboratory'], 'clinicaldocumentation.retention.destruction_enabled' => true]);
        $this->officer = $this->holder(['view', 'destroy']);
        app(ClinicalRecordRetention::class)->recordSchedule(25, 'Policy', (string) $this->officer->id);
        // Destruction is exercised against a real Laboratory custodian, so a composition without one has nothing to run.
        if (! ModuleUnderTest::isEnabled('Laboratory') || ! class_exists(Walk::class)) {
            $this->markTestSkipped('Laboratory is not composed here.');
        }
        $this->walk = Walk::released('4.2');
        $this->patientId = $this->walk['registration']['patient_id'];
        $this->attend('lab-old', '2000-01-01 09:00:00');
    }

    public function test_one_action_destroys_every_custodians_findings_and_leaves_a_history_without_them(): void
    {
        $this->destroy()->assertRedirect();

        $this->assertNull(LabResult::findOrFail($this->walk['result']->id)->values);
        $this->assertNull(DiagnosticResultEvidence::where('patient_id', $this->patientId)->firstOrFail()->summary);
        $this->assertSame('complete', $this->recordStatus());
        $record = DB::table('cd_destructions')->first();
        $this->assertSame((string) $this->officer->id, (string) $record->destroyed_by);
        $this->assertSame('Retention period ended', $record->reason);
        $history = (string) json_encode([$record, DB::table('cd_destruction_items')->get()]);
        $this->assertStringContainsString($this->walk['result']->id, $history);
        $this->assertStringNotContainsString('4.2', $history);
        $this->assertSame(0, DB::table('cd_destruction_items')->where('status', '!=', 'destroyed')->count());
        $this->assertSame(0, DB::table('cd_destruction_items')->whereNull('receipt_id')->count());
    }

    public function test_the_copies_it_covers_span_laboratory_audit_and_this_modules_own_projection(): void
    {
        $this->destroy();

        $kinds = DB::table('cd_destruction_items')->get()->map(fn ($i): string => $i->custodian.':'.$i->ref_type)->unique()->values()->all();
        $this->assertContains('laboratory:result_version', $kinds);
        $this->assertContains('laboratory:audit_findings', $kinds);
        $this->assertContains('clinicaldocumentation:diagnostic_result_evidence', $kinds);
    }

    public function test_nothing_is_destroyed_unless_the_deployment_switched_destruction_on(): void
    {
        config(['clinicaldocumentation.retention.destruction_enabled' => false]);

        // The flag gates in the FormRequest, so the action is refused outright rather than answered with a domain error.
        $this->destroy()->assertForbidden();

        $this->assertNotNull(LabResult::findOrFail($this->walk['result']->id)->values);
        $this->assertSame(0, DB::table('cd_destructions')->count());
    }

    public function test_the_person_must_acknowledge_that_no_retained_record_needs_the_content(): void
    {
        $this->destroy(['dependencies_acknowledged' => false])->assertSessionHasErrors('dependencies_acknowledged');

        $this->assertNotNull(LabResult::findOrFail($this->walk['result']->id)->values);
    }

    public function test_a_patient_who_may_not_be_reviewed_is_refused_with_nothing_destroyed(): void
    {
        app(ClinicalRecordRetention::class)->placeHold($this->patientId, 'patient', null, 'Litigation', (string) $this->officer->id);

        $this->destroy()->assertSessionHasErrors('patient_id');

        $this->assertNotNull(LabResult::findOrFail($this->walk['result']->id)->values);
        $this->assertSame(0, DB::table('cd_destructions')->count());
    }

    public function test_a_recent_visit_keeps_the_findings(): void
    {
        $this->attend('lab-new', CarbonImmutable::now()->subYear()->toDateTimeString());

        $this->destroy()->assertSessionHasErrors('patient_id');

        $this->assertNotNull(LabResult::findOrFail($this->walk['result']->id)->values);
    }

    public function test_evidence_cited_by_a_retained_assertion_stays_protected(): void
    {
        $this->citeFromAssertion(DiagnosticResultEvidence::where('patient_id', $this->patientId)->firstOrFail()->id);

        $this->destroy()->assertSessionHasErrors('patient_id');

        $this->assertNotNull(DiagnosticResultEvidence::where('patient_id', $this->patientId)->firstOrFail()->summary);
    }

    public function test_a_patient_with_an_archived_copy_whose_custody_is_unproven_is_blocked(): void
    {
        DB::table('cd_archive_packages')->insert([
            'id' => (string) \Illuminate\Support\Str::uuid(), 'document_id' => (string) \Illuminate\Support\Str::uuid(), 'patient_id' => $this->patientId,
            'custody_state' => 'requested', 'integrity_hash' => 'x', 'requested_at' => now(),
        ]);

        $this->destroy()->assertSessionHasErrors('patient_id');
    }

    // A change while it runs

    public function test_a_hold_placed_between_steps_stops_the_rest_and_resuming_waits_until_it_is_lifted(): void
    {
        $this->flakyCustodian(afterCall: 1, then: fn () => app(ClinicalRecordRetention::class)->placeHold($this->patientId, 'patient', null, 'Complaint', (string) $this->officer->id));

        $this->destroy()->assertSessionHasErrors('destruction');

        $this->assertSame('partial', $this->recordStatus());
        $before = DB::table('cd_destruction_items')->where('status', 'destroyed')->count();
        $this->artisan('clinicaldocumentation:resume-destruction', ['destruction' => $this->recordId()])->assertFailed();
        $this->assertSame($before, DB::table('cd_destruction_items')->where('status', 'destroyed')->count());

        $holdId = (string) DB::table('cd_legal_holds')->value('id');
        app(ClinicalRecordRetention::class)->liftHold($holdId, 'Closed', (string) $this->officer->id);
        $this->artisan('clinicaldocumentation:resume-destruction', ['destruction' => $this->recordId()])->assertSuccessful();

        $this->assertSame('complete', $this->recordStatus());
    }

    public function test_a_copy_discovered_while_it_runs_stops_the_rest(): void
    {
        $this->flakyCustodian(afterCall: 1, then: fn () => app(ResultService::class)->amend($this->walk['line']->id, [['code' => 'K', 'value' => '3.0']], 'Late fix', (string) $this->walk['lab']['admin']->id));

        $this->destroy()->assertSessionHasErrors('destruction');

        $this->assertNotSame('complete', $this->recordStatus());
        $this->assertGreaterThan(0, DB::table('cd_destruction_items')->where('status', '!=', 'destroyed')->count());
    }

    public function test_doing_the_action_again_takes_in_a_copy_that_stopped_the_first_run(): void
    {
        $this->flakyCustodian(afterCall: 1, then: fn () => app(ResultService::class)->amend($this->walk['line']->id, [['code' => 'K', 'value' => '3.0']], 'Late fix', (string) $this->walk['lab']['admin']->id));
        $this->destroy()->assertSessionHasErrors('destruction');
        $amended = LabResult::where('version', 2)->firstOrFail();
        $this->assertNotNull($amended->values);

        $this->destroy()->assertRedirect();

        $this->assertNull(LabResult::findOrFail($amended->id)->values);
        $this->assertNull(LabResult::findOrFail($this->walk['result']->id)->values);
        $this->assertSame(1, DB::table('cd_destructions')->count());
        $this->assertSame('complete', $this->recordStatus());
    }

    // Partial execution and retry

    public function test_a_custodian_failure_leaves_a_truthful_partial_and_a_retry_finishes_without_repeating_effects(): void
    {
        $this->flakyCustodian(failOnCall: 2);

        $this->destroy();

        $this->assertSame('partial', $this->recordStatus());
        $this->assertGreaterThan(0, DB::table('cd_destruction_items')->where('status', 'destroyed')->count());
        $this->assertGreaterThan(0, DB::table('cd_destruction_items')->where('status', 'failed')->count());

        $this->artisan('clinicaldocumentation:resume-destruction', ['destruction' => $this->recordId()])->assertSuccessful();

        $this->assertSame('complete', $this->recordStatus());
        $this->assertSame(1, DB::table('lab_destruction_receipts')->where('ref_type', 'result_version')->count());
    }

    public function test_a_lost_reply_is_retried_to_the_same_receipt_and_never_destroys_twice(): void
    {
        $this->flakyCustodian(loseReplyOnCall: 1);

        $this->destroy();
        $this->artisan('clinicaldocumentation:resume-destruction', ['destruction' => $this->recordId()])->assertSuccessful();

        $this->assertSame('complete', $this->recordStatus());
        $laboratoryItems = DB::table('cd_destruction_items')->where('custodian', 'laboratory')->count();
        $this->assertSame($laboratoryItems, DB::table('lab_destruction_receipts')->count());
    }

    public function test_doing_the_action_again_finishes_the_open_destruction_instead_of_starting_another(): void
    {
        $this->flakyCustodian(failOnCall: 2);
        $this->destroy();
        $this->assertSame('partial', $this->recordStatus());

        $this->destroy()->assertRedirect();

        $this->assertSame(1, DB::table('cd_destructions')->count());
        $this->assertSame('complete', $this->recordStatus());
        $this->assertSame(1, DB::table('lab_destruction_receipts')->where('ref_type', 'result_version')->count());
    }

    public function test_copies_already_destroyed_by_an_earlier_destruction_are_not_treated_as_discovered(): void
    {
        $this->destroy();
        $service = app(\Modules\ClinicalDocumentation\Services\ClinicalRecordDestruction::class);
        $later = \Modules\ClinicalDocumentation\Models\Destruction::create([
            'patient_id' => $this->patientId, 'reason' => 'Later', 'dependencies_acknowledged' => true,
            'destroyed_by' => $this->officer->id, 'destroyed_by_name' => 'x', 'started_at' => now(),
        ]);

        $this->assertNull($service->problem($later));
    }

    public function test_a_worker_can_only_finish_a_destruction_a_person_started(): void
    {
        $this->artisan('clinicaldocumentation:resume-destruction', ['destruction' => (string) \Illuminate\Support\Str::uuid()])->assertFailed();

        $this->assertSame(0, DB::table('cd_destructions')->count());
        $this->assertNotNull(LabResult::findOrFail($this->walk['result']->id)->values);
    }

    public function test_a_worker_does_nothing_while_destruction_is_switched_off(): void
    {
        $this->flakyCustodian(failOnCall: 2);
        $this->destroy();
        config(['clinicaldocumentation.retention.destruction_enabled' => false]);
        $before = DB::table('cd_destruction_items')->where('status', 'destroyed')->count();

        $this->artisan('clinicaldocumentation:resume-destruction', ['destruction' => $this->recordId()])->assertFailed();

        $this->assertSame($before, DB::table('cd_destruction_items')->where('status', 'destroyed')->count());
    }

    // The authorization capability

    public function test_the_authorization_capability_permits_only_a_started_destructions_own_steps_while_nothing_changed(): void
    {
        $this->flakyCustodian(failOnCall: 1);
        $this->destroy();
        $record = $this->recordId();
        $item = DB::table('cd_destruction_items')->where('custodian', 'laboratory')->where('ref_type', 'result_version')->first();
        $port = app(DestructionAuthorizationContract::class);

        $this->assertTrue($port->permits($record, $this->patientId, 'laboratory', 'result_version', $item->ref_id)['permitted']);
        $this->assertFalse($port->permits($record, $this->patientId, 'laboratory', 'result_version', '00000000-0000-0000-0000-000000000000')['permitted']);
        $this->assertFalse($port->permits('no-such-record', $this->patientId, 'laboratory', 'result_version', $item->ref_id)['permitted']);
        app(ClinicalRecordRetention::class)->placeHold($this->patientId, 'patient', null, 'Complaint', (string) $this->officer->id);
        $this->assertFalse($port->permits($record, $this->patientId, 'laboratory', 'result_version', $item->ref_id)['permitted']);
    }

    // Authorization at the HTTP boundary

    public function test_every_destruction_action_is_forbidden_without_the_destroy_ability(): void
    {
        $stranger = User::factory()->create();
        $clinician = User::factory()->create();
        foreach (['records.read', 'documents.sign', 'documents.amend', 'archive.manage', 'retention.view', 'retention.hold', 'retention.schedule'] as $ability) {
            $clinician->givePermissionTo('clinicaldocumentation.'.$ability);
        }
        $this->flakyCustodian(failOnCall: 1);
        $this->destroy();
        $record = $this->recordId();

        foreach ([$stranger, $clinician] as $user) {
            $this->actingAs($user)->post(route('clinicaldocumentation.retention.destroy', $this->patientId), ['reason' => 'x', 'dependencies_acknowledged' => true])->assertForbidden();
            $this->actingAs($user)->post(route('clinicaldocumentation.retention.destruction.resume', $record))->assertForbidden();
        }
        $this->actingAs($stranger)->get(route('clinicaldocumentation.retention.destruction.index'))->assertForbidden();
    }

    public function test_the_history_page_shows_status_and_the_switch(): void
    {
        $this->destroy();

        $this->actingAs($this->officer)->get(route('clinicaldocumentation.retention.destruction.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('ClinicalDocumentation::Retention/Destructions')
                ->where('destructions.0.status', 'complete')
                ->where('destruction_enabled', true));
    }

    // Helpers

    /** @param list<string> $abilities */
    private function holder(array $abilities): User
    {
        $user = User::factory()->create();
        foreach ($abilities as $ability) {
            $user->givePermissionTo('clinicaldocumentation.retention.'.$ability);
        }

        return $user;
    }

    /** @param array<string, mixed> $override */
    private function destroy(array $override = []): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->officer)->post(route('clinicaldocumentation.retention.destroy', $this->patientId), $override + ['reason' => 'Retention period ended', 'dependencies_acknowledged' => true]);
    }

    private function recordId(): string
    {
        return (string) DB::table('cd_destructions')->orderBy('started_at')->value('id');
    }

    private function recordStatus(): string
    {
        $response = $this->actingAs($this->officer)->get(route('clinicaldocumentation.retention.destruction.index'))->assertOk();

        return $response->viewData('page')['props']['destructions'][0]['status'];
    }

    private function attend(string $reference, string $endedAt): void
    {
        $evidence = app(AttendanceEvidenceContract::class);
        $evidence->recordAttendanceEnded(['provider' => 'laboratory', 'source_reference' => $reference, 'patient_id' => $this->patientId, 'kind' => 'diagnostic', 'ended_at' => $endedAt]);
        $evidence->reportCoverage(['provider' => 'laboratory', 'covered_through' => CarbonImmutable::now()->subMinute()->toIso8601String()]);
    }

    private function citeFromAssertion(string $evidenceId): void
    {
        DB::table('cd_diagnosis_assertions')->insert([
            'id' => $id = (string) \Illuminate\Support\Str::uuid(), 'lineage_id' => $id, 'document_id' => (string) \Illuminate\Support\Str::uuid(),
            'patient_id' => $this->patientId, 'coding_system' => 'ICD-10', 'code' => 'E87.5', 'display' => 'Hyperkalaemia', 'assertion_type' => 'initial',
            'revision' => 1, 'evidence_refs' => json_encode([$evidenceId]), 'asserted_by' => (string) $this->officer->id, 'asserted_by_name' => 'x', 'asserted_at' => now(),
        ]);
    }

    /** A Laboratory custodian that fails, loses its reply, or has a side effect after the Nth destroy call. */
    public function test_content_written_to_a_copy_after_this_destruction_emptied_it_stops_the_rest_as_a_discovered_copy(): void
    {
        $real = app(\Modules\Diagnostics\Contracts\RecordCustodyContract::class);
        $this->app->instance(\Modules\Diagnostics\Contracts\RecordCustodyContract::class, new class($real) implements \Modules\Diagnostics\Contracts\RecordCustodyContract
        {
            private ?string $rewritten = null;

            private int $calls = 0;

            public function __construct(private readonly \Modules\Diagnostics\Contracts\RecordCustodyContract $real) {}

            public function inventory(string $patientId): array
            {
                $inventory = $this->real->inventory($patientId);
                // The copy the first step emptied is written to again: it holds content, so it is live until a step removes it again.
                if ($this->rewritten !== null) {
                    $inventory['items'] = array_map(fn (array $item): array => $item['custodian'].'|'.$item['ref_id'] === $this->rewritten ? ['state' => 'live'] + $item : $item, $inventory['items']);
                }

                return $inventory;
            }

            public function destroy(array $step): array
            {
                $receipt = $this->real->destroy($step);
                $key = $step['custodian'].'|'.$step['ref_id'];
                if (++$this->calls === 1) {
                    $this->rewritten = $key;
                } elseif ($key === $this->rewritten) {
                    $this->rewritten = null;
                }

                return $receipt;
            }
        });

        // The first run stops at the copy that was written to after it was emptied...
        $this->destroy()->assertSessionHasErrors('destruction');
        $this->assertSame('partial', $this->recordStatus());

        // ...and doing the action again takes it in and runs its step again, instead of refusing for ever or completing over live content.
        $this->destroy()->assertRedirect();
        $this->assertSame('complete', $this->recordStatus());
        $this->assertSame(0, DB::table('cd_destruction_items')->where('status', '!=', 'destroyed')->count());
        $this->assertSame(0, DB::table('cd_destruction_items')->whereNull('receipt_id')->count(), 'every copy keeps its receipt, the one that was taken in again too');
    }

    public function test_a_custodian_that_is_not_composed_while_this_module_holds_a_specialtys_evidence_blocks_the_destruction(): void
    {
        // The diagnostic custodian is gone (its module disabled), but the findings it projected are still held here.
        unset($this->app[\Modules\Diagnostics\Contracts\RecordCustodyContract::class]);
        $this->assertFalse(app(\Modules\ClinicalDocumentation\Services\DestructionCustody::class)->available());

        $this->destroy()->assertSessionHasErrors('patient_id');

        $this->assertNotNull(LabResult::findOrFail($this->walk['result']->id)->values);
        $this->assertNotNull(DiagnosticResultEvidence::where('patient_id', $this->patientId)->firstOrFail()->summary);
        $this->assertSame(0, DB::table('cd_destructions')->count());
    }

    public function test_a_copy_that_appears_after_the_last_step_keeps_the_destruction_open_instead_of_completing_over_it(): void
    {
        $real = app(\Modules\Diagnostics\Contracts\RecordCustodyContract::class);
        $this->app->instance(\Modules\Diagnostics\Contracts\RecordCustodyContract::class, new class($real) implements \Modules\Diagnostics\Contracts\RecordCustodyContract
        {
            private int $total = 0;

            private int $calls = 0;

            public function __construct(private readonly \Modules\Diagnostics\Contracts\RecordCustodyContract $real) {}

            public function inventory(string $patientId): array
            {
                $inventory = $this->real->inventory($patientId);
                // How many steps this custodian will be asked, read from what it holds live before anything ran.
                if ($this->calls === 0 && $this->total === 0) {
                    $this->total = count(array_filter($inventory['items'], fn (array $item): bool => $item['state'] === 'live'));
                }
                // After the last of them, a copy nobody saw appears.
                if ($this->calls >= $this->total && $this->total > 0) {
                    $inventory['items'][] = ['custodian' => 'laboratory', 'ref_type' => 'draft_findings', 'ref_id' => 'appeared-after-the-last-step', 'state' => 'live'];
                }

                return $inventory;
            }

            public function destroy(array $step): array
            {
                $this->calls++;

                return $this->real->destroy($step);
            }
        });

        $this->destroy();

        $this->assertNull(DB::table('cd_destructions')->first()->completed_at, 'it is not completed over a copy it never named');
        $this->assertSame('partial', $this->recordStatus());
    }

    public function test_a_custodian_that_fails_when_asked_is_an_explicit_blocker_not_an_error(): void
    {
        $this->app->instance(\Modules\Diagnostics\Contracts\RecordCustodyContract::class, new class implements \Modules\Diagnostics\Contracts\RecordCustodyContract
        {
            public function inventory(string $patientId): array
            {
                throw new \RuntimeException('custody is down');
            }

            public function destroy(array $step): array
            {
                throw new \RuntimeException('custody is down');
            }
        });

        $this->destroy()->assertSessionHasErrors('patient_id');

        $this->assertNotNull(LabResult::findOrFail($this->walk['result']->id)->values);
        $this->assertSame(0, DB::table('cd_destructions')->count());
    }

    public function test_custody_that_stops_being_complete_after_the_last_step_keeps_the_destruction_open(): void
    {
        $real = app(\Modules\Diagnostics\Contracts\RecordCustodyContract::class);
        $this->app->instance(\Modules\Diagnostics\Contracts\RecordCustodyContract::class, new class($real) implements \Modules\Diagnostics\Contracts\RecordCustodyContract
        {
            private int $total = 0;

            private int $calls = 0;

            public function __construct(private readonly \Modules\Diagnostics\Contracts\RecordCustodyContract $real) {}

            public function inventory(string $patientId): array
            {
                $inventory = $this->real->inventory($patientId);
                if ($this->calls === 0 && $this->total === 0) {
                    $this->total = count(array_filter($inventory['items'], fn (array $item): bool => $item['state'] === 'live'));
                }
                // After the last step a specialty can no longer be asked: the custodian cannot say all it holds.
                if ($this->calls >= $this->total && $this->total > 0) {
                    $inventory['complete'] = false;
                    $inventory['blockers'] = [['custodian' => 'laboratory', 'reason' => 'custody_unreachable']];
                }

                return $inventory;
            }

            public function destroy(array $step): array
            {
                $this->calls++;

                return $this->real->destroy($step);
            }
        });

        $this->destroy();

        $this->assertNull(DB::table('cd_destructions')->first()->completed_at, 'it is not completed while custody cannot say everything');
        $this->assertSame('partial', $this->recordStatus());
    }

    public function test_evidence_whose_copies_were_all_destroyed_still_shows_a_specialty_history_a_missing_custodian_cannot_answer_for(): void
    {
        // Every copy held here was emptied by an earlier destruction, and the custodian that held the rest has since gone.
        DiagnosticResultEvidence::query()->where('patient_id', $this->patientId)->update(['destroyed_at' => now(), 'summary' => null]);
        unset($this->app[\Modules\Diagnostics\Contracts\RecordCustodyContract::class]);

        $blockers = (new \ReflectionMethod(\Modules\ClinicalDocumentation\Services\ClinicalRecordDestruction::class, 'dependencyBlockers'))
            ->invoke(app(\Modules\ClinicalDocumentation\Services\ClinicalRecordDestruction::class), $this->patientId, [], true, []);

        $this->assertContains('custody_unavailable', $blockers);
    }

    public function test_a_custodian_that_cannot_say_everything_it_holds_stops_the_destruction_instead_of_reading_as_nothing_more(): void
    {
        $real = app(\Modules\Diagnostics\Contracts\RecordCustodyContract::class);
        $this->app->instance(\Modules\Diagnostics\Contracts\RecordCustodyContract::class, new class($real) implements \Modules\Diagnostics\Contracts\RecordCustodyContract
        {
            public function __construct(private readonly \Modules\Diagnostics\Contracts\RecordCustodyContract $real) {}

            public function inventory(string $patientId): array
            {
                return ['complete' => false, 'blockers' => [['custodian' => 'radiology', 'reason' => 'specialty_not_registered']]] + $this->real->inventory($patientId);
            }

            public function destroy(array $step): array
            {
                return $this->real->destroy($step);
            }
        });

        $this->destroy()->assertSessionHasErrors('patient_id');

        $this->assertNotNull(LabResult::findOrFail($this->walk['result']->id)->values);
        $this->assertSame(0, DB::table('cd_destructions')->count());
    }

    private function flakyCustodian(?int $failOnCall = null, ?int $loseReplyOnCall = null, ?int $afterCall = null, ?\Closure $then = null): object
    {
        $real = app(RecordCustodyContract::class);
        $flaky = new class($real, $failOnCall, $loseReplyOnCall, $afterCall, $then) implements RecordCustodyContract
        {
            public int $calls = 0;

            public function __construct(private readonly RecordCustodyContract $real, private readonly ?int $failOn, private readonly ?int $loseOn, private readonly ?int $afterOn, private readonly ?\Closure $then) {}

            public function inventory(string $patientId): array
            {
                return $this->real->inventory($patientId);
            }

            public function destroy(array $step): array
            {
                $this->calls++;
                if ($this->failOn === $this->calls) {
                    throw new \RuntimeException('Custodian unavailable');
                }
                $receipt = $this->real->destroy($step);
                if ($this->loseOn === $this->calls) {
                    throw new \RuntimeException('Reply lost');
                }
                if ($this->afterOn === $this->calls && $this->then !== null) {
                    ($this->then)();
                }

                return $receipt;
            }
        };
        $this->app->instance(RecordCustodyContract::class, $flaky);

        return $flaky;
    }
}
