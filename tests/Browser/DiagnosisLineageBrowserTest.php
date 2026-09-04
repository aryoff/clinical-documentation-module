<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Str;
use Modules\ClinicalDocumentation\Contracts\ActiveClinicalRecordContract;
use Modules\ClinicalDocumentation\Models\DiagnosisAssertion;
use Modules\ClinicalDocumentation\Tests\Support\CredentialsClinicalActors;
use Spatie\Permission\Models\Permission;

uses(CredentialsClinicalActors::class);

/**
 * The diagnosis lineage, driven the way a clinician actually revises one.
 *
 * Feature tests prove the boundary appends rather than edits. Only a browser
 * proves the clinician can express the difference: the form has to stop
 * offering `initial` once a head exists, has to make the reader say *which*
 * head they are correcting, and has to keep the corrected assertion on screen
 * afterwards. A lineage that was appended correctly but rendered as a
 * replacement would pass every feature test and still lose the history the
 * decision exists to keep.
 */
test('a clinician records a diagnosis, revises it, and both revisions stay readable', function (): void {
    $username = 'diagnosis-lineage-'.Str::lower(Str::random(8));
    $clinician = User::factory()->create([
        'username' => $username,
        'password' => bcrypt('password'),
    ]);

    Permission::findOrCreate('clinicaldocumentation.records.read');
    $clinician->givePermissionTo('clinicaldocumentation.records.read');

    // Authoring and signing are Clinical Authorities, and asserting a diagnosis
    // is authorized as authoring. `Gate::before` refuses each on the permission
    // alone, so an uncredentialed clinician is turned away before the lineage
    // this drives is ever rendered.
    foreach ([
        'clinicaldocumentation.documents.author',
        'clinicaldocumentation.documents.sign',
    ] as $ability) {
        Permission::findOrCreate($ability);
        $this->grantClinicalAbility($clinician, $ability);
    }

    $handoff = app(ActiveClinicalRecordContract::class)->acceptHandoff([
        'registration_id' => (string) Str::uuid(),
        'patient_id' => $patientId = (string) Str::uuid(),
        'source_owner' => 'inpatient',
        'source_reference_id' => (string) Str::uuid(),
        'recipient_id' => (string) $clinician->id,
        'accepted_by' => (string) $clinician->id,
    ]);

    $page = visit('/login')
        ->resize(1280, 900)
        ->fill('Username', $username)
        ->fill('Password', 'password')
        ->press('Log in')
        ->navigate('/clinicaldocumentation/create?handoff_id='.$handoff['handoff_id'])
        ->assertSee('Accepted Clinical Handoff')
        ->fill('#payload', '{"subjective": "Fever and productive cough."}')
        ->press('Create private draft')
        ->waitForText('Continue authoring')
        ->press('Sign and lock')
        ->waitForText('Immutable source');

    // Nothing asserted yet, so the panel says so and offers only `initial`.
    $page->assertSee('No diagnosis has been asserted for this patient yet.');

    $page->fill('#diagnosis_code', 'J18.9')
        ->fill('#diagnosis_display', 'Pneumonia, unspecified organism')
        ->press('#record_diagnosis')
        // The badge an asserted head carries. The panel heading "Diagnosis" is
        // on screen before this and would pass instantly.
        ->waitForText('Current diagnosis')
        ->assertSee('J18.9')
        ->assertDontSee('No diagnosis has been asserted');

    $initial = DiagnosisAssertion::query()->sole();
    expect($initial->assertion_type)->toBe('initial')
        ->and($initial->revision)->toBe(1)
        ->and($initial->lineage_id)->toBe($initial->id);

    // With a head standing, `initial` is no longer on offer — a care journey
    // gets one, and the form must not invite a second.
    $page->assertDontSee('Initial diagnosis');

    $page->select('#assertion_type', 'Correct a current diagnosis')
        ->assertVisible('#supersedes_assertion_id')
        // Naming the head being corrected is the whole point of the control,
        // and it is `required`: left on its placeholder the browser blocks the
        // submit with a native validation bubble, so no request is ever made
        // and the wait below sits on text that is never coming.
        ->select('#supersedes_assertion_id', 'J18.9 — Pneumonia, unspecified organism')
        ->fill('#diagnosis_code', 'J15.9')
        ->fill('#diagnosis_display', 'Bacterial pneumonia, unspecified')
        ->fill('#diagnosis_note', 'Sputum culture returned.')
        ->press('#record_diagnosis')
        // "Superseded" is the badge the *predecessor* gains, so it cannot
        // appear until the append has actually happened. The new code would be
        // on screen the moment it was typed.
        ->waitForText('Superseded')
        ->assertSee('J15.9')
        // The corrected assertion is still there. This is the whole point.
        ->assertSee('J18.9')
        ->assertNoJavaScriptErrors();

    $lineage = DiagnosisAssertion::query()->orderBy('revision')->get();
    expect($lineage)->toHaveCount(2)
        ->and($lineage->last()->assertion_type)->toBe('supersession')
        ->and($lineage->last()->revision)->toBe(2)
        ->and($lineage->last()->lineage_id)->toBe($initial->lineage_id)
        ->and($lineage->last()->supersedes_assertion_id)->toBe($initial->id);

    // The Clinical Diagnosis Read shows the same history without the note.
    $page->navigate("/clinical-documentation/patients/{$patientId}/diagnoses")
        ->waitForText('Current diagnoses')
        ->assertSee('J15.9')
        ->assertSee('J18.9')
        ->assertDontSee('Fever and productive cough.')
        ->assertNoJavaScriptErrors();
})->group('browser', 'clinical-documentation', 'clinical-authoring');
