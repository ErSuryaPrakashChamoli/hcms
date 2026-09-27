<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Phase 1 §38–§41: staged, reviewable employee imports. Nothing touches employees until approval + run. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_imports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('original_name');
            $table->string('disk', 32);
            $table->string('path');
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->string('status', 24)->default('uploaded')->comment('uploaded|inspected|mapped|validated|approved|importing|imported|failed|discarded');
            $table->json('headers')->nullable();
            $table->json('mapping')->nullable();
            $table->json('options')->nullable();
            $table->unsignedInteger('row_count')->default(0);
            $table->unsignedInteger('valid_count')->default(0);
            $table->unsignedInteger('error_count')->default(0);
            $table->unsignedInteger('review_count')->default(0);
            $table->unsignedInteger('create_count')->default(0);
            $table->unsignedInteger('update_count')->default(0);
            $table->unsignedInteger('skip_count')->default(0);
            $table->unsignedInteger('failure_count')->default(0);
            $table->string('operation_id', 26)->nullable();
            $table->foreignId('uploaded_by')->constrained('users');
            $table->foreignId('approved_by')->nullable()->constrained('users');
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('imported_at')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
        });

        Schema::create('employee_import_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_import_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('row_number');
            $table->json('data');
            $table->string('action', 16)->default('pending')->comment('pending|create|update|review|skip|error');
            $table->string('status', 16)->default('pending')->comment('pending|imported|failed|skipped');
            $table->foreignId('employee_id')->nullable()->constrained()->nullOnDelete();
            $table->json('match')->nullable();
            $table->json('errors')->nullable();
            $table->text('result')->nullable();
            $table->timestamps();

            $table->unique(['employee_import_id', 'row_number']);
            $table->index(['employee_import_id', 'action']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_import_rows');
        Schema::dropIfExists('employee_imports');
    }
};
