<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HROne setup parity HP-8i (SV-11, PF-6 … PF-11) — performance policy (type `performance`):
 *
 * - performance_period_reviews: the periodic reviews of an appraisal when the employee's performance policy runs review
 *   cycles shorter than the appraisal cycle (e.g. quarterly reviews in an annual cycle): one per period, with its window,
 *   opened by the schedule or by hand, rated by the reporting manager; they roll up into the appraisal rating only when
 *   the policy says how (average, last, custom weights);
 * - appraisals.recommendations: what reviewers recommend (training needs, promotion, increment, variable pay), offered
 *   to compensation and learning as read-only suggestions.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('performance_period_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('appraisal_id')->constrained('appraisals', 'id', 'period_reviews_appraisal_fk')->cascadeOnDelete();
            $table->foreignId('performance_cycle_id')->constrained('performance_cycles', 'id', 'period_reviews_cycle_fk')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees', 'id', 'period_reviews_employee_fk')->cascadeOnDelete();
            $table->foreignId('reviewer_id')->nullable()->comment('the employee who rates it (the reporting manager)')
                ->constrained('employees', 'id', 'period_reviews_reviewer_fk')->nullOnDelete();
            $table->unsignedSmallInteger('sequence');
            $table->date('period_start');
            $table->date('period_end');
            $table->date('window_opens_on');
            $table->date('window_closes_on');
            $table->boolean('manual')->default(false)->comment('opened by hand (policy: manually initiate review cycle)');
            $table->string('status', 16)->default('scheduled')->comment('scheduled|open|submitted|missed');
            $table->decimal('rating', 5, 2)->nullable();
            $table->text('comment')->nullable();
            $table->foreignId('opened_by')->nullable()->constrained('users', 'id', 'period_reviews_opened_by_fk')->nullOnDelete();
            $table->timestamp('opened_at')->nullable();
            $table->foreignId('submitted_by')->nullable()->constrained('users', 'id', 'period_reviews_submitted_by_fk')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamps();

            $table->unique(['appraisal_id', 'sequence'], 'period_reviews_appraisal_sequence_unique');
            $table->index(['tenant_id', 'status', 'window_opens_on'], 'period_reviews_status_window_index');
        });

        Schema::table('appraisals', function (Blueprint $table) {
            $table->json('recommendations')->nullable()->comment('HP-8i: training needs, promotion, increment, variable pay recommended by reviewers');
        });
    }

    public function down(): void
    {
        Schema::table('appraisals', function (Blueprint $table) {
            $table->dropColumn('recommendations');
        });
        Schema::dropIfExists('performance_period_reviews');
    }
};
