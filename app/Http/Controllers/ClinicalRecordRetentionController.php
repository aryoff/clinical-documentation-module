<?php

declare(strict_types=1);

namespace Modules\ClinicalDocumentation\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;
use Modules\ClinicalDocumentation\Http\Requests\LiftLegalHoldRequest;
use Modules\ClinicalDocumentation\Http\Requests\PlaceLegalHoldRequest;
use Modules\ClinicalDocumentation\Http\Requests\RecordRetentionExtensionRequest;
use Modules\ClinicalDocumentation\Http\Requests\RecordRetentionScheduleRequest;
use Modules\ClinicalDocumentation\Http\Requests\ViewClinicalRecordRetentionRequest;
use Modules\ClinicalDocumentation\Models\LegalHold;
use Modules\ClinicalDocumentation\Models\RetentionSchedule;
use Modules\ClinicalDocumentation\Services\ClinicalRecordRetention;
use Modules\ClinicalDocumentation\Services\ClinicalRecordRetentionReview;

final class ClinicalRecordRetentionController extends Controller
{
    public function index(ViewClinicalRecordRetentionRequest $request): Response
    {
        return Inertia::render('ClinicalDocumentation::Retention/Index', [
            'schedules' => RetentionSchedule::query()->orderByDesc('version')->get(['id', 'version', 'minimum_years', 'rationale', 'created_by_name', 'created_at']),
            'holds' => LegalHold::query()->whereNull('lifted_at')->orderBy('placed_at')->get(['id', 'patient_id', 'scope', 'record_refs', 'reason', 'placed_by_name', 'placed_at']),
            'floor_years' => ClinicalRecordRetention::FLOOR_YEARS,
            'destruction_enabled' => app(\Modules\ClinicalDocumentation\Services\DestructionEnablement::class)->enabled(),
        ]);
    }

    public function review(ViewClinicalRecordRetentionRequest $request, string $patient, ClinicalRecordRetentionReview $review): Response
    {
        return Inertia::render('ClinicalDocumentation::Retention/Review', ['review' => $review->assess($patient)]);
    }

    public function storeSchedule(RecordRetentionScheduleRequest $request, ClinicalRecordRetention $retention): RedirectResponse
    {
        $retention->recordSchedule((int) $request->validated('minimum_years'), $request->validated('rationale'), (string) $request->user()->id);

        return back();
    }

    public function placeHold(PlaceLegalHoldRequest $request, ClinicalRecordRetention $retention): RedirectResponse
    {
        $data = $request->validated();
        $retention->placeHold($data['patient_id'], $data['scope'], $data['record_refs'] ?? null, $data['reason'], (string) $request->user()->id);

        return back();
    }

    public function liftHold(LiftLegalHoldRequest $request, string $hold, ClinicalRecordRetention $retention): RedirectResponse
    {
        $retention->liftHold($hold, $request->validated('reason'), (string) $request->user()->id);

        return back();
    }

    public function storeExtension(RecordRetentionExtensionRequest $request, ClinicalRecordRetention $retention): RedirectResponse
    {
        $data = $request->validated();
        $retention->recordExtension($data['patient_id'], $data['retain_until'], $data['reason'], (string) $request->user()->id);

        return back();
    }
}
