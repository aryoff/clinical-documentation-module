<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Str;
use Modules\ClinicalDocumentation\Contracts\ActiveClinicalRecordContract;
use Modules\ClinicalDocumentation\Tests\Support\AdministrationFacts;
use Spatie\Permission\Models\Permission;

uses(AdministrationFacts::class);

test(
    "a treating reader follows an administration correction to its unchanged original",
    function (): void {
        $this->requireAdministrationPublisher();
        $username = "administration-reader-" . Str::lower(Str::random(8));
        $reader = User::factory()->create([
            "username" => $username,
            "password" => bcrypt("password"),
        ]);
        Permission::findOrCreate("clinicaldocumentation.records.read");
        $reader->givePermissionTo("clinicaldocumentation.records.read");
        $original = $this->administrationFact();
        $correction = $this->administrationFact([
            "type" => "corrected",
            "corrects_administration_id" => $original["administration_id"],
            "correction_reason" => "Corrected dose: 250 mg.",
            "dose" => ["quantity" => "250", "unit" => "mg"],
            "occurred_at" => "2026-10-12T10:05:00+07:00",
        ]);
        event("eprescription.administration-clinical-fact-published", [
            $correction,
        ]);
        event("eprescription.administration-clinical-fact-published", [
            $original,
        ]);
        app(ActiveClinicalRecordContract::class)->acceptHandoff([
            "registration_id" => $original["registration_id"],
            "patient_id" => $original["patient_id"],
            "source_owner" => "inpatient",
            "source_reference_id" => $original["administration_id"],
            "recipient_id" => (string) $reader->id,
            "accepted_by" => (string) $reader->id,
        ]);

        $page = visit("/login")
            ->resize(1280, 900)
            ->fill("Username", $username)
            ->fill("Password", "password")
            ->press("Log in")
            ->navigate("/clinicaldocumentation")
            ->waitForText("Active clinical records")
            ->click(
                'a[href$="/patients/' . $original["patient_id"] . '/record"]',
            )
            ->waitForText("Medication administration")
            ->assertSee("Paracetamol · Given")
            ->assertSee("500 mg")
            ->assertSee("250 mg")
            ->assertSee("Nurse Ari")
            ->assertSee("Care location: Ward A")
            ->assertSee("Administration correction")
            ->assertSee("Corrected dose: 250 mg.")
            ->assertSee("View original administration")
            ->click("View original administration")
            ->assertSee("500 mg")
            ->assertDontSee("Sign and lock")
            ->assertDontSee("Create private draft")
            ->assertNoJavaScriptErrors();
        $page->screenshot(filename: "clinical3-administration-record");
    },
)->group("browser", "clinical-documentation", "clinical-authoring");
