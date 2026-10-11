<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        Schema::create("cd_administration_projections", function (
            Blueprint $table,
        ): void {
            $table->uuid("id")->primary();
            $table->timestamp("created_at", 6)->useCurrent();
            $table
                ->timestamp("updated_at", 6)
                ->useCurrent()
                ->useCurrentOnUpdate();
            $table->uuid("patient_id")->index();
            $table->uuid("registration_id")->index();
            $table->string("source_reference");
            $table->string("type", 32);
            $table->json("fact");
            $table->timestampTz("occurred_at", 6);
            $table->timestampTz("received_at", 6)->useCurrent();
            // Insert-only source evidence has no deleted state (module AGENTS.md).
            $table->unique(
                ["source_reference", "type"],
                "cd_administration_source_type_unique",
            );
            $table->index(["patient_id", "occurred_at"]);
        });
        if (DB::getDriverName() === "pgsql") {
            DB::unprepared(
                <<<'SQL'
                CREATE OR REPLACE FUNCTION cd_forbid_administration_rewrite() RETURNS trigger AS $$
                BEGIN
                    RAISE EXCEPTION 'Administration projections are append-only';
                END;
                $$ LANGUAGE plpgsql;
                CREATE TRIGGER cd_administration_append_only
                BEFORE UPDATE OR DELETE ON cd_administration_projections
                FOR EACH ROW EXECUTE FUNCTION cd_forbid_administration_rewrite();
                SQL
                ,
            );
        } elseif (DB::getDriverName() === "sqlite") {
            foreach (["UPDATE", "DELETE"] as $operation) {
                DB::unprepared(
                    "CREATE TRIGGER cd_administration_no_{$operation} BEFORE {$operation} ON cd_administration_projections BEGIN SELECT RAISE(ABORT, 'Administration projections are append-only'); END",
                );
            }
        } elseif (in_array(DB::getDriverName(), ["mysql", "mariadb"], true)) {
            foreach (["UPDATE", "DELETE"] as $operation) {
                $trigger_suffix = strtolower($operation);
                DB::unprepared(
                    "CREATE TRIGGER cd_administration_no_{$trigger_suffix} BEFORE {$operation} ON cd_administration_projections FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Administration projections are append-only'",
                );
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists("cd_administration_projections");
        if (DB::getDriverName() === "pgsql") {
            DB::unprepared(
                "DROP FUNCTION IF EXISTS cd_forbid_administration_rewrite()",
            );
        }
    }
};
