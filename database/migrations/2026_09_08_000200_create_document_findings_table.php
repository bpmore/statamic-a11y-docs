<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The individual problems found in one document.
 *
 * No timestamps: findings are regenerated wholesale every time their parent
 * check runs, so a created_at here would record when we last re-scanned rather
 * than when the problem appeared. The date that means something is the parent's
 * `checked_at`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_findings', function (Blueprint $table) {
            $table->id();

            $table->foreignId('check_id')
                ->constrained('document_checks')
                ->cascadeOnDelete();

            // "<format>.<rule_name>", e.g. pdf.not_tagged. Indexed because the
            // remediation queue filters by it: "show me every untagged PDF".
            $table->string('rule_id', 64)->index();

            // critical | serious | moderate | minor
            $table->string('severity', 16);

            $table->text('message');
            $table->string('help_url', 1024)->nullable();

            // {"page": 14, "element": "Figure"} — whichever of page, slide,
            // sheet and element apply to the format.
            $table->json('location')->nullable();

            // The two orders the queue is read in: worst first within one
            // document, and worst first across the whole library.
            $table->index(['check_id', 'severity']);
            $table->index(['severity', 'rule_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_findings');
    }
};
