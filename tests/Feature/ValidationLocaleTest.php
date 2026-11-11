<?php

declare(strict_types=1);

namespace Modules\ClinicalDocumentation\Tests\Feature;

use App\Models\User;
use Modules\ClinicalDocumentation\Tests\Support\CredentialsClinicalActors;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class ValidationLocaleTest extends TestCase
{
    use CredentialsClinicalActors;

    #[
        TestWith([
            "en",
            "The emergency reason field must not be greater than 2000 characters.",
        ]),
    ]
    #[
        TestWith([
            "id",
            "Kolom alasan darurat tidak boleh lebih besar dari 2000 karakter.",
        ]),
    ]
    public function test_break_glass_validation_names_the_localized_emergency_reason(
        string $locale,
        string $message,
    ): void {
        $user = User::factory()->create();
        $user->givePermissionTo("clinicaldocumentation.records.break-glass");

        $this->actingAs($user)
            ->withLocale($locale)
            ->postJson(route("clinicaldocumentation.break-glass", "missing"), [
                "reason" => str_repeat("a", 2001),
            ])
            ->assertUnprocessable()
            ->assertJsonPath("errors.reason.0", $message);
    }
    public function test_draft_and_external_evidence_validation_uses_the_request_locale(): void
    {
        $user = User::factory()->create();
        $this->grantClinicalAbility(
            $user,
            "clinicaldocumentation.documents.author",
        );
        $user->givePermissionTo(
            "clinicaldocumentation.records.stage-external-evidence",
        );
        $this->actingAs($user)->withLocale("id");

        $this->postJson(route("clinicaldocumentation.store"), [])
            ->assertUnprocessable()
            ->assertJsonPath(
                "errors.template_version.0",
                "Kolom versi templat wajib diisi.",
            );
        $this->postJson(
            route("clinicaldocumentation.presented-external-evidence.store"),
            [],
        )
            ->assertUnprocessable()
            ->assertJsonPath("errors.claim.0", "Kolom pernyataan wajib diisi.");
    }
}
