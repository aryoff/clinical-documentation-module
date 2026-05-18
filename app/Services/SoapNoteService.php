<?php

declare(strict_types=1);

namespace Modules\ClinicalDocumentation\Services;

use Illuminate\Support\Facades\DB;
use Modules\ClinicalDocumentation\Models\SoapNote;
use Modules\HospitalCore\Models\Registration;
use Modules\HospitalCore\Services\DiagnosisService;
use Modules\HospitalCore\Services\TimelineService;

class SoapNoteService
{
    /**
     * Create a new draft SOAP note.
     */
    public function create(array $data, string $userId): SoapNote
    {
        $registration = Registration::findOrFail($data['registration_id']);

        if (!in_array($registration->type, ['outpatient', 'inpatient', 'emergency'], true)) {
            throw new \InvalidArgumentException("The registration type must be outpatient, inpatient, or emergency.");
        }

        if ($registration->status === 'cancelled') {
            throw new \InvalidArgumentException("Cannot create a clinical note for a cancelled registration.");
        }

        return SoapNote::create([
            'registration_id' => $registration->id,
            'author_id'       => $userId,
            'author_role'     => $data['author_role'] ?? 'doctor',
            'status'          => 'draft',
            'subjective'      => $data['subjective'] ?? null,
            'objective'       => $data['objective'] ?? null,
            'assessment'      => $data['assessment'] ?? null,
            'plan'            => $data['plan'] ?? null,
            'vitals'          => $data['vitals'] ?? null,
            'noted_at'        => $data['noted_at'] ?? now(),
            'created_by'      => $userId,
        ]);
    }

    /**
     * Update an existing draft SOAP note.
     */
    public function update(string $noteId, array $data, string $userId): SoapNote
    {
        $note = SoapNote::findOrFail($noteId);

        if ($note->status !== 'draft') {
            throw new \LogicException("Only draft clinical notes can be edited.");
        }

        if ($note->author_id !== $userId) {
            throw new \Illuminate\Auth\Access\AuthorizationException("Only the author can update this draft.");
        }

        $note->update(array_intersect_key($data, array_flip([
            'subjective',
            'objective',
            'assessment',
            'plan',
            'vitals',
            'noted_at',
        ])));

        return $note;
    }

    /**
     * Submit a SOAP note, finalizing it and pushing diagnoses and timeline events.
     */
    public function submit(string $noteId, array $diagnoses, string $userId): SoapNote
    {
        return DB::transaction(function () use ($noteId, $diagnoses, $userId) {
            $note = SoapNote::findOrFail($noteId);

            if ($note->status !== 'draft') {
                throw new \LogicException("Only draft clinical notes can be submitted.");
            }

            if ($note->author_id !== $userId) {
                throw new \Illuminate\Auth\Access\AuthorizationException("Only the author can submit this note.");
            }

            // At least one SOAP content block must be filled out
            if (empty(trim($note->subjective ?? '')) &&
                empty(trim($note->objective ?? '')) &&
                empty(trim($note->assessment ?? '')) &&
                empty(trim($note->plan ?? ''))
            ) {
                throw new \InvalidArgumentException("A clinical SOAP note cannot be completely empty on submission.");
            }

            // Update status and timestamp
            $note->status = 'submitted';
            $note->submitted_at = now();
            $note->save();

            $registration = $note->registration;
            $patient = $registration->patient;

            // Push diagnoses to HospitalCore's visit diagnoses
            $diagnosisService = app(DiagnosisService::class);
            foreach ($diagnoses as $diag) {
                // $diag contains: icd_code_id, diagnosis_type ('primary' or 'secondary'), notes
                $diagnosisService->recordDiagnosis(
                    $registration->id,
                    $patient->id,
                    $diag['icd_code_id'],
                    $diag['diagnosis_type'] ?? 'primary',
                    $diag['notes'] ?? null,
                    $userId
                );
            }

            // If this is an amendment, transition the source note to superseded
            if ($note->amended_from_id) {
                $sourceNote = SoapNote::findOrFail($note->amended_from_id);
                if ($sourceNote->status !== 'submitted') {
                    throw new \LogicException("The parent note of an amendment must be currently in 'submitted' status.");
                }
                $sourceNote->status = 'superseded';
                $sourceNote->save();
            }

            // Write custom timeline events via TimelineService
            $timelineService = app(TimelineService::class);
            $timelineService->push(
                $patient->id,
                $registration->id,
                'ClinicalDocumentation',
                'soap_note',
                sprintf("Clinical SOAP Note Submitted by %s (%s)", $note->author->name, ucfirst($note->author_role)),
                $note->id
            );

            return $note;
        });
    }

    /**
     * Create an amendment draft note from a submitted note.
     */
    public function amend(string $sourceNoteId, string $userId): SoapNote
    {
        return DB::transaction(function () use ($sourceNoteId, $userId) {
            $sourceNote = SoapNote::findOrFail($sourceNoteId);

            if ($sourceNote->status !== 'submitted') {
                throw new \LogicException("Only finalized (submitted) clinical notes can be amended.");
            }

            // Ensure this note has not already been amended (enforcing linear chains)
            $existingAmendment = SoapNote::where('amended_from_id', $sourceNoteId)->exists();
            if ($existingAmendment) {
                throw new \LogicException("This clinical note has already been amended. Multi-branching amendments are prohibited.");
            }

            // Prefill draft note with the source note's data
            return SoapNote::create([
                'registration_id' => $sourceNote->registration_id,
                'author_id'       => $userId,
                'author_role'     => $sourceNote->author_role,
                'status'          => 'draft',
                'amended_from_id' => $sourceNote->id,
                'subjective'      => $sourceNote->subjective,
                'objective'       => $sourceNote->objective,
                'assessment'      => $sourceNote->assessment,
                'plan'            => $sourceNote->plan,
                'vitals'          => $sourceNote->vitals ? $sourceNote->vitals->toArray() : null,
                'noted_at'        => now(),
                'created_by'      => $userId,
            ]);
        });
    }
}
