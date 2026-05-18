<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('cd_soap_notes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('registration_id')->constrained('hc_registrations')->cascadeOnDelete();
            $table->foreignUuid('author_id')->constrained('users');
            $table->string('author_role'); // doctor, nurse
            $table->string('status')->default('draft'); // draft, submitted, superseded
            $table->uuid('amended_from_id')->nullable();
            
            $table->text('subjective')->nullable();
            $table->text('objective')->nullable();
            $table->text('assessment')->nullable();
            $table->text('plan')->nullable();
            
            $table->json('vitals')->nullable();
            
            $table->timestamp('noted_at');
            $table->timestamp('submitted_at')->nullable();
            
            $table->foreignUuid('created_by')->constrained('users');
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::table('cd_soap_notes', function (Blueprint $table) {
            $table->foreign('amended_from_id')
                ->references('id')
                ->on('cd_soap_notes')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('cd_soap_notes', function (Blueprint $table) {
            $table->dropForeign(['amended_from_id']);
        });

        Schema::dropIfExists('cd_soap_notes');
    }
};
