<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2: additive attendance foundation — raw-punch lifecycle and DB-level idempotency, shift
 * timezone and breaks, calculation basis/version and scheduled minutes on records, overtime review,
 * regularisation snapshots, punch imports through the staging tables.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_punches', function (Blueprint $table) {
            if (! Schema::hasColumn('attendance_punches', 'source_type')) {
                $table->string('source_type', 16)->default('manual')->after('source')->comment('biometric_device|mobile|web|api|manual|import');
            }
            if (! Schema::hasColumn('attendance_punches', 'source_timezone')) {
                $table->string('source_timezone', 48)->nullable()->after('source_type');
            }
            if (! Schema::hasColumn('attendance_punches', 'fingerprint')) {
                $table->char('fingerprint', 64)->nullable()->after('external_id');
            }
            if (! Schema::hasColumn('attendance_punches', 'received_at')) {
                $table->timestamp('received_at')->nullable()->after('payload');
            }
            if (! Schema::hasColumn('attendance_punches', 'processing_status')) {
                $table->string('processing_status', 16)->default('received')->after('received_at')->comment('received|normalized|processed|failed|ignored');
            }
            if (! Schema::hasColumn('attendance_punches', 'processing_error')) {
                $table->text('processing_error')->nullable()->after('processing_status');
            }
            if (! Schema::hasColumn('attendance_punches', 'processed_at')) {
                $table->timestamp('processed_at')->nullable()->after('processing_error');
            }
            if (! Schema::hasColumn('attendance_punches', 'correlation_id')) {
                $table->string('correlation_id', 64)->nullable()->after('processed_at');
            }
        });

        // Unknown-employee punches are retained as failed, retryable evidence.
        Schema::table('attendance_punches', function (Blueprint $table) {
            $table->foreignId('employee_id')->nullable()->change();
        });

        // Backfill fingerprints so the unique index can be created on existing rows.
        foreach (DB::table('attendance_punches')->whereNull('fingerprint')->orderBy('id')->cursor() as $punch) {
            $basis = $punch->attendance_device_id && $punch->external_id
                ? "device:{$punch->attendance_device_id}:{$punch->external_id}"
                : "employee:{$punch->employee_id}:".substr((string) $punch->punched_at, 0, 16).":{$punch->direction}";
            DB::table('attendance_punches')->where('id', $punch->id)->update(['fingerprint' => hash('sha256', $punch->tenant_id.'|'.$basis), 'received_at' => $punch->created_at, 'processing_status' => 'processed']);
        }

        Schema::table('attendance_punches', function (Blueprint $table) {
            $table->unique(['tenant_id', 'fingerprint'], 'attendance_punches_fingerprint_unique');
            $table->index(['tenant_id', 'punched_at'], 'attendance_punches_tenant_time_idx');
            $table->index(['tenant_id', 'processing_status'], 'attendance_punches_tenant_status_idx');
        });

        Schema::table('attendance_records', function (Blueprint $table) {
            $table->dateTime('scheduled_start')->nullable()->after('shift_id');
            $table->dateTime('scheduled_end')->nullable()->after('scheduled_start');
            $table->unsignedInteger('scheduled_minutes')->default(0)->after('scheduled_end');
            $table->unsignedInteger('break_minutes')->default(0)->after('worked_minutes');
            $table->string('overtime_status', 16)->default('none')->after('overtime_approved_minutes')->comment('none|pending|approved|rejected');
            $table->string('overtime_review_note')->nullable()->after('overtime_status');
            $table->foreignId('overtime_reviewed_by')->nullable()->after('overtime_review_note')->constrained('users')->nullOnDelete();
            $table->timestamp('overtime_reviewed_at')->nullable()->after('overtime_reviewed_by');
            $table->string('timezone', 48)->nullable()->after('holiday_name');
            $table->string('calculation_version', 32)->nullable()->after('timezone');
            $table->json('calculation_basis')->nullable()->after('calculation_version');
            $table->timestamp('finalized_at')->nullable()->after('is_locked');
            $table->index(['tenant_id', 'overtime_status'], 'attendance_records_ot_status_idx');
            $table->index(['tenant_id', 'employee_id', 'date'], 'attendance_records_tenant_emp_date_idx');
        });

        Schema::table('shifts', function (Blueprint $table) {
            $table->string('timezone', 48)->nullable()->after('crosses_midnight');
        });

        Schema::create('shift_breaks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('shift_id')->constrained()->cascadeOnDelete();
            $table->string('name', 64);
            $table->time('starts_at')->nullable();
            $table->time('ends_at')->nullable();
            $table->unsignedSmallInteger('duration_minutes');
            $table->boolean('is_paid')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['tenant_id', 'shift_id']);
        });

        Schema::table('attendance_regularisations', function (Blueprint $table) {
            $table->json('original_snapshot')->nullable()->after('review_note');
            $table->json('resulting_snapshot')->nullable()->after('original_snapshot');
            $table->timestamp('cancelled_at')->nullable()->after('resulting_snapshot');
        });

        Schema::table('employee_imports', function (Blueprint $table) {
            $table->string('type', 16)->default('employees')->after('tenant_id')->comment('employees|punches');
            $table->index(['tenant_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::table('employee_imports', fn (Blueprint $table) => $table->dropColumn('type'));
        Schema::table('attendance_regularisations', fn (Blueprint $table) => $table->dropColumn(['original_snapshot', 'resulting_snapshot', 'cancelled_at']));
        Schema::dropIfExists('shift_breaks');
        Schema::table('shifts', fn (Blueprint $table) => $table->dropColumn('timezone'));
        Schema::table('attendance_records', function (Blueprint $table) {
            $table->dropIndex('attendance_records_ot_status_idx');
            $table->dropIndex('attendance_records_tenant_emp_date_idx');
            $table->dropConstrainedForeignId('overtime_reviewed_by');
            $table->dropColumn(['scheduled_start', 'scheduled_end', 'scheduled_minutes', 'break_minutes', 'overtime_status', 'overtime_review_note', 'overtime_reviewed_at', 'timezone', 'calculation_version', 'calculation_basis', 'finalized_at']);
        });
        Schema::table('attendance_punches', function (Blueprint $table) {
            $table->dropUnique('attendance_punches_fingerprint_unique');
            $table->dropIndex('attendance_punches_tenant_time_idx');
            $table->dropIndex('attendance_punches_tenant_status_idx');
            $table->dropColumn(['source_type', 'source_timezone', 'fingerprint', 'received_at', 'processing_status', 'processing_error', 'processed_at', 'correlation_id']);
        });
    }
};
