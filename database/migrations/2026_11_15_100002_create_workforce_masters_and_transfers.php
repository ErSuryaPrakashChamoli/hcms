<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HROne setup parity — Workforce: reason masters (separation, transfer, blacklist), clearance checklists mapped by the
 * exit policy, and transfer requests decided before the position changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workforce_reasons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 16)->comment('separation|transfer|blacklist');
            $table->string('name', 120);
            $table->boolean('for_employee')->default(true)->comment('offered to employees');
            $table->boolean('remark_required')->default(false);
            $table->string('status', 16)->default('active');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['tenant_id', 'kind', 'name'], 'wr_tenant_kind_name_unique');
        });

        Schema::create('clearance_checklists', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('description', 500)->nullable();
            $table->json('items')->comment('[{stage, item, due_days, mandatory}]');
            $table->string('status', 16)->default('active');
            $table->timestamps();
        });

        Schema::create('transfer_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->date('effective_date');
            $table->foreignId('workforce_reason_id')->nullable()->constrained()->nullOnDelete();
            $table->json('changes')->comment('position dimension => new id');
            $table->foreignId('new_manager_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->string('note', 1000)->nullable();
            $table->string('status', 16)->default('pending')->comment('pending|approved|rejected|cancelled');
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('review_note', 1000)->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status'], 'tr_tenant_status_idx');
        });

        Schema::table('exit_cases', function (Blueprint $table) {
            $table->foreignId('workforce_reason_id')->nullable()->after('reason')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('exit_cases', fn (Blueprint $table) => $table->dropConstrainedForeignId('workforce_reason_id'));
        Schema::dropIfExists('transfer_requests');
        Schema::dropIfExists('clearance_checklists');
        Schema::dropIfExists('workforce_reasons');
    }
};
