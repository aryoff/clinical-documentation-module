<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create("cd_consumable_events", function (Blueprint $table): void {
            $table->uuid("id")->primary();
            // The Consumable Audit Correlation shared with the custodian's movement.
            $table->uuid("use_id")->index();
            $table->string("type", 16);
            $table->uuid("idempotency_key")->unique();
            $table->string("request_hash", 64);
            $table->uuid("registration_id")->index();
            $table->uuid("patient_id")->nullable();
            // The custodian's movement is an opaque reference, never a foreign key.
            $table->uuid("movement_id")->nullable();
            $table->json("payload");
            $table->uuid("recorded_by");
            $table->string("recorded_by_name");
            // Append-only evidence has no deleted state, so no softDeletes (as cd_administration_projections).
            $table->timestampTz("created_at", 6)->useCurrent();
            // The audit pair the module standard asks for, in this table's own names: the subject is the
            // registration (there is no polymorphic subject_type) and the causer is recorded_by.
            $table->index(
                ["registration_id", DB::raw("created_at DESC")],
                "cd_consumable_registration_audit",
            );
            $table->index(
                ["recorded_by", DB::raw("created_at DESC")],
                "cd_consumable_causer_audit",
            );
        });
        // An order, a use and a correction each happen once; waste may repeat.
        DB::statement(
            "CREATE UNIQUE INDEX cd_consumable_event_once ON cd_consumable_events (use_id, type) WHERE type IN ('ordered', 'recorded', 'corrected')",
        );
        if (DB::getDriverName() === "pgsql") {
            DB::unprepared(
                <<<'SQL'
                CREATE OR REPLACE FUNCTION cd_forbid_consumable_rewrite() RETURNS trigger AS $$
                BEGIN
                    RAISE EXCEPTION 'Consumable usage events are append-only';
                END;
                $$ LANGUAGE plpgsql;
                CREATE TRIGGER cd_consumable_append_only
                BEFORE UPDATE OR DELETE ON cd_consumable_events
                FOR EACH ROW EXECUTE FUNCTION cd_forbid_consumable_rewrite();
                SQL
                ,
            );
        } elseif (DB::getDriverName() === "sqlite") {
            foreach (["UPDATE", "DELETE"] as $operation) {
                DB::unprepared(
                    "CREATE TRIGGER cd_consumable_no_{$operation} BEFORE {$operation} ON cd_consumable_events BEGIN SELECT RAISE(ABORT, 'Consumable usage events are append-only'); END",
                );
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists("cd_consumable_events");
        if (DB::getDriverName() === "pgsql") {
            DB::unprepared("DROP FUNCTION IF EXISTS cd_forbid_consumable_rewrite()");
        }
    }
};
