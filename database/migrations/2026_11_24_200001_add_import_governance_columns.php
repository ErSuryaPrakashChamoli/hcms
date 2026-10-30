<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HROne setup parity P2 (Data import → Import approval / Recent imports): when a run started (set as the upload moves
 * to importing) and where the upload came from (the admin screens today; the API later). Existing rows came from the
 * admin screens.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employee_imports', function (Blueprint $table) {
            $table->timestamp('run_started_at')->nullable()->after('approved_at');
            $table->string('source', 8)->default('ui')->after('type')->comment('ui|api');
            $table->index(['tenant_id', 'created_at'], 'employee_imports_tenant_created_idx');
        });
    }

    public function down(): void
    {
        Schema::table('employee_imports', function (Blueprint $table) {
            $table->dropIndex('employee_imports_tenant_created_idx');
            $table->dropColumn(['run_started_at', 'source']);
        });
    }
};
