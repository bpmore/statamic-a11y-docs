<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Documents somebody has decided not to fix, and why.
 *
 * Append-only. Withdrawing an exemption sets `revoked_at` rather than deleting
 * the row: "exemptions with an audit trail" is only true if the trail survives
 * the exemption. The active exemption for an asset is the most recent row that
 * is neither revoked nor expired.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_exemptions', function (Blueprint $table) {
            $table->id();

            // Not unique: an asset can be exempted, have that withdrawn, and be
            // exempted again, and all three are part of the record.
            $table->string('asset_id', 512);

            // Required, and required to be meaningful. An exemption without a
            // reason is an untracked failure.
            $table->text('reason');

            // Null when granted from the command line, where there is no user.
            $table->string('granted_by', 255)->nullable();
            $table->timestamp('granted_at');
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();

            $table->timestamps();

            // "Is this asset currently exempt?", asked once per document by the
            // publish gate. asset_id is the leftmost column, so a lookup by
            // asset alone uses this too and needs no index of its own.
            $table->index(['asset_id', 'revoked_at', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_exemptions');
    }
};
