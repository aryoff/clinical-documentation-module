<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create("cd_archive_administration_entries", function (
            Blueprint $table,
        ): void {
            $table->uuid("id")->primary();
            $table->timestamp("created_at", 6)->useCurrent();
            $table
                ->timestamp("updated_at", 6)
                ->useCurrent()
                ->useCurrentOnUpdate();
            $table
                ->foreignUuid("package_id")
                ->constrained("cd_archive_packages")
                ->restrictOnDelete();
            $table
                ->foreignUuid("projection_id")
                ->constrained("cd_administration_projections")
                ->restrictOnDelete();
            $table->timestampTz("requested_at", 6)->useCurrent();
            // Immutable archive-intent references have no deleted state (module AGENTS.md).
            $table->unique(
                ["package_id", "projection_id"],
                "cd_archive_administration_entry_unique",
            );
        });
        if (DB::getDriverName() === "pgsql") {
            DB::unprepared(
                "CREATE TRIGGER cd_archive_administration_append_only BEFORE UPDATE OR DELETE ON cd_archive_administration_entries FOR EACH ROW EXECUTE FUNCTION cd_forbid_administration_rewrite()",
            );
        } elseif (DB::getDriverName() === "sqlite") {
            foreach (["UPDATE", "DELETE"] as $operation) {
                DB::unprepared(
                    "CREATE TRIGGER cd_archive_administration_no_{$operation} BEFORE {$operation} ON cd_archive_administration_entries BEGIN SELECT RAISE(ABORT, 'Archive administration entries are append-only'); END",
                );
            }
        } elseif (in_array(DB::getDriverName(), ["mysql", "mariadb"], true)) {
            foreach (["UPDATE", "DELETE"] as $operation) {
                $trigger_suffix = strtolower($operation);
                DB::unprepared(
                    "CREATE TRIGGER cd_archive_administration_no_{$trigger_suffix} BEFORE {$operation} ON cd_archive_administration_entries FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Archive administration entries are append-only'",
                );
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists("cd_archive_administration_entries");
    }
};
