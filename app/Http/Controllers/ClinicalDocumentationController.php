<?php

declare(strict_types=1);

namespace Modules\ClinicalDocumentation\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\ClinicalDocumentation\Contracts\ActiveClinicalRecordContract;
use Modules\ClinicalDocumentation\Http\Requests\RequestClinicalArchiveRequest;
use Modules\ClinicalDocumentation\Http\Requests\ViewClinicalAuditRequest;
use Modules\ClinicalDocumentation\Models\ClinicalAuditEvent;
use Modules\ClinicalDocumentation\Models\ClinicalDocument;

class ClinicalDocumentationController extends Controller
{
    private const IMMUTABLE_NOTICE = 'Signed clinical documents are immutable; create an addendum.';

    /** Flashed by a refused edit and rendered by `show` as the addendum prompt. */
    private const IMMUTABLE_NOTICE_KEY = 'immutabilityNotice';

    public function __construct(private readonly ActiveClinicalRecordContract $records) {}

    public function index(Request $request): Response
    {
        return Inertia::render('ClinicalDocumentation::Index', [
            'documents' => ClinicalDocument::query()
                ->where('author_id', (string) $request->user()->id)
                ->latest()
                ->paginate(10),
        ]);
    }

    public function create(Request $request): Response|RedirectResponse
    {
        if (!$request->query('handoff_id')) {
            return redirect()->route('clinicaldocumentation.index')
                ->with('error', 'Clinical authoring requires an accepted Clinical Handoff.');
        }

        return Inertia::render('ClinicalDocumentation::Create', ['handoffId' => $request->query('handoff_id')]);
    }

    public function store(Request $request): RedirectResponse
    {
        $command = $request->validate([
            'handoff_id' => ['required', 'uuid'],
            'template' => ['required', 'string'],
            'template_version' => ['required', 'string'],
            'encountered_at' => ['required', 'date'],
            'payload' => ['required', 'array'],
        ]);
        $document = $this->records->createDraft($command, (string) $request->user()->id);

        return redirect()->route('clinicaldocumentation.edit', $document['document_id'])
            ->with('success', 'Private clinical document draft created.');
    }

    public function show(Request $request, string $id): Response|RedirectResponse
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
            'canArchive' => $request->user()->can('clinicaldocumentation.archive.manage')
                && ClinicalDocument::query()->whereKey($id)->where('author_id', (string) $request->user()->id)->exists(),
        ]);
    }

    public function edit(Request $request, string $id): Response|RedirectResponse
    {
        $document = $this->authoredDocument($request, $id);
        if ($document->status !== 'draft') {
            return redirect()->route('clinicaldocumentation.show', $id)->with(self::IMMUTABLE_NOTICE_KEY, self::IMMUTABLE_NOTICE);
        }

        return Inertia::render('ClinicalDocumentation::Edit', ['document' => $document]);
    }

    public function update(Request $request, string $id): RedirectResponse
    {
        $changes = $request->validate([
            'payload' => ['sometimes', 'array'],
            'encountered_at' => ['sometimes', 'date'],
        ]);

        // The service refuses this too, but a signed record met at the HTTP
        // boundary deserves the addendum route rather than a server error.
        if ($this->authoredDocument($request, $id)->status !== 'draft') {
            return redirect()->route('clinicaldocumentation.show', $id)->with(self::IMMUTABLE_NOTICE_KEY, self::IMMUTABLE_NOTICE);
        }

        $this->records->updateDraft($id, $changes, (string) $request->user()->id);

        return back()->with('success', 'Clinical document draft updated.');
    }

    public function submit(Request $request, string $id): RedirectResponse
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

    public function amend(Request $request, string $id): RedirectResponse
    {
        $command = $request->validate([
            'reason' => ['required', 'string'],
            'payload' => ['required', 'array'],
            'encountered_at' => ['required', 'date'],
        ]);
        $command['document_id'] = $id;
        $addendum = $this->records->createAddendum($command, (string) $request->user()->id);
        $this->records->signAddendum($addendum['addendum_id'], (string) $request->user()->id);

        return redirect()->route('clinicaldocumentation.show', $id)
            ->with('success', 'Signed addendum recorded without changing the source document.');
    }

    public function breakGlassForm(string $id): Response
    {
        // Signed evidence only, matching what breakGlassRead will allow. Serving
        // the form for a draft would confirm that draft's existence to someone
        // with no right to know it.
        return Inertia::render('ClinicalDocumentation::BreakGlass', [
            'documentId' => $this->signedDocument($id)->id,
        ]);
    }

    public function breakGlass(Request $request, string $id): Response
    {
        $command = $request->validate(['reason' => ['required', 'string', 'max:2000']]);
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

    private function mayBreakGlassOn(Request $request, string $id): bool
    {
        // Break-Glass reaches signed evidence only; a private draft stays
        // private to its author even in an emergency.
        return $request->user()->can('clinicaldocumentation.records.break-glass')
            && ClinicalDocument::query()->whereKey($id)->where('status', 'signed')->exists();
    }

    private function signedDocument(string $id): ClinicalDocument
    {
        return ClinicalDocument::query()->whereKey($id)->where('status', 'signed')->firstOrFail();
    }
}
