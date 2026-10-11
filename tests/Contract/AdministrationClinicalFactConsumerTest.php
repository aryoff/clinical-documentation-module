<?php

declare(strict_types=1);

namespace Modules\ClinicalDocumentation\Tests\Contract;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\ClinicalDocumentation\Contracts\ActiveClinicalRecordContract;
use Modules\ClinicalDocumentation\Tests\Support\AdministrationFacts;
use Tests\TestCase;

class AdministrationClinicalFactConsumerTest extends TestCase
{
    use AdministrationFacts;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireAdministrationPublisher();
    }

    public function test_named_fact_is_projected_once_without_a_handoff_and_preserves_the_source_snapshot(): void
    {
        $fact = $this->administrationFact();
        event("eprescription.administration-clinical-fact-published", [$fact]);
        event("eprescription.administration-clinical-fact-published", [$fact]);
        $this->assertDatabaseCount("cd_clinical_handoffs", 0);
        $this->assertDatabaseCount("cd_clinical_documents", 0);
        $this->assertDatabaseCount("cd_archive_packages", 0);
        $this->assertDatabaseCount("cd_archive_administration_entries", 0);

        $reader = User::factory()->create();
        $records = app(ActiveClinicalRecordContract::class);
        $records->acceptHandoff([
            "registration_id" => $fact["registration_id"],
            "patient_id" => $fact["patient_id"],
            "source_owner" => "inpatient",
            "source_reference_id" => $fact["administration_id"],
            "recipient_id" => (string) $reader->id,
            "accepted_by" => (string) $reader->id,
        ]);
        $record = $records->chronologicalRecord(
            $fact["patient_id"],
            (string) $reader->id,
            "treatment",
        );

        $this->assertCount(1, $record["entries"]);
        $this->assertSame(
            "medication_administration",
            $record["entries"][0]["kind"],
        );
        $this->assertSame($fact, $record["entries"][0]["fact"]);
    }
    public function test_record_orders_signed_documents_and_linked_source_corrections_without_rewriting_originals(): void
    {
        $original = $this->administrationFact();
        $correction = $this->administrationFact([
            "type" => "corrected",
            "corrects_administration_id" => $original["administration_id"],
            "correction_reason" => "Actually administered 250 mg.",
            "dose" => ["quantity" => "250", "unit" => "mg"],
            "occurred_at" => "2026-10-12T10:05:00+07:00",
        ]);
        $reconciliation = [
            "fact_id" => (string) \Illuminate\Support\Str::uuid(),
            "fact_version" => "1.4.0",
            "type" => "basis_reconciled",
            "occurred_at" => "2026-10-12T10:08:00+07:00",
            "source_reference" => $original["source_reference"],
            "administration_id" => $original["administration_id"],
            "registration_id" => $original["registration_id"],
            "patient_id" => $original["patient_id"],
            "reconciliation_id" => (string) \Illuminate\Support\Str::uuid(),
            "basis" => ["type" => "emergency", "state" => "emergency-affirmed"],
            "administration" => [
                "outcome" => "given",
                "dose_quantity" => "500",
                "dose_unit" => "mg",
                "product_name" => "Paracetamol",
            ],
            "reconciled_by" => [
                "id" => (string) \Illuminate\Support\Str::uuid(),
                "name" => "Dr Sari",
            ],
            "reconciled_at" => "2026-10-12T10:08:00+07:00",
            "variable_dose_selection" => null,
            "variable_dose_deviation" => null,
        ];
        // Async delivery can arrive in reverse order; linkage is an opaque source ID.
        foreach (
            [$reconciliation, $correction, $original, $correction]
            as $fact
        ) {
            event("eprescription.administration-clinical-fact-published", [
                $fact,
            ]);
        }
        $reader = User::factory()->create();
        $records = app(ActiveClinicalRecordContract::class);
        $handoff = $records->acceptHandoff([
            "registration_id" => $original["registration_id"],
            "patient_id" => $original["patient_id"],
            "source_owner" => "inpatient",
            "source_reference_id" => $original["administration_id"],
            "recipient_id" => (string) $reader->id,
            "accepted_by" => (string) $reader->id,
        ]);
        $draft = $records->createDraft(
            [
                "handoff_id" => $handoff["handoff_id"],
                "template" => "soap",
                "template_version" => "1.0.0",
                "encountered_at" => "2026-10-12T09:55:00+07:00",
                "payload" => ["assessment" => "Pain"],
            ],
            (string) $reader->id,
        );
        $records->signDocument($draft["document_id"], (string) $reader->id);
        $addendum = $records->createAddendum(
            [
                "document_id" => $draft["document_id"],
                "reason" => "Reassessed pain",
                "encountered_at" => "2026-10-12T10:06:00+07:00",
                "payload" => ["assessment" => "Improving"],
            ],
            (string) $reader->id,
        );
        $records->signAddendum($addendum["addendum_id"], (string) $reader->id);
        $records->createDraft(
            [
                "handoff_id" => $handoff["handoff_id"],
                "template" => "soap",
                "template_version" => "1.0.0",
                "payload" => ["assessment" => "Private unfinished draft"],
            ],
            (string) $reader->id,
        );
        $entries = $records->chronologicalRecord(
            $original["patient_id"],
            (string) $reader->id,
            "treatment",
        )["entries"];

        $this->assertSame(
            [
                "clinical_document",
                "medication_administration",
                "medication_administration",
                "clinical_addendum",
                "medication_administration",
            ],
            array_column($entries, "kind"),
        );
        $this->assertSame($original, $entries[1]["fact"]);
        $this->assertSame($correction, $entries[2]["fact"]);
        $this->assertSame($reconciliation, $entries[4]["fact"]);
    }

    public function test_patient_identity_is_resolved_forward_without_refiling_the_frozen_source_fact(): void
    {
        $fact = $this->administrationFact();
        event("eprescription.administration-clinical-fact-published", [$fact]);
        $reader = User::factory()->create();
        $records = app(ActiveClinicalRecordContract::class);
        $records->acceptHandoff([
            "registration_id" => $fact["registration_id"],
            "patient_id" => $fact["patient_id"],
            "source_owner" => "inpatient",
            "source_reference_id" => $fact["administration_id"],
            "recipient_id" => (string) $reader->id,
            "accepted_by" => (string) $reader->id,
        ]);
        $canonical_id = (string) \Illuminate\Support\Str::uuid();
        event("hospitalcore.patient-identity-reconciled", [
            [
                "canonical_patient_id" => $canonical_id,
                "superseded_patient_id" => $fact["patient_id"],
                "reconciled_by" => (string) $reader->id,
                "reason" => "Provisional identity resolved",
                "reconciled_at" => "2026-10-12T11:00:00+07:00",
            ],
        ]);
        $record = $records->chronologicalRecord(
            $canonical_id,
            (string) $reader->id,
            "treatment",
        );
        $this->assertCount(1, $record["entries"]);
        $this->assertSame($fact, $record["entries"][0]["fact"]);
        $this->assertSame(
            $canonical_id,
            $records->chronologicalRecord(
                $fact["patient_id"],
                (string) $reader->id,
                "treatment",
            )["patient_id"],
        );
    }

    public function test_existing_archive_intent_includes_only_this_journeys_immutable_administrations_and_later_corrections(): void
    {
        $fact = $this->administrationFact();
        event("eprescription.administration-clinical-fact-published", [$fact]);
        event("eprescription.administration-clinical-fact-published", [
            $this->administrationFact([
                "registration_id" => (string) \Illuminate\Support\Str::uuid(),
            ]),
        ]);
        $author = User::factory()->create();
        $records = app(ActiveClinicalRecordContract::class);
        $handoff = $records->acceptHandoff([
            "registration_id" => $fact["registration_id"],
            "patient_id" => $fact["patient_id"],
            "source_owner" => "inpatient",
            "source_reference_id" => $fact["administration_id"],
            "recipient_id" => (string) $author->id,
            "accepted_by" => (string) $author->id,
        ]);
        $draft = $records->createDraft(
            [
                "handoff_id" => $handoff["handoff_id"],
                "template" => "soap",
                "template_version" => "1.0.0",
                "payload" => ["assessment" => "Pain"],
            ],
            (string) $author->id,
        );
        $records->signDocument($draft["document_id"], (string) $author->id);
        $archive = $records->archiveDocument(
            $draft["document_id"],
            (string) $author->id,
        );
        $this->assertSame("local_retention", $archive["custody_state"]);
        $this->assertSame([$fact], $archive["administration_facts"]);
        $correction = $this->administrationFact([
            "type" => "corrected",
            "corrects_administration_id" => $fact["administration_id"],
            "correction_reason" => "Dose clarified",
            "occurred_at" => "2026-10-12T10:05:00+07:00",
        ]);
        event("eprescription.administration-clinical-fact-published", [
            $correction,
        ]);
        $later = $records->archiveDocument(
            $draft["document_id"],
            (string) $author->id,
        );
        $this->assertSame($archive["package_id"], $later["package_id"]);
        $this->assertSame([$fact, $correction], $later["administration_facts"]);
        $this->assertSame(
            [$fact, $correction],
            $records->archiveDocument(
                $draft["document_id"],
                (string) $author->id,
            )["administration_facts"],
        );
    }

    public static function malformedPublications(): array
    {
        return [
            "unsupported major" => [["fact_version" => "2.0.0"]],
            "missing medicine" => [["medicine" => null]],
            "wrong reference" => [
                [
                    "source_reference" =>
                        "eprescription.medication-administration:someone-else",
                ],
            ],
            "object instead of scalar map" => [
                ["verification" => (object) ["patient" => true]],
            ],
            "correction without predecessor" => [["type" => "corrected"]],
            "reconciliation without evidence" => [
                ["type" => "basis_reconciled"],
            ],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider("malformedPublications")]
    public function test_incompatible_or_malformed_publications_are_refused(
        array $overrides,
    ): void {
        $this->expectException(\InvalidArgumentException::class);
        event("eprescription.administration-clinical-fact-published", [
            $this->administrationFact($overrides),
        ]);
    }

    public function test_a_replay_with_changed_scalar_types_cannot_replace_the_finalized_fact(): void
    {
        $fact = $this->administrationFact();
        event("eprescription.administration-clinical-fact-published", [$fact]);
        $fact["dose"]["quantity"] = 500;
        $this->expectException(\DomainException::class);
        event("eprescription.administration-clinical-fact-published", [$fact]);
    }

    public function test_optional_consumption_declares_the_version_and_verifies_the_provider_contract_shape(): void
    {
        $manifest = json_decode(
            file_get_contents(
                module_path("ClinicalDocumentation", "module.json"),
            ),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $consumption = collect(
            $manifest["capabilities"]["consumes"],
        )->firstWhere("id", "eprescription.administration-clinical-fact");
        $this->assertSame("^1.4", $consumption["version"]);
        $this->assertSame("async", $consumption["mode"]);
        $this->assertFalse($consumption["required"]);
        $contract = file_get_contents(
            base_path(
                "Modules/EPrescription/contracts/eprescription.administration-clinical-fact/v1/contract.md",
            ),
        );
        foreach (
            [
                "fact_id",
                "fact_version",
                "source_reference",
                "administration_id",
                "corrects_administration_id",
                "registration_id",
                "patient_id",
                "medicine",
                "dose",
                "route",
                "administered_at",
                "administered_by",
                "verification",
                "care_location",
                "basis",
                "reconciliation_id",
            ]
            as $key
        ) {
            $this->assertStringContainsString("`" . $key . "`", $contract);
        }
    }
    public static function nonAdministeredOutcomes(): array
    {
        return [["held"], ["refused"], ["missed"]];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider("nonAdministeredOutcomes")]
    public function test_a_non_administered_outcome_preserves_nullable_verification_and_dose(
        string $outcome,
    ): void {
        $fact = $this->administrationFact([
            "outcome" => $outcome,
            "reason" => "No medicine administered",
            "dose" => null,
            "verification" => null,
        ]);
        event("eprescription.administration-clinical-fact-published", [$fact]);
        $reader = User::factory()->create();
        $records = app(ActiveClinicalRecordContract::class);
        $records->acceptHandoff([
            "registration_id" => $fact["registration_id"],
            "patient_id" => $fact["patient_id"],
            "source_owner" => "inpatient",
            "source_reference_id" => $fact["administration_id"],
            "recipient_id" => (string) $reader->id,
            "accepted_by" => (string) $reader->id,
        ]);
        $entry = $records->chronologicalRecord(
            $fact["patient_id"],
            (string) $reader->id,
            "treatment",
        )["entries"][0];
        $this->assertSame($outcome, $entry["fact"]["outcome"]);
        $this->assertNull($entry["fact"]["verification"]);
        $this->assertNull($entry["fact"]["dose"]);
    }
    public function test_numeric_observation_snapshots_survive_receipt_and_exact_replay(): void
    {
        $fact = $this->administrationFact([
            "variable_dose_selection" => [
                "readings" => [["value" => 100.0, "unit" => "mg/dL"]],
            ],
        ]);
        event("eprescription.administration-clinical-fact-published", [$fact]);
        event("eprescription.administration-clinical-fact-published", [$fact]);
        $reader = User::factory()->create();
        $records = app(ActiveClinicalRecordContract::class);
        $records->acceptHandoff([
            "registration_id" => $fact["registration_id"],
            "patient_id" => $fact["patient_id"],
            "source_owner" => "inpatient",
            "source_reference_id" => $fact["administration_id"],
            "recipient_id" => (string) $reader->id,
            "accepted_by" => (string) $reader->id,
        ]);
        $entries = $records->chronologicalRecord(
            $fact["patient_id"],
            (string) $reader->id,
            "treatment",
        )["entries"];
        $this->assertCount(1, $entries);
        $this->assertSame(
            100.0,
            $entries[0]["fact"]["variable_dose_selection"]["readings"][0][
                "value"
            ],
        );
    }
}
