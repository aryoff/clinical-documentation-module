<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A source version is projected once, and an amendment names the version it supersedes as structured lineage. Additive: the table is
     * deployed. No producer of this evidence is deployed yet (Laboratory and Radiology are not), so no duplicate can exist for the unique key.
     */
    public function up(): void
    {
        Schema::table('cd_diagnostic_result_evidence', function (Blueprint $table): void {
            // The version's number at its source (1 for a first release), as the source states it.
            $table->unsignedInteger('source_version')->nullable()->after('result_reference_id');
            // The source version this one corrects. Kept as the source's own reference, so a correction delivered before the version it
            // corrects (or one that corrects a version never projected here) still carries its lineage; the link is read, never required.
            $table->uuid('supersedes_result_reference_id')->nullable()->after('source_version');
            $table->unique(['source_owner', 'result_reference_id'], 'cd_diagnostic_evidence_source_unique');
            $table->index(['source_owner', 'supersedes_result_reference_id'], 'cd_diagnostic_evidence_supersedes_idx');
        });
    }

    public function down(): void
    {
        Schema::table('cd_diagnostic_result_evidence', function (Blueprint $table): void {
            $table->dropIndex('cd_diagnostic_evidence_supersedes_idx');
            $table->dropUnique('cd_diagnostic_evidence_source_unique');
            $table->dropColumn(['source_version', 'supersedes_result_reference_id']);
        });
    }
};
