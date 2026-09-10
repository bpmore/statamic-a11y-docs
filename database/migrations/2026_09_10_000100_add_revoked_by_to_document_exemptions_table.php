<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who withdrew an exemption, alongside when.
 *
 * The grant records `granted_by`; without the matching column a withdrawal is
 * an unattributed change to a record whose whole purpose is attribution.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_exemptions', function (Blueprint $table) {
            // Null when withdrawn from the command line, where there is no user.
            $table->string('revoked_by', 255)->nullable()->after('revoked_at');
        });
    }

    public function down(): void
    {
        Schema::table('document_exemptions', function (Blueprint $table) {
            $table->dropColumn('revoked_by');
        });
    }
};
