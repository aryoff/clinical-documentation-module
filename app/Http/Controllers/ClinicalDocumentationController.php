<?php

declare(strict_types=1);

namespace Modules\ClinicalDocumentation\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;
use Modules\ClinicalDocumentation\Models\SoapNote;
use Modules\ClinicalDocumentation\Services\SoapNoteService;
use Modules\ClinicalDocumentation\Services\SoapNoteQueryService;
use Modules\HospitalCore\Models\Registration;
use Modules\HospitalCore\Models\IcdCode;
use Modules\MedicalRecords\Models\PatientDocument;
use Modules\MedicalRecords\Models\AccessLog;

class ClinicalDocumentationController extends Controller
{
    protected SoapNoteService $soapNoteService;
    protected SoapNoteQueryService $soapNoteQueryService;

    public function __construct(SoapNoteService $soapNoteService, SoapNoteQueryService $soapNoteQueryService)
    {
        $this->soapNoteService = $soapNoteService;
        $this->soapNoteQueryService = $soapNoteQueryService;
    }

    /**
     * Display a listing of the registrations needing documentation.
     */
    public function index(Request $request): Response
    {
        $registrations = Registration::with(['patient', 'doctor', 'department'])
            ->orderBy('registered_at', 'desc')
            ->paginate(10);

        return Inertia::render('ClinicalDocumentation::Index', [
            'registrations' => $registrations,
        ]);
    }

    /**
     * Show the form for creating a new SOAP note.
     */
    public function create(Request $request): Response|RedirectResponse
    {
        $registrationId = $request->query('registration_id');

        if (!$registrationId) {
            return redirect()->route('clinicaldocumentation.index')
                ->with('error', 'Please select a patient registration first from the EMR Dashboard.');
        }

        $registration = Registration::with(['patient', 'doctor', 'department'])->find($registrationId);

        if (!$registration) {
            return redirect()->route('clinicaldocumentation.index')
                ->with('error', 'The selected registration was not found. Please choose a valid registration.');
        }

        // Fetch scanned diagnostic/lab/rad documents for this active registration
        $patientDocuments = PatientDocument::where('registration_id', $registrationId)->get();

        return Inertia::render('ClinicalDocumentation::Create', [
            'registration' => $registration,
            'patientDocuments' => $patientDocuments,
        ]);
    }

    /**
     * Store a newly created draft SOAP note.
     */
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'registration_id' => 'required|uuid',
            'author_role'     => 'nullable|string',
            'subjective'      => 'nullable|string',
            'objective'       => 'nullable|string',
            'assessment'      => 'nullable|string',
            'plan'            => 'nullable|string',
            'vitals'          => 'nullable|array',
            'noted_at'        => 'nullable|date',
        ]);

        $note = $this->soapNoteService->create($data, (string) Auth::id());

        return redirect()->route('clinicaldocumentation.edit', $note->id)
            ->with('success', 'Draft SOAP note created successfully.');
    }

    /**
     * Show the specified finalized/superseded SOAP note.
     */
    public function show(Request $request, string $id): Response
    {
        $note = SoapNote::with(['registration.patient', 'author', 'amendedFrom.author'])->findOrFail($id);
        $patient = $note->registration->patient;

        // Log-on-Read: compliance audit log
        AccessLog::create([
            'patient_id'   => $patient->id,
            'accessed_by'  => Auth::id(),
            'access_type'  => 'view',
            'resource'     => 'soap_note',
            'ip_address'   => $request->ip(),
            'accessed_at'  => now(),
        ]);

        // Get full amendment history chain
        $chain = $this->soapNoteQueryService->getChain($id);

        return Inertia::render('ClinicalDocumentation::Show', [
            'note' => $note,
            'chain' => $chain,
        ]);
    }

    /**
     * Show the form for editing an existing SOAP note draft.
     */
    public function edit(string $id): Response
    {
        $note = SoapNote::with(['registration.patient', 'registration.doctor', 'registration.department'])->findOrFail($id);

        if ($note->status !== 'draft') {
            return redirect()->route('clinicaldocumentation.show', $id)
                ->with('error', 'Finalized notes cannot be edited. Create an amendment instead.');
        }

        // Fetch scanned diagnostic/lab/rad documents for this active registration
        $patientDocuments = PatientDocument::where('registration_id', $note->registration_id)->get();

        return Inertia::render('ClinicalDocumentation::Edit', [
            'note' => $note,
            'registration' => $note->registration,
            'patientDocuments' => $patientDocuments,
        ]);
    }

    /**
     * Update the draft SOAP note.
     */
    public function update(Request $request, string $id): RedirectResponse
    {
        $data = $request->validate([
            'subjective' => 'nullable|string',
            'objective'  => 'nullable|string',
            'assessment' => 'nullable|string',
            'plan'       => 'nullable|string',
            'vitals'     => 'nullable|array',
            'noted_at'   => 'nullable|date',
        ]);

        $this->soapNoteService->update($id, $data, (string) Auth::id());

        return redirect()->back()->with('success', 'Draft SOAP note updated successfully.');
    }

    /**
     * Submit and finalize the SOAP note with diagnoses.
     */
    public function submit(Request $request, string $id): RedirectResponse
    {
        $data = $request->validate([
            'diagnoses' => 'present|array',
            'diagnoses.*.icd_code_id' => 'required|uuid',
            'diagnoses.*.diagnosis_type' => 'required|string|in:primary,secondary',
            'diagnoses.*.notes' => 'nullable|string',
        ]);

        $this->soapNoteService->submit($id, $data['diagnoses'], (string) Auth::id());

        return redirect()->route('clinicaldocumentation.show', $id)
            ->with('success', 'Clinical SOAP note submitted and locked successfully.');
    }

    /**
     * Create a new draft amendment from a finalized note.
     */
    public function amend(Request $request, string $id): RedirectResponse
    {
        $draft = $this->soapNoteService->amend($id, (string) Auth::id());

        return redirect()->route('clinicaldocumentation.edit', $draft->id)
            ->with('success', 'Draft amendment created. Prefilled with previous note content.');
    }

    /**
     * Search ICD codes JSON API.
     */
    public function searchIcd(Request $request): \Illuminate\Http\JsonResponse
    {
        $query = $request->query('q', '');
        $icdCodes = IcdCode::where('code', 'ilike', "%{$query}%")
            ->orWhere('description', 'ilike', "%{$query}%")
            ->limit(20)
            ->get();

        return response()->json($icdCodes);
    }
}
