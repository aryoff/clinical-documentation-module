<?php

declare(strict_types=1);

namespace Modules\ClinicalDocumentation\Tests\Feature;

use App\Models\User;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\HospitalCore\Models\Registration;
use Modules\HospitalCore\Models\Patient;
use Modules\HospitalCore\Models\Doctor;
use Modules\HospitalCore\Models\Room;
use Modules\HospitalCore\Models\ServiceClass;
use Modules\HospitalCore\Models\PatientGroup;
use Modules\HospitalCore\Models\Department;
use Modules\HospitalCore\Models\IcdCode;
use Modules\ClinicalDocumentation\Models\SoapNote;
use Modules\ClinicalDocumentation\Services\SoapNoteService;

class SoapNoteServiceTest extends TestCase
{
    use RefreshDatabase;

    private SoapNoteService $service;
    private User $author;
    private Registration $registration;
    private IcdCode $icdCode;
    private Department $department;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new SoapNoteService();
        $this->author = User::factory()->create();

        $this->department = Department::factory()->create();
        $serviceClass = ServiceClass::factory()->create();
        $patientGroup = PatientGroup::factory()->create();
        $room = Room::factory()->create(['service_class_id' => $serviceClass->id]);
        $doctor = Doctor::factory()->create();
        $patient = Patient::factory()->create();

        $this->registration = Registration::create([
            'patient_id' => $patient->id,
            'room_id' => $room->id,
            'doctor_id' => $doctor->id,
            'patient_group_id' => $patientGroup->id,
            'department_id' => $this->department->id,
            'registered_by' => $this->author->id,
            'registration_number' => 'REG-100001',
            'type' => 'outpatient',
            'status' => 'active',
            'registered_at' => now(),
        ]);

        $this->icdCode = IcdCode::create([
            'code' => 'Z00.0',
            'description' => 'General adult medical examination',
        ]);
    }

    public function test_can_create_draft_soap_note(): void
    {
        $note = $this->service->create([
            'registration_id' => $this->registration->id,
            'subjective' => 'Patient has headache',
            'vitals' => [
                'temperature' => 36.6,
                'systolic_bp' => 120,
                'diastolic_bp' => 80,
            ],
        ], (string) $this->author->id);

        $this->assertInstanceOf(SoapNote::class, $note);
        $this->assertEquals('draft', $note->status);
        $this->assertEquals('Patient has headache', $note->subjective);
        $this->assertEquals(36.6, $note->vitals->temperature);
        $this->assertDatabaseHas('cd_soap_notes', [
            'id' => $note->id,
            'status' => 'draft',
        ]);
    }

    public function test_throws_exception_on_cancelled_registration(): void
    {
        $cancelledRegistration = Registration::create([
            'patient_id' => $this->registration->patient_id,
            'room_id' => $this->registration->room_id,
            'doctor_id' => $this->registration->doctor_id,
            'patient_group_id' => $this->registration->patient_group_id,
            'department_id' => $this->department->id,
            'registered_by' => $this->author->id,
            'registration_number' => 'REG-100002',
            'type' => 'outpatient',
            'status' => 'cancelled',
            'registered_at' => now(),
        ]);

        $this->expectException(\InvalidArgumentException::class);

        $this->service->create([
            'registration_id' => $cancelledRegistration->id,
            'subjective' => 'Patient has headache',
        ], (string) $this->author->id);
    }

    public function test_can_update_draft_soap_note(): void
    {
        $note = $this->service->create([
            'registration_id' => $this->registration->id,
            'subjective' => 'Patient has headache',
        ], (string) $this->author->id);

        $updated = $this->service->update($note->id, [
            'subjective' => 'Updated headache notes',
            'objective' => 'Normal physical exam',
        ], (string) $this->author->id);

        $this->assertEquals('Updated headache notes', $updated->subjective);
        $this->assertEquals('Normal physical exam', $updated->objective);
    }

    public function test_cannot_update_non_draft_soap_note(): void
    {
        $note = $this->service->create([
            'registration_id' => $this->registration->id,
            'subjective' => 'Patient has headache',
        ], (string) $this->author->id);

        $this->service->submit($note->id, [[
            'icd_code_id' => $this->icdCode->id,
            'diagnosis_type' => 'primary',
        ]], (string) $this->author->id);

        $this->expectException(\LogicException::class);

        $this->service->update($note->id, [
            'subjective' => 'Updated headache notes',
        ], (string) $this->author->id);
    }

    public function test_can_submit_and_finalize_soap_note(): void
    {
        $note = $this->service->create([
            'registration_id' => $this->registration->id,
            'subjective' => 'Patient has headache',
        ], (string) $this->author->id);

        $submitted = $this->service->submit($note->id, [[
            'icd_code_id' => $this->icdCode->id,
            'diagnosis_type' => 'primary',
            'notes' => 'Primary headache',
        ]], (string) $this->author->id);

        $this->assertEquals('submitted', $submitted->status);
        $this->assertNotNull($submitted->submitted_at);

        $this->assertDatabaseHas('hc_visit_diagnoses', [
            'registration_id' => $this->registration->id,
            'icd_code_id' => $this->icdCode->id,
            'diagnosis_type' => 'primary',
        ]);

        $this->assertDatabaseHas('hc_patient_timeline', [
            'registration_id' => $this->registration->id,
            'event_type' => 'soap_note',
            'reference_uuid' => $submitted->id,
        ]);
    }

    public function test_can_amend_submitted_soap_note(): void
    {
        $note = $this->service->create([
            'registration_id' => $this->registration->id,
            'subjective' => 'Patient has headache',
        ], (string) $this->author->id);

        $submitted = $this->service->submit($note->id, [[
            'icd_code_id' => $this->icdCode->id,
            'diagnosis_type' => 'primary',
        ]], (string) $this->author->id);

        $amendment = $this->service->amend($submitted->id, (string) $this->author->id);

        $this->assertEquals('draft', $amendment->status);
        $this->assertEquals($submitted->id, $amendment->amended_from_id);
        $this->assertEquals('Patient has headache', $amendment->subjective);

        // Submit the amendment
        $finalizedAmendment = $this->service->submit($amendment->id, [[
            'icd_code_id' => $this->icdCode->id,
            'diagnosis_type' => 'primary',
        ]], (string) $this->author->id);

        $this->assertEquals('submitted', $finalizedAmendment->status);

        // Parent note should now be superseded
        $this->assertDatabaseHas('cd_soap_notes', [
            'id' => $submitted->id,
            'status' => 'superseded',
        ]);
    }

    public function test_prohibits_multi_branching_amendments(): void
    {
        $note = $this->service->create([
            'registration_id' => $this->registration->id,
            'subjective' => 'Patient has headache',
        ], (string) $this->author->id);

        $submitted = $this->service->submit($note->id, [[
            'icd_code_id' => $this->icdCode->id,
            'diagnosis_type' => 'primary',
        ]], (string) $this->author->id);

        // Create first amendment
        $this->service->amend($submitted->id, (string) $this->author->id);

        // Attempting second amendment should fail
        $this->expectException(\LogicException::class);
        $this->service->amend($submitted->id, (string) $this->author->id);
    }
}
