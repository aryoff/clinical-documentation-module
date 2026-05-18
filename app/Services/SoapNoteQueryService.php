<?php

declare(strict_types=1);

namespace Modules\ClinicalDocumentation\Services;

use Illuminate\Support\Collection;
use Modules\ClinicalDocumentation\Models\SoapNote;
use Modules\ClinicalDocumentation\DTOs\VitalsData;

class SoapNoteQueryService
{
    /**
     * Get all SOAP notes for a specific registration.
     * Shows submitted/superseded notes, plus the current user's own drafts.
     */
    public function getForRegistration(string $registrationId, string $userId): Collection
    {
        return SoapNote::where('registration_id', $registrationId)
            ->where(function ($query) use ($userId) {
                $query->whereIn('status', ['submitted', 'superseded'])
                      ->orWhere(function ($q) use ($userId) {
                          $q->where('status', 'draft')
                            ->where('author_id', $userId);
                      });
            })
            ->orderBy('noted_at', 'asc')
            ->with(['author', 'amendedFrom'])
            ->get();
    }

    /**
     * Find the latest submitted SOAP note's vitals for a patient.
     */
    public function getLatestVitalsForPatient(string $patientId): ?VitalsData
    {
        $latestNote = SoapNote::whereHas('registration', function ($query) use ($patientId) {
                $query->where('patient_id', $patientId);
            })
            ->where('status', 'submitted')
            ->whereNotNull('vitals')
            ->orderBy('submitted_at', 'desc')
            ->first();

        return $latestNote ? $latestNote->vitals : null;
    }

    /**
     * Fetch the complete linear chronological amendment chain for a SOAP note.
     */
    public function getChain(string $noteId): array
    {
        $note = SoapNote::with('author')->findOrFail($noteId);
        
        // Find absolute root note in the chain
        $root = $note;
        while ($root->amended_from_id !== null) {
            $root = SoapNote::with('author')->findOrFail($root->amended_from_id);
        }

        // Chronologically collect descendants
        $chain = [$root];
        $current = $root;
        while (true) {
            $next = SoapNote::with('author')
                ->where('amended_from_id', $current->id)
                ->first();
            if (!$next) {
                break;
            }
            $chain[] = $next;
            $current = $next;
        }

        return $chain;
    }
}
