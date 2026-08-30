<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * That the discharge summary was **explained**, and to whom.
 *
 * ADR 0004 here: a signed summary is the clinician's work, and handing it over
 * is a second act by a different person at a different time. Folding the two
 * into one signature is how a hospital ends up certifying that a patient had
 * their follow-up plan explained on the strength of a document they never saw.
 *
 * So the receipt is its own record with its own moment, its own recipient, and
 * the language and accessibility support the explanation actually used — an
 * explanation delivered in a language the recipient does not read is not one,
 * and the record has to be able to say which it was.
 *
 * Insert-only. A correction is a new receipt; nothing here is edited, because
 * "who was told what, and when" is exactly the question a complaint turns on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cd_discharge_receipts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('document_id')->index();
            $table->uuid('registration_id')->index();
            $table->uuid('patient_id')->index();

            // patient | representative. There is no third value: an
            // explanation given to nobody is not one, and a summary posted to
            // an empty house is not a receipt.
            $table->string('recipient_kind');
            $table->string('recipient_name');
            $table->string('recipient_relationship')->nullable();

            $table->string('language')->nullable();
            $table->string('interpreter_name')->nullable();
            $table->string('accessibility_support')->nullable();

            $table->text('explanation_summary');
            $table->timestamp('explained_at');
            $table->uuid('explained_by')->index();
            $table->string('explained_by_name');

            $table->timestamps();

            $table->index(['registration_id', 'explained_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cd_discharge_receipts');
    }
};
