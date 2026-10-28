<?php

declare(strict_types=1);

namespace Modules\ClinicalDocumentation\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;
use Modules\ClinicalDocumentation\Http\Requests\DestroyClinicalRecordsRequest;
use Modules\ClinicalDocumentation\Http\Requests\ResumeDestructionRequest;
use Modules\ClinicalDocumentation\Http\Requests\ViewClinicalRecordRetentionRequest;
use Modules\ClinicalDocumentation\Models\Destruction;
use Modules\ClinicalDocumentation\Services\ClinicalRecordDestruction;
use Modules\ClinicalDocumentation\Services\DestructionEnablement;

final class ClinicalRecordDestructionController extends Controller
{
    public function index(ViewClinicalRecordRetentionRequest $request, ClinicalRecordDestruction $destruction, DestructionEnablement $enablement): Response
    {
        $destructions = Destruction::query()->with('items')->orderByDesc('started_at')->get()->map(fn (Destruction $record): array => [
            'id' => $record->id,
            'patient_id' => $record->patient_id,
            'reason' => $record->reason,
            'destroyed_by_name' => $record->destroyed_by_name,
            'started_at' => $record->started_at->toIso8601String(),
            'status' => $destruction->status($record),
            'items' => $record->items->map(fn ($item): array => [
                'custodian' => $item->custodian, 'ref_type' => $item->ref_type, 'status' => $item->status, 'failure' => $item->failure,
            ])->all(),
        ])->all();

        return Inertia::render('ClinicalDocumentation::Retention/Destructions', ['destructions' => $destructions, 'destruction_enabled' => $enablement->enabled()]);
    }

    public function destroy(DestroyClinicalRecordsRequest $request, string $patient, ClinicalRecordDestruction $destruction): RedirectResponse
    {
        $destruction->destroy($patient, $request->validated('reason'), $request->boolean('dependencies_acknowledged'), (string) $request->user()->id);

        return back();
    }

    public function resume(ResumeDestructionRequest $request, string $destructionId, ClinicalRecordDestruction $destruction): RedirectResponse
    {
        $destruction->resume($destructionId);

        return back();
    }
}
