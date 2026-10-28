<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Str;
use Modules\HospitalCore\Models\Patient;
use Spatie\Permission\Models\Permission;

/**
 * A records officer sets the retention schedule, places a legal hold and reads the review that says why the patient's
 * records must be kept. Feature tests prove the boundary; only a browser proves the officer can reach it, that the
 * reasons render as sentences, and that this page offers no way to destroy anything.
 */
test('a records officer records a schedule, holds a patient and reads why their records must be retained', function (): void {
    $username = 'retention-officer-'.Str::lower(Str::random(8));
    $officer = User::factory()->create(['username' => $username, 'password' => bcrypt('password')]);
    foreach (['view', 'schedule', 'hold', 'extend'] as $ability) {
        Permission::findOrCreate('clinicaldocumentation.retention.'.$ability);
        $officer->givePermissionTo('clinicaldocumentation.retention.'.$ability);
    }
    $patient = Patient::factory()->create();

    $page = visit('/login')
        ->resize(1280, 900)
        ->fill('Username', $username)
        ->fill('Password', 'password')
        ->press('Log in')
        ->navigate('/clinical-documentation/retention')
        ->assertSee('Destruction is disabled')
        ->fill('#schedule-rationale', 'Facility retention policy')
        ->assertSee('Record schedule version')
        ->press('#record-schedule')
        ->assertSee('Facility retention policy')
        ->fill('#hold-patient', $patient->id)
        ->fill('#hold-reason', 'Complaint under investigation')
        ->press('#place-hold')
        ->assertSee('Complaint under investigation');

    $page->navigate('/clinical-documentation/retention/patients/'.$patient->id)
        ->assertSee('Retention review')
        ->assertSee('A legal hold is active.')
        ->assertSee('Must be retained')
        ->assertDontSee('May be considered for destruction review');
});
