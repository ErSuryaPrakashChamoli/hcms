<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * HROne setup parity HP-8b — Performance general settings: when an appraisal's rating was published to the employee
 * (performance.rating_publish; appraisals finalized before this change count as published when they were finalized),
 * who started a one-on-one, and the feedback each side gives after a held one-on-one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appraisals', function (Blueprint $table) {
            $table->timestamp('published_at')->nullable();
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
        });
        // Employees saw finalized ratings at once until now: those appraisals are published as of their finalization.
        DB::table('appraisals')->whereNotNull('finalized_at')->whereNull('published_at')
            ->update(['published_at' => DB::raw('finalized_at'), 'published_by' => DB::raw('finalized_by')]);

        Schema::table('one_on_ones', function (Blueprint $table) {
            $table->foreignId('initiated_by')->nullable()->constrained('employees')->nullOnDelete();
        });

        Schema::create('one_on_one_feedback', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('one_on_one_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->string('role', 16)->comment('initiator|participant');
            $table->unsignedTinyInteger('rating')->nullable()->comment('1-5');
            $table->string('comment', 1000)->nullable();
            $table->timestamp('acknowledged_at');
            $table->timestamps();

            $table->unique(['one_on_one_id', 'employee_id'], 'oo_feedback_meeting_employee_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('one_on_one_feedback');
        Schema::table('one_on_ones', fn (Blueprint $table) => $table->dropConstrainedForeignId('initiated_by'));
        Schema::table('appraisals', function (Blueprint $table) {
            $table->dropConstrainedForeignId('published_by');
            $table->dropColumn('published_at');
        });
    }
};
