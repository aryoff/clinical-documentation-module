<?php

declare(strict_types=1);

namespace Modules\ClinicalDocumentation\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Modules\ClinicalDocumentation\Contracts\ActiveClinicalRecordContract;
use Modules\ClinicalDocumentation\Tests\Support\AdministrationFacts;
use Tests\TestCase;

class AdministrationRecordTest extends TestCase
{
    use AdministrationFacts;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireAdministrationPublisher();
    }

    public function test_read_route_requires_read_permission_and_treatment_access_and_audits_success(): void
    {
        $fact = $this->administrationFact();
        event("eprescription.administration-clinical-fact-published", [$fact]);
        $reader = User::factory()->create();
        $url = route("clinicaldocumentation.records.show", $fact["patient_id"]);
        $this->actingAs($reader)->get($url)->assertForbidden();
        $reader->givePermissionTo("clinicaldocumentation.records.read");
        $this->actingAs($reader)->get($url)->assertForbidden();
        $this->acceptTreatment($fact, $reader);
        $this->actingAs($reader)
            ->get($url)
            ->assertOk()
            ->assertInertia(
                fn(Assert $page) => $page
                    ->component("ClinicalDocumentation::Record")
                    ->where(
                        "record.entries.0.fact.medicine.product_name",
                        "Paracetamol",
                    )
                    ->where("record.entries.0.fact.verification.patient", true),
            );
        $this->assertDatabaseHas("cd_clinical_audit_events", [
            "action" => "active_clinical_record_read",
            "patient_id" => $fact["patient_id"],
            "actor_id" => $reader->id,
            "reason" => "clinical-record-review",
        ]);
        $this->actingAs($reader)
            ->get(route("clinicaldocumentation.index"))
            ->assertOk()
            ->assertInertia(
                fn(Assert $page) => $page->where(
                    "activeRecords.0.patient_id",
                    $fact["patient_id"],
                ),
            );
    }

    private function acceptTreatment(array $fact, User $reader): array
    {
        return app(ActiveClinicalRecordContract::class)->acceptHandoff([
            "registration_id" => $fact["registration_id"],
            "patient_id" => $fact["patient_id"],
            "source_owner" => "inpatient",
            "source_reference_id" => $fact["administration_id"],
            "recipient_id" => (string) $reader->id,
            "accepted_by" => (string) $reader->id,
        ]);
    }
}
