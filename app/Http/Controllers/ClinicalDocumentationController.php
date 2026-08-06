<?php

declare(strict_types=1);

namespace Modules\ClinicalDocumentation\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\ClinicalDocumentation\Contracts\ActiveClinicalRecordContract;
use Modules\ClinicalDocumentation\Models\ClinicalDocument;

class ClinicalDocumentationController extends Controller
{
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

    public function show(Request $request, string $id): Response
    {
        $document = $this->records->readDocument($id, (string) $request->user()->id, 'clinical-record-review');

        return Inertia::render('ClinicalDocumentation::Show', ['document' => $document]);
    }

    public function edit(Request $request, string $id): Response|RedirectResponse
    {
        $document = ClinicalDocument::query()->whereKey($id)->where('author_id', (string) $request->user()->id)->firstOrFail();
        if ($document->status !== 'draft') {
            return redirect()->route('clinicaldocumentation.show', $id)
                ->with('error', 'Signed clinical documents are immutable; create an addendum.');
        }

        return Inertia::render('ClinicalDocumentation::Edit', ['document' => $document]);
    }

    public function update(Request $request, string $id): RedirectResponse
    {
        $changes = $request->validate([
            'payload' => ['sometimes', 'array'],
            'encountered_at' => ['sometimes', 'date'],
        ]);
        $this->records->updateDraft($id, $changes, (string) $request->user()->id);

        return back()->with('success', 'Clinical document draft updated.');
    }

    public function submit(Request $request, string $id): RedirectResponse
    {
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

    public function destroy(string $id): never
    {
        abort(405, 'Clinical documents are never deleted.');
    }
}
