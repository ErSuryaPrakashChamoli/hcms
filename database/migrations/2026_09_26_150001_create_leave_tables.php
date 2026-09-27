<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('code', 16);
            $table->string('category', 32)->default('paid');
            $table->boolean('is_paid')->default(true);
            $table->boolean('allow_half_day')->default(true);
            $table->boolean('is_encashable')->default(false);
            $table->string('applicable_gender', 32)->nullable();
            $table->string('colour', 16)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->string('status', 32)->default('active');
            $table->timestamps();

            $table->unique(['tenant_id', 'code']);
        });

        // Authoritative movements; balances are derived from these (§25 accrual, carry-forward, encashment, expiry).
        Schema::create('leave_ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('leave_type_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('period_year');
            $table->date('entry_date');
            $table->string('type', 32);
            $table->decimal('days', 6, 2);
            $table->string('accrual_key', 32)->nullable();
            $table->nullableMorphs('reference');
            $table->string('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'employee_id', 'leave_type_id', 'period_year'], 'leave_ledger_employee_type_year_idx');
            $table->unique(['employee_id', 'leave_type_id', 'accrual_key'], 'leave_ledger_accrual_unique');
        });

        // Cached view of the ledger per employee / type / leave year.
        Schema::create('leave_balances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('leave_type_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('period_year');
            $table->decimal('opening', 6, 2)->default(0);
            $table->decimal('accrued', 6, 2)->default(0);
            $table->decimal('adjusted', 6, 2)->default(0);
            $table->decimal('used', 6, 2)->default(0);
            $table->decimal('pending', 6, 2)->default(0);
            $table->decimal('encashed', 6, 2)->default(0);
            $table->decimal('lapsed', 6, 2)->default(0);
            $table->decimal('closing', 6, 2)->default(0);
            $table->timestamp('computed_at')->nullable();
            $table->timestamps();

            $table->unique(['employee_id', 'leave_type_id', 'period_year'], 'leave_balances_employee_type_year_unique');
        });

        Schema::create('leave_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('leave_type_id')->constrained()->restrictOnDelete();
            $table->date('from_date');
            $table->date('to_date');
            $table->string('from_session', 8)->default('full');
            $table->string('to_session', 8)->default('full');
            $table->decimal('days', 6, 2);
            $table->json('dates')->nullable();
            $table->string('reason');
            $table->string('status', 32)->default('pending');
            $table->foreignId('document_id')->nullable()->constrained('employee_documents')->nullOnDelete();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('review_note')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status', 'from_date']);
            $table->index(['employee_id', 'from_date', 'to_date']);
        });

        Schema::create('leave_encashments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('leave_type_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('period_year');
            $table->decimal('days', 6, 2);
            $table->string('status', 32)->default('pending');
            $table->string('reason')->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('review_note')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
        });

        Schema::table('attendance_records', function (Blueprint $table) {
            $table->foreignId('leave_request_id')->nullable()->after('shift_id')->constrained()->nullOnDelete();
            $table->boolean('is_half_day_leave')->default(false)->after('is_regularised');
        });
    }

    public function down(): void
    {
        Schema::table('attendance_records', function (Blueprint $table) {
            $table->dropConstrainedForeignId('leave_request_id');
            $table->dropColumn('is_half_day_leave');
        });
        Schema::dropIfExists('leave_encashments');
        Schema::dropIfExists('leave_requests');
        Schema::dropIfExists('leave_balances');
        Schema::dropIfExists('leave_ledger_entries');
        Schema::dropIfExists('leave_types');
    }
};
