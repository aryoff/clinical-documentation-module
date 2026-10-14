<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Consumable use is not clinical-record content: a diaper or a syringe is stock handling, recorded against the
 * Hospital Registration by HospitalCore. The recorder this table served was removed, so the table goes with it.
 * The create migration stays in history.
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable("cd_consumable_events") && DB::table("cd_consumable_events")->exists()) {
            // Append-only evidence is never dropped silently: export it, then remove it deliberately.
            throw new RuntimeException("cd_consumable_events holds consumable use evidence; export it before dropping the table.");
        }
        Schema::dropIfExists("cd_consumable_events");
        if (DB::getDriverName() === "pgsql") {
            DB::unprepared("DROP FUNCTION IF EXISTS cd_forbid_consumable_rewrite()");
        }
    }

    public function down(): void
    {
        // The recorder no longer exists, so there is nothing to rebuild the table for.
    }
};
