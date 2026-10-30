<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HROne setup parity HP-9c (Engage → Badges, Custom badges): the badges colleagues give one another (HROne's nine
 * system badges, plus the organisation's custom badges with their rules) and the recognitions ledger. A recognition is
 * never changed or deleted; HR may withdraw one (who, when, why), which takes it off the wall and the counts.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('badges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('code', 40);
            $table->string('name', 80);
            $table->string('description', 500)->nullable();
            $table->string('icon', 64)->default('heroicon-o-star');
            $table->string('tone', 16)->default('indigo');
            $table->boolean('is_system')->default(false);
            $table->string('status', 16)->default('active');
            $table->unsignedInteger('sort_order')->default(0);
            // Custom badge rules (system badges: everyone gives, everyone receives, public, no limit).
            $table->string('visibility', 16)->default('public');
            $table->json('givers')->nullable();
            $table->json('giver_roles')->nullable();
            $table->json('receivers')->nullable();
            $table->unsignedSmallInteger('receive_limit')->default(0);
            $table->string('receive_period', 16)->default('month');
            $table->string('receive_period_basis', 16)->default('calendar');
            $table->boolean('reason_required')->default(false);
            $table->boolean('attachment_required')->default(false);
            $table->foreignId('certificate_template_id')->nullable()->constrained('letter_templates')->nullOnDelete();
            $table->string('core_value', 120)->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('recognitions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('badge_id')->constrained()->restrictOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('giver_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('giver_employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->text('message')->nullable();
            $table->string('visibility', 16)->default('public');
            $table->json('attachments')->nullable();
            $table->timestamp('withdrawn_at')->nullable();
            $table->foreignId('withdrawn_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('withdraw_reason', 255)->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'giver_user_id', 'created_at'], 'recognitions_giver_index');
            $table->index(['tenant_id', 'employee_id', 'badge_id', 'created_at'], 'recognitions_receiver_index');
            $table->index(['tenant_id', 'created_at'], 'recognitions_created_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recognitions');
        Schema::dropIfExists('badges');
    }
};
