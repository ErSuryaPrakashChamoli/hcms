<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HROne parity PO-8 (matrix 25 §25.6.14 #1–#6; matrix 24 TX-8 row "Pay month of the encashment"):
 * - `leave_encashment_batches`: HR's "Encash leaves" runs — a leave type, the leave year, the pay month, as per the
 *   encashment rule or a set number of days, who was chosen, the reviewed lines, who processed and approved it and when,
 *   and the last unprocess. The encashments it creates point back at it.
 * - `leave_encashments.leave_encashment_batch_id`: the batch an encashment came from (null = a request). One encashment
 *   per employee and batch.
 * - `leave_encashments.pay_month` ('YYYY-MM'): the month whose pay run pays the encashment, or a later one when that month
 *   was missed; null (every existing row and every request by default) = the next pay run after approval, as before.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_encashment_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('leave_type_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('period_year')->comment('leave year the days are encashed from');
            $table->char('pay_month', 7)->nullable()->comment('YYYY-MM: the month that pays it (null = the next pay run)');
            $table->string('mode', 16)->comment('policy|specified');
            $table->decimal('specified_days', 6, 2)->nullable();
            $table->json('applicability')->comment('who was chosen: source and criteria');
            $table->json('plan')->nullable()->comment('reviewed lines [{employee_id, days}] and skipped [{employee_id, reason}]');
            $table->json('results')->nullable()->comment('outcome per employee once processed');
            $table->unsignedInteger('employee_count')->default(0);
            $table->unsignedInteger('encashed_count')->default(0);
            $table->decimal('encashed_days', 10, 2)->default(0);
            $table->string('status', 16)->default('pending')->comment('pending|processing|processed|rejected|withdrawn|unprocessed');
            $table->string('note', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->string('decision_note', 500)->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->foreignId('unprocessed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('unprocessed_at')->nullable();
            $table->string('unprocess_reason', 500)->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status'], 'leb_tenant_status_idx');
            $table->index(['tenant_id', 'pay_month'], 'leb_tenant_pay_month_idx');
        });

        Schema::table('leave_encashments', function (Blueprint $table) {
            $table->foreignId('leave_encashment_batch_id')->nullable()->constrained('leave_encashment_batches', indexName: 'le_batch_fk')->nullOnDelete();
            $table->char('pay_month', 7)->nullable();
            $table->unique(['leave_encashment_batch_id', 'employee_id'], 'le_batch_employee_unique');
        });
    }

    public function down(): void
    {
        Schema::table('leave_encashments', function (Blueprint $table) {
            // The foreign key first: MySQL may have let the unique key serve it.
            $table->dropForeign('le_batch_fk');
            $table->dropUnique('le_batch_employee_unique');
            $table->dropColumn(['leave_encashment_batch_id', 'pay_month']);
        });
        Schema::dropIfExists('leave_encashment_batches');
    }
};
