<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SaaS.9 closure: tenant_deletion_steps.status was 24 characters, too short for `external_retention_required` (27). MySQL
 * refused the write (SQLite does not enforce lengths, so only the MySQL browser fixture caught it). Widened, additive.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_deletion_steps', function (Blueprint $table) {
            $table->string('status', 40)->change();
        });
    }

    public function down(): void
    {
        Schema::table('tenant_deletion_steps', function (Blueprint $table) {
            $table->string('status', 24)->change(); // only safe while no step is external_retention_required
        });
    }
};
