<?php

declare(strict_types=1);

namespace Modules\ClinicalDocumentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\FileVaultService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\ClinicalDocumentation\Contracts\ActiveClinicalRecordContract;
use Modules\ClinicalDocumentation\Http\Requests\AmendClinicalDocumentRequest;
use Modules\ClinicalDocumentation\Http\Requests\BreakGlassFormRequest;
use Modules\ClinicalDocumentation\Http\Requests\BreakGlassRequest;
use Modules\ClinicalDocumentation\Http\Requests\CreateClinicalDocumentRequest;
use Modules\ClinicalDocumentation\Http\Requests\CreatePresentedExternalEvidenceRequest;
use Modules\ClinicalDocumentation\Http\Requests\EditClinicalDocumentRequest;
use Modules\ClinicalDocumentation\Http\Requests\IncorporatePresentedExternalEvidenceRequest;
use Modules\ClinicalDocumentation\Http\Requests\RequestClinicalArchiveRequest;
use Modules\ClinicalDocumentation\Http\Requests\ReviewPresentedExternalEvidenceRequest;
use Modules\ClinicalDocumentation\Http\Requests\SignClinicalDocumentRequest;
use Modules\ClinicalDocumentation\Http\Requests\StoreClinicalDocumentRequest;
use Modules\ClinicalDocumentation\Http\Requests\UpdateClinicalDocumentRequest;
use Modules\ClinicalDocumentation\Http\Requests\ViewClinicalDocumentRequest;
use Modules\ClinicalDocumentation\Http\Requests\ViewClinicalAuditRequest;
use Modules\ClinicalDocumentation\Http\Requests\ViewClinicalDocumentsRequest;
use Modules\ClinicalDocumentation\Http\Requests\StagePresentedExternalEvidenceRequest;
use Modules\ClinicalDocumentation\Http\Requests\ViewPresentedExternalEvidenceFileRequest;
use Modules\ClinicalDocumentation\Models\ClinicalAuditEvent;
use Modules\ClinicalDocumentation\Models\ClinicalDocument;
use Modules\ClinicalDocumentation\Services\PresentedExternalEvidenceService;

class ClinicalDocumentationController extends Controller
{
    private const IMMUTABLE_NOTICE = 'Signed clinical documents are immutable; create an addendum.';

    /** Flashed by a refused edit and rendered by `show` as the addendum prompt. */
    private const IMMUTABLE_NOTICE_KEY = 'immutabilityNotice';

    public function __construct(
        private readonly ActiveClinicalRecordContract $records,
        private readonly PresentedExternalEvidenceService $externalEvidence,
        private readonly FileVaultService $files,
    ) {}

    public function createPresentedExternalEvidence(CreatePresentedExternalEvidenceRequest $request): Response
    {
        $registrationId = (string) $request->query('registration_id');
        $registration = $this->externalEvidence->activeRegistration($registrationId);
        abort_unless($registration !== null, 404);

        return Inertia::render('ClinicalDocumentation::PresentedExternalEvidence/Create', [
            'registrationId' => $registrationId,
            'evidence' => $this->externalEvidence->unreviewedForRegistration(
                $registrationId,
                (string) $registration['patient_id'],
                $request->user(),
            ),
        ]);
    }

    public function storePresentedExternalEvidence(StagePresentedExternalEvidenceRequest $request): RedirectResponse
    {
        $registrationId = (string) $request->validated('registration_id');
        $this->externalEvidence->stage(
            $request->file('file'),
            $registrationId,
            (string) $request->validated('claim'),
            $request->user(),
        );

        return redirect()->route('clinicaldocumentation.presented-external-evidence.create', [
            'registration_id' => $registrationId,
        ])->with('success', 'Presented external evidence staged for clinician review.');
    }

    public function reviewPresentedExternalEvidence(ReviewPresentedExternalEvidenceRequest $request, string $id): RedirectResponse
    {
        $documentId = (string) $request->validated('document_id');
        $this->externalEvidence->review($id, $documentId, $request->user());

        return redirect()->route('clinicaldocumentation.edit', $documentId)
            ->with('success', 'Presented external evidence reviewed during clinical authoring.');
    }

    public function incorporatePresentedExternalEvidence(IncorporatePresentedExternalEvidenceRequest $request, string $id): RedirectResponse
    {
        $documentId = (string) $request->validated('document_id');
        $this->externalEvidence->incorporate($id, $documentId, $request->user());

        return redirect()->route('clinicaldocumentation.edit', $documentId)
            ->with('success', 'Incorporation decision recorded during clinical authoring.')
            ->with('presentedExternalEvidenceIncorporated', true);
    }

    public function filePresentedExternalEvidence(ViewPresentedExternalEvidenceFileRequest $request, string $id): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $file = $this->externalEvidence->fileFor($id, $request->user());
        $stream = $this->files->streamFileWithRange($file->disk, $file->file_path, $file->mime_type);

        return response()->stream($stream['stream'], $stream['status'], $stream['headers']);
    }

    public function index(ViewClinicalDocumentsRequest $request): Response
    {
        return Inertia::render('ClinicalDocumentation::Index', [
            'documents' => ClinicalDocument::query()
                ->where('author_id', (string) $request->user()->id)
                ->latest()
                ->paginate(10),
        ]);
    }

    public function create(CreateClinicalDocumentRequest $request): Response|RedirectResponse
    {
        if (!$request->query('handoff_id')) {
            return redirect()->route('clinicaldocumentation.index')
                ->with('error', 'Clinical authoring requires an accepted Clinical Handoff.');
        }

        return Inertia::render('ClinicalDocumentation::Create', ['handoffId' => $request->query('handoff_id')]);
    }

    public function store(StoreClinicalDocumentRequest $request): RedirectResponse
    {
        $command = $request->validated();
        $document = $this->records->createDraft($command, (string) $request->user()->id);

        return redirect()->route('clinicaldocumentation.edit', $document['document_id'])
            ->with('success', 'Private clinical document draft created.');
    }

    public function show(ViewClinicalDocumentRequest $request, string $id): Response|RedirectResponse
    {
        try {
            $document = $this->records->readDocument($id, (string) $request->user()->id, 'clinical-record-review');
        } catch (AuthorizationException $exception) {
            // A responder holding no treatment relationship is not simply
            // refused: the emergency path exists precisely for this moment, and
            // it stays reasoned and separately audited.
            if ($this->mayBreakGlassOn($request, $id)) {
                return redirect()->route('clinicaldocumentation.break-glass.create', $id)
                    ->with('error', 'You have no treatment relationship with this record. Emergency access requires a reasoned Break-Glass.');
            }

            throw $exception;
        }

        return Inertia::render('ClinicalDocumentation::Show', [
            'document' => $document,
            'immutabilityNotice' => $request->session()->get(self::IMMUTABLE_NOTICE_KEY),
            // The permission alone does not earn the button: archive custody is
            // author-scoped, so offering it to a non-author would only 403.
            'canArchive' => $request->mayRequestArchive()
                && ClinicalDocument::query()->whereKey($id)->where('author_id', (string) $request->user()->id)->exists(),
        ]);
    }

    public function edit(EditClinicalDocumentRequest $request, string $id): Response|RedirectResponse
    {
        $document = $this->authoredDocument($request, $id);
        if ($document->status !== 'draft') {
            return redirect()->route('clinicaldocumentation.show', $id)->with(self::IMMUTABLE_NOTICE_KEY, self::IMMUTABLE_NOTICE);
        }

        return Inertia::render('ClinicalDocumentation::Edit', [
            'document' => $document,
            'presentedExternalEvidence' => $this->externalEvidence->unreviewedForDocument($document, $request->user()),
            'reviewedExternalEvidence' => $this->externalEvidence->reviewedForDocument($document, $request->user()),
            'presentedExternalEvidenceIncorporated' => $request->session()->pull('presentedExternalEvidenceIncorporated', false),
            'clinicalDocumentDraftUpdated' => $request->session()->pull('clinicalDocumentDraftUpdated', false),
        ]);
    }

    public function update(UpdateClinicalDocumentRequest $request, string $id): RedirectResponse
    {
        $changes = $request->validated();

        // The service refuses this too, but a signed record met at the HTTP
        // boundary deserves the addendum route rather than a server error.
        if ($this->authoredDocument($request, $id)->status !== 'draft') {
            return redirect()->route('clinicaldocumentation.show', $id)->with(self::IMMUTABLE_NOTICE_KEY, self::IMMUTABLE_NOTICE);
        }

        $this->records->updateDraft($id, $changes, (string) $request->user()->id);

        return back()
            ->with('success', 'Clinical document draft updated.')
            ->with('clinicalDocumentDraftUpdated', true);
    }

    public function submit(SignClinicalDocumentRequest $request, string $id): RedirectResponse
    {
        // Signing is one click from the draft form, so the two ways it can
        // legitimately arrive wrong — an already-signed document in a stale tab
        // or a double submit, and an emptied payload — answer as refusals
        // rather than as the service's uncaught exceptions.
        $document = $this->authoredDocument($request, $id);
        if ($document->status !== 'draft') {
            return redirect()->route('clinicaldocumentation.show', $id)->with(self::IMMUTABLE_NOTICE_KEY, self::IMMUTABLE_NOTICE);
        }
        if ($document->payload === []) {
            return back()->withErrors(['payload' => 'A clinical document cannot be signed with an empty payload.']);
        }

        $this->records->signDocument($id, (string) $request->user()->id);

        return redirect()->route('clinicaldocumentation.show', $id)
            ->with('success', 'Clinical document signed and locked.');
    }

    public function amend(AmendClinicalDocumentRequest $request, string $id): RedirectResponse
    {
        $command = $request->validated();
        $command['document_id'] = $id;
        $addendum = $this->records->createAddendum($command, (string) $request->user()->id);
        $this->records->signAddendum($addendum['addendum_id'], (string) $request->user()->id);

        return redirect()->route('clinicaldocumentation.show', $id)
            ->with('success', 'Signed addendum recorded without changing the source document.');
    }

    public function breakGlassForm(BreakGlassFormRequest $request, string $id): Response
    {
        // Signed evidence only, matching what breakGlassRead will allow. Serving
        // the form for a draft would confirm that draft's existence to someone
        // with no right to know it.
        return Inertia::render('ClinicalDocumentation::BreakGlass', [
            'documentId' => $this->signedDocument($id)->id,
        ]);
    }

    public function breakGlass(BreakGlassRequest $request, string $id): Response
    {
        $command = $request->validated();
        $document = $this->records->breakGlassRead($id, (string) $request->user()->id, $command['reason']);

        // The emergency read is audited once. Inertia keeps page props in
        // history state, so without this the responder could press Back and
        // Forward to re-read the payload with no second audit event — exactly
        // the ongoing access Break-Glass is defined not to grant.
        Inertia::encryptHistory();

        return Inertia::render('ClinicalDocumentation::BreakGlass', [
            'documentId' => $id,
            'document' => $document,
        ]);
    }

    public function archive(RequestClinicalArchiveRequest $request, string $id): RedirectResponse
    {
        // Archive custody follows the contract's author scoping: the permission
        // is necessary, not sufficient. Checked here so a records custodian who
        // did not author the note is refused before the service raises.
        $document = $this->signedDocument($id);
        abort_unless($document->author_id === (string) $request->user()->id, 403, 'Only the author of a signed clinical document may request its archive package.');

        $package = $this->records->archiveDocument($id, (string) $request->user()->id);

        return redirect()->route('clinicaldocumentation.show', $id)
            ->with('success', "Archive package requested; custody is {$package['custody_state']}.");
    }

    public function audit(ViewClinicalAuditRequest $request): Response
    {
        $filters = $request->validated();

        $events = ClinicalAuditEvent::query()
            ->when($filters['document_id'] ?? null, fn ($query, string $documentId) => $query->where('document_id', $documentId))
            ->when($filters['patient_id'] ?? null, fn ($query, string $patientId) => $query->where('patient_id', $patientId))
            ->orderByDesc('occurred_at')
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('ClinicalDocumentation::Audit', [
            'events' => $events,
            'filters' => [
                'document_id' => $filters['document_id'] ?? null,
                'patient_id' => $filters['patient_id'] ?? null,
            ],
        ]);
    }

    public function destroy(string $id): never
    {
        abort(405, 'Clinical documents are never deleted.');
    }

    private function authoredDocument(Request $request, string $id): ClinicalDocument
    {
        return ClinicalDocument::query()
            ->whereKey($id)
            ->where('author_id', (string) $request->user()->id)
            ->firstOrFail();
    }

    private function mayBreakGlassOn(ViewClinicalDocumentRequest $request, string $id): bool
    {
        // Break-Glass reaches signed evidence only; a private draft stays
        // private to its author even in an emergency.
        return $request->mayBreakGlass()
            && ClinicalDocument::query()->whereKey($id)->where('status', 'signed')->exists();
    }

    private function signedDocument(string $id): ClinicalDocument
    {
        return ClinicalDocument::query()->whereKey($id)->where('status', 'signed')->firstOrFail();
    }
}
