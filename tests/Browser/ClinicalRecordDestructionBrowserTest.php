<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Modules\ClinicalDocumentation\Models\DiagnosticResultEvidence;
use Modules\ClinicalDocumentation\Services\ClinicalRecordRetention;
use Modules\HospitalCore\Contracts\AttendanceEvidenceContract;
use Modules\Laboratory\Models\LabResult;
use Modules\Laboratory\Tests\Support\Walk;
use Spatie\Permission\Models\Permission;

/**
 * A records officer reads the review of a patient well past retention and destroys the findings in one action, then
 * sees the processing history. Feature tests prove the boundary; only a browser proves the control appears exactly when
 * the review allows it and that the outcome reads as a history, not a silent deletion.
 */
test('a records officer destroys the findings of a patient past retention and reads the history', function (): void {
    $username = 'destroyer-'.Str::lower(Str::random(8));
    $officer = User::factory()->create(['username' => $username, 'password' => bcrypt('password')]);
    foreach (['view', 'destroy'] as $ability) {
        Permission::findOrCreate('clinicaldocumentation.retention.'.$ability);
        $officer->givePermissionTo('clinicaldocumentation.retention.'.$ability);
    }
    config(['hospitalcore.attendance.reporting_modules' => [], 'hospitalcore.attendance.expected_providers' => ['laboratory'], 'clinicaldocumentation.retention.destruction_enabled' => true]);
    app(ClinicalRecordRetention::class)->recordSchedule(25, 'Facility policy', (string) $officer->id);
    if (! \Tests\Support\ModuleUnderTest::isEnabled('Laboratory') || ! class_exists(Walk::class)) {
        test()->markTestSkipped('Laboratory is not composed here.');
    }
    $walk = Walk::released('4.2');
    $patientId = $walk['registration']['patient_id'];
    $evidence = app(AttendanceEvidenceContract::class);
    $evidence->recordAttendanceEnded(['provider' => 'laboratory', 'source_reference' => 'lab-old', 'patient_id' => $patientId, 'kind' => 'diagnostic', 'ended_at' => '2000-01-01 09:00:00']);
    $evidence->reportCoverage(['provider' => 'laboratory', 'covered_through' => CarbonImmutable::now()->subMinute()->toIso8601String()]);

    visit('/login')
        ->resize(1280, 900)
        ->fill('Username', $username)
        ->fill('Password', 'password')
        ->press('Log in')
        ->navigate('/clinical-documentation/retention/patients/'.$patientId)
        ->assertSee('May be considered for destruction review')
        ->assertSee('Destroying removes the findings')
        ->fill('#destroy-reason', 'Retention period ended')
        ->click('button[role=checkbox]')
        ->press('#destroy-findings')
        ->navigate('/clinical-documentation/retention/destruction')
        ->assertSee('Complete')
        ->assertSee('Retention period ended');

    expect(LabResult::findOrFail($walk['result']->id)->values)->toBeNull()
        ->and(DiagnosticResultEvidence::where('patient_id', $patientId)->firstOrFail()->summary)->toBeNull();
});
