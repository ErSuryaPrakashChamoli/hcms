<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bgv_cases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 32)->default('manual');
            $table->string('external_reference', 128)->nullable();
            $table->string('status', 32)->default('initiated');
            $table->string('overall_result', 32)->default('pending');
            $table->timestamp('consent_given_at')->nullable();
            $table->foreignId('consent_document_id')->nullable()->constrained('employee_documents')->nullOnDelete();
            $table->foreignId('initiated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('initiated_at');
            $table->timestamp('completed_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'external_reference']);
        });

        Schema::create('bgv_checks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('bgv_case_id')->constrained()->cascadeOnDelete();
            $table->string('type', 32);
            $table->string('status', 32)->default('pending');
            $table->text('result_notes')->nullable();
            $table->foreignId('evidence_document_id')->nullable()->constrained('employee_documents')->nullOnDelete();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['bgv_case_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bgv_checks');
        Schema::dropIfExists('bgv_cases');
    }
};
