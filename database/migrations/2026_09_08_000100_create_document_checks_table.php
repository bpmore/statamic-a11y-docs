<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per asset: the latest result of checking it.
 *
 * Deliberately not a history. Hash-based caching means the interesting question
 * is always "has this file changed since we last looked", which is answered by
 * comparing one stored hash — and a 1,240-document library re-scanned nightly
 * would otherwise accumulate half a million rows nobody reads.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_checks', function (Blueprint $table) {
            $table->id();

            // Statamic identifies an asset as "container::path", not as a
            // number. 512 characters keeps the unique index inside InnoDB's
            // 3072-byte key limit at utf8mb4 with room to spare.
            $table->string('asset_id', 512)->unique();
            $table->string('container', 128)->index();
            $table->string('path', 1024);

            $table->string('format', 8);

            // The whole point of the caching: re-check only when this changes.
            // Null when the document was never read — a file over the size cap
            // is skipped without downloading it, so there is nothing to hash,
            // and such a row is re-evaluated every run. That costs one size
            // lookup, which is the point.
            $table->string('file_hash', 64)->nullable()->index();
            $table->unsignedBigInteger('file_size');
            $table->unsignedInteger('page_count')->nullable();

            // Null on skipped and unsupported results, where nothing ran. A
            // report that names an engine that never executed is worse than one
            // that admits none did.
            $table->string('engine', 32)->nullable();
            $table->string('engine_version', 64)->nullable();

            // pass | fail | error | skipped | unsupported
            $table->string('status', 16)->index();

            $table->timestamp('checked_at');
            $table->unsignedInteger('duration_ms')->nullable();

            // Why an error, skipped or unsupported result came out that way,
            // in words that go in front of a person.
            $table->text('error')->nullable();

            // Rules that could not be evaluated on this document, as
            // [{"rule_id": ..., "reason": ...}]. An encrypted PDF reads its
            // title as noise rather than as absent, so the title rule has to be
            // able to report that it could not look — which is not the same as
            // reporting that nothing was found.
            $table->json('unchecked')->nullable();

            $table->timestamps();

            // The dashboard's headline counts: how many of each format are in
            // each state. Also serves a lookup by format alone, which is the
            // leftmost column, so no separate index for it.
            $table->index(['format', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_checks');
    }
};
