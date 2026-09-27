<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('letter_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('code', 32);
            $table->string('type', 32)->default('custom');
            $table->string('subject');
            $table->longText('body');
            $table->boolean('requires_approval')->default(false);
            $table->unsignedInteger('version')->default(1);
            $table->string('status', 32)->default('active');
            $table->timestamps();

            $table->unique(['tenant_id', 'code']);
        });

        Schema::create('letters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('number', 32);
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('letter_template_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 32);
            $table->string('subject');
            $table->longText('body');
            $table->json('context')->nullable();
            $table->string('status', 32)->default('draft');
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('issued_at')->nullable();
            $table->foreignId('document_id')->nullable()->constrained('employee_documents')->nullOnDelete();
            $table->text('review_note')->nullable();
            $table->string('source_type')->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'number']);
            $table->index(['tenant_id', 'employee_id']);
        });

        Schema::create('exit_cases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('number', 32);
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->string('type', 32);
            $table->text('reason')->nullable();
            $table->string('status', 32)->default('initiated');
            $table->foreignId('initiated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->date('initiated_on');
            $table->date('resignation_date')->nullable();
            $table->unsignedSmallInteger('notice_days')->default(0);
            $table->date('notice_end_date')->nullable();
            $table->date('last_working_day');
            $table->foreignId('manager_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->foreignId('knowledge_transfer_to')->nullable()->constrained('employees')->nullOnDelete();
            $table->text('knowledge_transfer_notes')->nullable();
            $table->boolean('is_rehire_eligible')->default(true);
            $table->text('notes')->nullable();
            $table->foreignId('workflow_instance_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('clearance_started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('alumni_created_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'number']);
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('exit_clearances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('exit_case_id')->constrained()->cascadeOnDelete();
            $table->string('stage', 32);
            $table->string('name');
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('owner_role_id')->nullable()->constrained('roles')->nullOnDelete();
            $table->json('items')->nullable();
            $table->string('status', 32)->default('pending');
            $table->text('remarks')->nullable();
            $table->decimal('recoverable_amount', 14, 2)->default(0);
            $table->foreignId('cleared_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cleared_at')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['exit_case_id', 'stage']);
        });

        Schema::create('exit_interviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('exit_case_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('conducted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('conducted_at')->nullable();
            $table->string('reason_for_leaving', 32)->nullable();
            $table->json('ratings')->nullable();
            $table->boolean('would_recommend')->nullable();
            $table->boolean('would_rejoin')->nullable();
            $table->text('liked_most')->nullable();
            $table->text('suggestions')->nullable();
            $table->string('source', 16)->default('employee');
            $table->string('status', 16)->default('draft');
            $table->timestamps();
        });

        Schema::create('final_settlements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('exit_case_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->string('status', 32)->default('draft');
            $table->decimal('total_earnings', 14, 2)->default(0);
            $table->decimal('total_deductions', 14, 2)->default(0);
            $table->decimal('net_amount', 14, 2)->default(0);
            $table->json('inputs')->nullable();
            $table->timestamp('calculated_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->string('payment_reference')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('final_settlement_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('final_settlement_id')->constrained()->cascadeOnDelete();
            $table->string('type', 16);
            $table->string('code', 32);
            $table->string('name');
            $table->decimal('amount', 14, 2);
            $table->json('basis')->nullable();
            $table->string('source', 16)->default('auto');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('alumni_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('exit_case_id')->nullable()->constrained()->nullOnDelete();
            $table->string('personal_email')->nullable();
            $table->string('phone', 32)->nullable();
            $table->string('last_designation')->nullable();
            $table->string('last_department')->nullable();
            $table->date('joined_on')->nullable();
            $table->date('exited_on');
            $table->string('exit_type', 32)->nullable();
            $table->boolean('is_rehire_eligible')->default(true);
            $table->boolean('portal_enabled')->default(true);
            $table->boolean('consent_to_contact')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('alumni_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('number', 32);
            $table->foreignId('alumni_profile_id')->constrained()->cascadeOnDelete();
            $table->string('type', 32);
            $table->text('details')->nullable();
            $table->string('status', 32)->default('submitted');
            $table->foreignId('handled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('letter_id')->nullable()->constrained()->nullOnDelete();
            $table->text('response')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'number']);
        });
    }

    public function down(): void
    {
        foreach (['alumni_requests', 'alumni_profiles', 'final_settlement_lines', 'final_settlements', 'exit_interviews', 'exit_clearances', 'exit_cases', 'letters', 'letter_templates'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
