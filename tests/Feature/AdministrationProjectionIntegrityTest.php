<?php

declare(strict_types=1);

namespace Modules\ClinicalDocumentation\Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\ClinicalDocumentation\Models\AdministrationProjection;
use Modules\ClinicalDocumentation\Tests\Support\AdministrationFacts;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdministrationProjectionIntegrityTest extends TestCase
{
    use AdministrationFacts;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->requireAdministrationPublisher();
    }

    public static function rewriteAttempts(): array
    {
        return [
            ["model", "update"],
            ["model", "delete"],
            ["database", "update"],
            ["database", "delete"],
        ];
    }

    #[DataProvider("rewriteAttempts")]
    public function test_finalized_projection_refuses_updates_and_deletes(
        string $seam,
        string $operation,
    ): void {
        $fact = $this->administrationFact();
        event("eprescription.administration-clinical-fact-published", [$fact]);
        $this->expectException(
            $seam === "model" ? \LogicException::class : QueryException::class,
        );
        if ($seam === "model") {
            $projection = AdministrationProjection::findOrFail(
                $fact["fact_id"],
            );
            $operation === "update"
                ? $projection->update(["fact" => ["outcome" => "missed"]])
                : $projection->delete();
        } else {
            $query = DB::table("cd_administration_projections")->where(
                "id",
                $fact["fact_id"],
            );
            $operation === "update"
                ? $query->update([
                    "fact" => json_encode(["outcome" => "missed"]),
                ])
                : $query->delete();
        }
    }
}
