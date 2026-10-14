<?php

declare(strict_types=1);

namespace Modules\ClinicalDocumentation\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controller;
use Modules\ClinicalDocumentation\Http\Requests\CorrectConsumableUseRequest;
use Modules\ClinicalDocumentation\Http\Requests\OrderConsumableRequest;
use Modules\ClinicalDocumentation\Http\Requests\RecordConsumableUseRequest;
use Modules\ClinicalDocumentation\Http\Requests\WasteConsumableRequest;
use Modules\ClinicalDocumentation\Services\ClinicalConsumableUsageService;

final class ClinicalConsumableController extends Controller
{
    public function __construct(
        private readonly ClinicalConsumableUsageService $usage,
    ) {}

    public function order(OrderConsumableRequest $request): RedirectResponse
    {
        $this->usage->order($request->validated(), $request->user());

        return back()->with("success", "Consumable order recorded.");
    }

    public function store(RecordConsumableUseRequest $request): RedirectResponse
    {
        $this->usage->record($request->validated(), $request->user());

        return back()->with("success", "Consumable use recorded.");
    }

    public function correct(
        CorrectConsumableUseRequest $request,
        string $use,
    ): RedirectResponse {
        $this->usage->correct(
            [...$request->validated(), "use_id" => $use],
            $request->user(),
        );

        return back()->with("success", "Consumable use corrected.");
    }

    public function waste(
        WasteConsumableRequest $request,
        string $use,
    ): RedirectResponse {
        $this->usage->waste(
            [...$request->validated(), "use_id" => $use],
            $request->user(),
        );

        return back()->with("success", "Consumable waste recorded.");
    }
}
