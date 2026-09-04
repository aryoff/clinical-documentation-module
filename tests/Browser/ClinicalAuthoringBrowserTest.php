<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Str;
use Modules\ClinicalDocumentation\Contracts\ActiveClinicalRecordContract;
use Modules\ClinicalDocumentation\Models\ClinicalAddendum;
use Modules\ClinicalDocumentation\Models\ClinicalDocument;
use Modules\ClinicalDocumentation\Tests\Support\CredentialsClinicalActors;
use Spatie\Permission\Models\Permission;

uses(CredentialsClinicalActors::class);

/**
 * Clinical authoring depends materially on browser behaviour: the payload is
 * authored in an action panel that submits through Inertia, signing navigates
 * to a record that must then refuse its own edit form, and the refusal has to
 * land the author on the addendum path rather than on an error. Feature tests
 * prove the boundary permits and refuses; only a browser proves a clinician
 * can get through it.
 */
test('a clinician drafts, signs, is refused an edit, and records an addendum', function (): void {
    $username = 'clinical-authoring-'.Str::lower(Str::random(8));
    $clinician = User::factory()->create([
        'username' => $username,
        'password' => bcrypt('password'),
    ]);

    Permission::findOrCreate('clinicaldocumentation.records.read');
    $clinician->givePermissionTo('clinicaldocumentation.records.read');

    // Authoring, signing and amending are Clinical Authorities. `Gate::before`
    // refuses each of them on the permission alone, so a clinician granted only
    // the permission is turned away at `/clinicaldocumentation/create` and never
    // reaches the workspace this drives.
    foreach ([
        'clinicaldocumentation.documents.author',
        'clinicaldocumentation.documents.sign',
        'clinicaldocumentation.documents.amend',
    ] as $ability) {
        Permission::findOrCreate($ability);
        $this->grantClinicalAbility($clinician, $ability);
    }

    // Authoring is handoff-gated, and no care context accepts a handoff through
    // its own UI yet, so the journey starts from the declared contract.
    $handoff = app(ActiveClinicalRecordContract::class)->acceptHandoff([
        'registration_id' => (string) Str::uuid(),
        'patient_id' => (string) Str::uuid(),
        'source_owner' => 'outpatient',
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
        ->assertSee($handoff['handoff_id'])
        ->fill('Clinical payload', '{"subjective": "Persistent headache for three days"}')
        ->press('Create private draft')
        ->waitForText('Continue authoring')
        ->assertSee('Draft protection');

    $document = ClinicalDocument::query()->where('handoff_id', $handoff['handoff_id'])->sole();

    expect($document->status)->toBe('draft')
        ->and($document->payload)->toBe(['subjective' => 'Persistent headache for three days']);

    // Signing locks the source permanently.
    $page->press('Sign and lock')
        ->waitForText('Immutable source')
        ->assertSee('Persistent headache for three days');

    expect($document->refresh()->status)->toBe('signed');

    // The edit form is now refused, and the refusal names the addendum.
    $page->navigate("/clinicaldocumentation/{$document->id}/edit")
        ->waitForText('Signed clinical documents are immutable; create an addendum.')
        ->assertSee('The signed source stays exactly as you signed it.')
        ->assertDontSee('Continue authoring');

    // The addendum is a separate signed record that leaves its source intact.
    $page->navigate("/clinicaldocumentation/{$document->id}")
        ->waitForText('Immutable source')
        ->press('Add addendum')
        ->fill('Reason', 'Clarifies the reported symptom duration.')
        ->fill('Addendum payload', '{"clarification": "Symptoms started three days ago."}')
        ->press('Record signed addendum')
        ->waitForText('Immutable source')
        ->assertSee('Persistent headache for three days')
        ->assertNoJavaScriptErrors();

    $addendum = ClinicalAddendum::query()->where('document_id', $document->id)->sole();

    expect($addendum->reason)->toBe('Clarifies the reported symptom duration.')
        ->and($addendum->payload)->toBe(['clarification' => 'Symptoms started three days ago.'])
        ->and($addendum->signed_at)->not->toBeNull()
        ->and($document->refresh()->payload)->toBe(['subjective' => 'Persistent headache for three days']);
})->group('browser', 'clinical-documentation', 'clinical-authoring');
