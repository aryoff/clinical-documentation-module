<?php

declare(strict_types=1);

namespace Modules\ClinicalDocumentation\Tests\Contract;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\ClinicalDocumentation\Contracts\ActiveClinicalRecordContract;
use Modules\ClinicalDocumentation\Models\ClinicalHandoff;
use Modules\ClinicalDocumentation\Models\DiagnosisAssertion;
use Modules\ClinicalDocumentation\Models\DiagnosticResultEvidence;
use Modules\ClinicalDocumentation\Tests\Support\RegistersDiagnosticSpecialties;
use Tests\TestCase;

/**
 * The clinical record projects the finalized source version of any REGISTERED diagnostic specialty once, with structured amendment lineage,
 * and a treating clinician reads it. A projection is evidence: it is neither a diagnosis nor a handoff, and a pending candidate is never one.
 */
final class DiagnosticResultEvidenceContractTest extends TestCase
{
    use RefreshDatabase;
    use RegistersDiagnosticSpecialties;

    private ActiveClinicalRecordContract $records;

    private string $patient;

    protected function setUp(): void
    {
        parent::setUp();
        $this->patient = (string) Str::uuid();
        $this->registerSpecialties(['waveform']);
        $this->records = app(ActiveClinicalRecordContract::class);
    }

    /** @return array<string, mixed> */
    private function command(string $reference, array $overrides = []): array
    {
        return array_merge([
            'patient_id' => $this->patient, 'registration_id' => (string) Str::uuid(), 'source_owner' => 'waveform', 'result_reference_id' => $reference,
            'source_version' => 1, 'coding_system' => 'LOINC', 'code' => '11524-6', 'display' => 'ECG study', 'summary' => 'Sinus rhythm.',
            'observed_at' => now()->subMinute()->toIso8601String(),
        ], $overrides);
    }

    private function clinician(?string $patient = null): User
    {
        $clinician = User::factory()->create();
        $this->records->acceptHandoff([
            'registration_id' => (string) Str::uuid(), 'patient_id' => $patient ?? $this->patient, 'source_owner' => 'outpatient', 'source_reference_id' => (string) Str::uuid(),
            'recipient_id' => (string) $clinician->id, 'accepted_by' => (string) $clinician->id,
        ]);

        return $clinician;
    }

    public function test_a_registered_fixture_specialty_projects_its_finalized_version_with_the_source_attribution(): void
    {
        $reference = (string) Str::uuid();
        $command = $this->command($reference);

        $fact = $this->records->recordDiagnosticResultEvidence($command, (string) User::factory()->create()->id);

        $this->assertSame(
            [true, 'waveform', $reference, 1, $this->patient, $command['registration_id'], null],
            [$fact['recorded'], $fact['source_owner'], $fact['result_reference_id'], $fact['source_version'], $fact['patient_id'], $fact['registration_id'], $fact['supersedes_result_reference_id']],
        );
        $this->assertSame(0, DiagnosisAssertion::query()->count(), 'a projection is not a diagnosis');
        $this->assertSame(0, ClinicalHandoff::query()->count(), 'and not a handoff');
    }

    public function test_an_unregistered_or_absent_specialty_is_refused_whatever_it_calls_itself(): void
    {
        foreach (['pathology', 'laboratory', ''] as $owner) {
            try {
                $this->records->recordDiagnosticResultEvidence($this->command((string) Str::uuid(), ['source_owner' => $owner]), (string) User::factory()->create()->id);
                $this->fail("[{$owner}] was accepted although not registered.");
            } catch (\InvalidArgumentException) {
            }
        }

        // With no specialty registered at all (Diagnostics not composed), nothing is accepted.
        $this->registerSpecialties([]);
        $this->expectException(\InvalidArgumentException::class);
        app(ActiveClinicalRecordContract::class)->recordDiagnosticResultEvidence($this->command((string) Str::uuid()), (string) User::factory()->create()->id);
    }

    public function test_a_registered_specialty_other_than_laboratory_and_radiology_is_accepted_and_the_old_two_are_not_special(): void
    {
        $this->registerSpecialties(['radiology']);
        $fact = app(ActiveClinicalRecordContract::class)->recordDiagnosticResultEvidence($this->command((string) Str::uuid(), ['source_owner' => 'radiology']), (string) User::factory()->create()->id);
        $this->assertSame('radiology', $fact['source_owner']);

        $this->expectException(\InvalidArgumentException::class);
        app(ActiveClinicalRecordContract::class)->recordDiagnosticResultEvidence($this->command((string) Str::uuid(), ['source_owner' => 'laboratory']), (string) User::factory()->create()->id);
    }

    public function test_scalar_validation_names_what_is_wrong(): void
    {
        $actor = (string) User::factory()->create()->id;
        $bad = [
            'patient not an identifier' => ['patient_id' => 'not-a-uuid'],
            'reference not an identifier' => ['result_reference_id' => 'abc'],
            'registration not an identifier' => ['registration_id' => '12'],
            'no code' => ['code' => ''],
            'version zero' => ['source_version' => 0],
            'version text' => ['source_version' => 'two'],
            'summary not text' => ['summary' => ['x']],
            'observed later than now' => ['observed_at' => now()->addDay()->toIso8601String()],
            'observed not a time' => ['observed_at' => 'yesterday-ish'],
            'supersedes itself' => ['supersedes_result_reference_id' => '__same__'],
        ];
        foreach ($bad as $name => $override) {
            $reference = (string) Str::uuid();
            $override = array_map(fn ($v) => $v === '__same__' ? $reference : $v, $override);
            try {
                $this->records->recordDiagnosticResultEvidence($this->command($reference, $override), $actor);
                $this->fail("{$name} was accepted.");
            } catch (\InvalidArgumentException) {
            }
        }

        $this->assertSame(0, DiagnosticResultEvidence::query()->count());
    }

    public function test_a_repeated_delivery_or_lost_reply_is_one_projection_of_the_source_version(): void
    {
        $actor = (string) User::factory()->create()->id;
        $command = $this->command((string) Str::uuid());

        $first = $this->records->recordDiagnosticResultEvidence($command, $actor);
        $again = $this->records->recordDiagnosticResultEvidence($command, $actor);
        // Even a repeat that says something else about the same source version changes nothing: the projection is immutable.
        $changed = $this->records->recordDiagnosticResultEvidence(array_merge($command, ['summary' => 'Something else.']), $actor);

        $this->assertSame([true, false, false], [$first['recorded'], $again['recorded'], $changed['recorded']]);
        $this->assertSame([$first['evidence_id'], $first['evidence_id']], [$again['evidence_id'], $changed['evidence_id']]);
        $this->assertSame('Sinus rhythm.', $changed['summary']);
        $this->assertSame(1, DiagnosticResultEvidence::query()->count());
        $this->assertSame(1, \Modules\ClinicalDocumentation\Models\ClinicalAuditEvent::query()->where('action', 'diagnostic_result_evidence_recorded')->count());
    }

    public function test_the_database_refuses_a_second_projection_of_a_source_version_even_when_deliveries_race(): void
    {
        $reference = (string) Str::uuid();
        $this->records->recordDiagnosticResultEvidence($this->command($reference), (string) User::factory()->create()->id);

        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);
        DiagnosticResultEvidence::query()->create([
            'patient_id' => $this->patient, 'source_owner' => 'waveform', 'result_reference_id' => $reference, 'coding_system' => 'LOINC', 'code' => 'x', 'display' => 'x',
            'observed_at' => now()->subMinute(), 'released_by' => (string) Str::uuid(), 'released_by_name' => 'x',
        ]);
    }

    public function test_an_amendment_keeps_its_structured_predecessor_and_the_original_is_history_not_current(): void
    {
        $actor = (string) User::factory()->create()->id;
        [$v1, $v2] = [(string) Str::uuid(), (string) Str::uuid()];
        $this->records->recordDiagnosticResultEvidence($this->command($v1, ['source_version' => 1, 'observed_at' => now()->subHours(2)->toIso8601String()]), $actor);
        $second = $this->records->recordDiagnosticResultEvidence($this->command($v2, ['source_version' => 2, 'supersedes_result_reference_id' => $v1, 'summary' => 'Corrected: atrial fibrillation.']), $actor);

        $read = $this->records->diagnosticResultsForPatient($this->patient, (string) $this->clinician()->id, 'clinical-review')['results'];

        $this->assertSame($v1, $second['supersedes_result_reference_id']);
        $this->assertSame([$v1, $v2], array_column($read, 'result_reference_id'));
        $this->assertSame([false, true], array_column($read, 'is_current'));
        $this->assertSame([$v2, null], array_column($read, 'superseded_by_result_reference_id'));
        $this->assertSame([1, 2], array_column($read, 'source_version'));
    }

    public function test_a_correction_delivered_before_its_predecessor_still_links_when_the_predecessor_arrives(): void
    {
        $actor = (string) User::factory()->create()->id;
        [$v1, $v2] = [(string) Str::uuid(), (string) Str::uuid()];

        // Out of order: the correction lands first, naming a version nobody has projected yet.
        $this->records->recordDiagnosticResultEvidence($this->command($v2, ['source_version' => 2, 'supersedes_result_reference_id' => $v1]), $actor);
        $alone = $this->records->diagnosticResultsForPatient($this->patient, (string) $this->clinician()->id, 'clinical-review')['results'];
        $this->assertSame([true], array_column($alone, 'is_current'));

        $this->records->recordDiagnosticResultEvidence($this->command($v1, ['source_version' => 1, 'observed_at' => now()->subHours(3)->toIso8601String()]), $actor);
        $both = collect($this->records->diagnosticResultsForPatient($this->patient, (string) $this->clinician()->id, 'clinical-review')['results'])->keyBy('result_reference_id');

        $this->assertSame([false, $v2, true], [$both[$v1]['is_current'], $both[$v1]['superseded_by_result_reference_id'], $both[$v2]['is_current']]);
    }

    public function test_one_specialtys_reference_never_supersedes_anothers(): void
    {
        $this->registerSpecialties(['waveform', 'radiology']);
        $records = app(ActiveClinicalRecordContract::class);
        $actor = (string) User::factory()->create()->id;
        $shared = (string) Str::uuid();
        $records->recordDiagnosticResultEvidence($this->command($shared), $actor);
        $records->recordDiagnosticResultEvidence($this->command((string) Str::uuid(), ['source_owner' => 'radiology', 'supersedes_result_reference_id' => $shared]), $actor);

        $read = collect($records->diagnosticResultsForPatient($this->patient, (string) $this->clinician()->id, 'clinical-review')['results'])->firstWhere('source_owner', 'waveform');

        $this->assertTrue($read['is_current'], "radiology's correction names a radiology version, not the waveform one that happens to share the identifier");
    }

    public function test_only_a_treating_clinician_reads_and_the_read_is_audited_and_what_was_never_projected_is_not_there(): void
    {
        $actor = (string) User::factory()->create()->id;
        $this->records->recordDiagnosticResultEvidence($this->command((string) Str::uuid()), $actor);
        $stranger = User::factory()->create();

        try {
            $this->records->diagnosticResultsForPatient($this->patient, (string) $stranger->id, 'clinical-review');
            $this->fail('Someone with no treatment relationship read a patient\'s results.');
        } catch (AuthorizationException) {
        }

        $clinician = $this->clinician();
        $read = $this->records->diagnosticResultsForPatient($this->patient, (string) $clinician->id, 'clinical-review');
        $this->assertCount(1, $read['results']);
        $this->assertDatabaseHas('cd_clinical_audit_events', ['action' => 'diagnostic_results_read', 'actor_id' => (string) $clinician->id, 'patient_id' => $this->patient]);
        // A candidate or an unauthorized version is only ever in its specialty: for a patient nothing was published about, nothing is here.
        $other = (string) Str::uuid();
        $elsewhere = $this->clinician($other);
        $this->assertSame([], $this->records->diagnosticResultsForPatient($other, (string) $elsewhere->id, 'clinical-review')['results']);
        $this->expectException(\InvalidArgumentException::class);
        $this->records->diagnosticResultsForPatient($this->patient, (string) $clinician->id, '  ');
    }

    public function test_a_redelivery_that_says_something_different_about_the_same_source_version_is_refused_not_acknowledged(): void
    {
        $actor = (string) User::factory()->create()->id;
        $command = $this->command((string) Str::uuid());
        $this->records->recordDiagnosticResultEvidence($command, $actor);

        foreach ([['patient_id' => (string) Str::uuid()], ['registration_id' => (string) Str::uuid()], ['source_version' => 2], ['supersedes_result_reference_id' => (string) Str::uuid()]] as $different) {
            try {
                $this->records->recordDiagnosticResultEvidence(array_merge($command, $different), $actor);
                $this->fail('A conflicting redelivery was acknowledged: '.json_encode($different));
            } catch (\InvalidArgumentException) {
            }
        }

        $this->assertSame(1, DiagnosticResultEvidence::query()->count());
    }

    public function test_a_correction_cannot_name_another_patients_version_and_never_marks_it_superseded(): void
    {
        $actor = (string) User::factory()->create()->id;
        $theirs = (string) Str::uuid();
        $this->records->recordDiagnosticResultEvidence($this->command($theirs, ['patient_id' => (string) Str::uuid()]), $actor);

        try {
            $this->records->recordDiagnosticResultEvidence($this->command((string) Str::uuid(), ['supersedes_result_reference_id' => $theirs]), $actor);
            $this->fail("A version superseded another patient's.");
        } catch (\InvalidArgumentException) {
        }

        $this->assertSame(1, DiagnosticResultEvidence::query()->count());
    }

    public function test_a_projection_inside_a_callers_transaction_survives_a_concurrent_delivery_of_the_same_version(): void
    {
        $actor = (string) User::factory()->create()->id;
        $command = $this->command((string) Str::uuid());

        // A retry inside a transaction that already contains the first delivery's winner must not abort the transaction.
        \Illuminate\Support\Facades\DB::transaction(function () use ($command, $actor): void {
            $this->records->recordDiagnosticResultEvidence($command, $actor);
            DiagnosticResultEvidence::query()->insertOrIgnore([
                'id' => (string) Str::uuid(), 'patient_id' => $this->patient, 'source_owner' => 'waveform', 'result_reference_id' => $command['result_reference_id'],
                'coding_system' => 'x', 'code' => 'x', 'display' => 'x', 'observed_at' => now(), 'released_by' => $actor, 'released_by_name' => 'x',
            ]);
            $again = $this->records->recordDiagnosticResultEvidence($command, $actor);
            $this->assertFalse($again['recorded']);
        });

        $this->assertSame(1, DiagnosticResultEvidence::query()->count());
    }

    public function test_a_version_is_corrected_once_so_the_clinical_record_never_shows_two_current_results(): void
    {
        $actor = (string) User::factory()->create()->id;
        [$v1, $v2, $other] = [(string) Str::uuid(), (string) Str::uuid(), (string) Str::uuid()];
        $this->records->recordDiagnosticResultEvidence($this->command($v1), $actor);
        $this->records->recordDiagnosticResultEvidence($this->command($v2, ['source_version' => 2, 'supersedes_result_reference_id' => $v1]), $actor);

        try {
            $this->records->recordDiagnosticResultEvidence($this->command($other, ['source_version' => 2, 'supersedes_result_reference_id' => $v1]), $actor);
            $this->fail('A second correction of an already corrected version forked the line.');
        } catch (\InvalidArgumentException) {
        }

        $current = array_filter($this->records->diagnosticResultsForPatient($this->patient, (string) $this->clinician()->id, 'clinical-review')['results'], fn (array $r): bool => $r['is_current']);
        $this->assertSame([$v2], array_column(array_values($current), 'result_reference_id'));
    }

    public function test_a_specialty_declared_by_a_disabled_module_or_registered_invalidly_is_not_registered(): void
    {
        $this->registerSpecialties(['waveform'], disabled: ['ghost'], mismatched: ['crooked']);
        $records = app(ActiveClinicalRecordContract::class);
        $actor = (string) User::factory()->create()->id;

        $records->recordDiagnosticResultEvidence($this->command((string) Str::uuid()), $actor);
        foreach (['ghost', 'crooked', 'something-else'] as $owner) {
            try {
                $records->recordDiagnosticResultEvidence($this->command((string) Str::uuid(), ['source_owner' => $owner]), $actor);
                $this->fail("[{$owner}] was accepted although its registration is disabled or invalid.");
            } catch (\InvalidArgumentException) {
            }
        }
    }

    public function test_a_concurrent_delivery_with_other_attribution_is_refused_like_a_later_one(): void
    {
        $actor = (string) User::factory()->create()->id;
        $command = $this->command((string) Str::uuid());
        $this->records->recordDiagnosticResultEvidence($command, $actor);
        $service = new \ReflectionMethod($this->records, 'assertSameDelivery');
        $found = DiagnosticResultEvidence::query()->sole();

        // The race path runs the very same comparison on the winner's row.
        $service->invoke($this->records, $found, $this->patient, $command['registration_id'], 1, null);
        $this->expectException(\InvalidArgumentException::class);
        $service->invoke($this->records, $found, (string) Str::uuid(), $command['registration_id'], 1, null);
    }

    public function test_the_database_itself_refuses_a_forked_line_when_two_corrections_race(): void
    {
        $actor = (string) User::factory()->create()->id;
        [$v1, $v2, $v3] = [(string) Str::uuid(), (string) Str::uuid(), (string) Str::uuid()];
        $this->records->recordDiagnosticResultEvidence($this->command($v1), $actor);
        $this->records->recordDiagnosticResultEvidence($this->command($v2, ['source_version' => 2, 'supersedes_result_reference_id' => $v1]), $actor);

        // The application's own check passed for both in a race; the second insert is refused by the index, not silently kept.
        $inserted = DiagnosticResultEvidence::query()->insertOrIgnore([
            'id' => (string) Str::uuid(), 'patient_id' => $this->patient, 'source_owner' => 'waveform', 'result_reference_id' => $v3, 'supersedes_result_reference_id' => $v1,
            'coding_system' => 'x', 'code' => 'x', 'display' => 'x', 'observed_at' => now(), 'released_by' => $actor, 'released_by_name' => 'x',
        ]);

        $this->assertSame(0, $inserted);
        $this->assertSame(2, DiagnosticResultEvidence::query()->count());
    }

    public function test_a_foreign_correction_delivered_before_its_predecessor_cannot_block_the_real_line(): void
    {
        $actor = (string) User::factory()->create()->id;
        [$v1, $stray] = [(string) Str::uuid(), (string) Str::uuid()];

        // Another patient's correction names a version that has not arrived yet: nothing can tell it is foreign.
        $this->records->recordDiagnosticResultEvidence($this->command($stray, ['patient_id' => (string) Str::uuid(), 'source_version' => 2, 'supersedes_result_reference_id' => $v1]), $actor);

        // The version arrives for its own patient: it is refused into the line, rather than adopting or being blocked by the stray.
        try {
            $this->records->recordDiagnosticResultEvidence($this->command($v1), $actor);
            $this->fail("A version arrived into another patient's line.");
        } catch (\InvalidArgumentException) {
        }

        $this->assertSame(1, DiagnosticResultEvidence::query()->count());
    }
}
