<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HROne setup parity P5 (Workflow): the order in which workflows on the same trigger are tried when only the first
 * match starts (workflow.first_match_only), and the acceptance of a resignation decided by its approval workflow.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workflows', function (Blueprint $table) {
            $table->unsignedSmallInteger('priority')->default(100)->after('status')->comment('lower is tried first when only the first match starts');
        });

        Schema::table('exit_cases', function (Blueprint $table) {
            $table->timestamp('resignation_accepted_at')->nullable()->after('initiated_on');
            $table->foreignId('resignation_accepted_by')->nullable()->after('resignation_accepted_at')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('exit_cases', function (Blueprint $table) {
            $table->dropConstrainedForeignId('resignation_accepted_by');
            $table->dropColumn('resignation_accepted_at');
        });
        Schema::table('workflows', function (Blueprint $table) {
            $table->dropColumn('priority');
        });
    }
};
