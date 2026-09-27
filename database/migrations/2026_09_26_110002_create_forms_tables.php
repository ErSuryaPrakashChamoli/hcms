<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Form Builder (§42): Create -> Version -> Publish -> Collect -> Validate -> Approve -> Audit.
        Schema::create('forms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('key', 64);
            $table->text('description')->nullable();
            $table->boolean('requires_approval')->default(false);
            $table->string('status', 32)->default('active');
            $table->timestamps();

            $table->unique(['tenant_id', 'key']);
        });

        // Versions are immutable once published; the draft is the only editable row.
        Schema::create('form_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('form_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->json('fields');
            $table->string('status', 32)->default('draft');
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('retired_at')->nullable();
            $table->timestamps();

            $table->unique(['form_id', 'version']);
            $table->index(['tenant_id', 'form_id', 'status']);
        });

        Schema::create('form_submissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('form_id')->constrained()->cascadeOnDelete();
            $table->foreignId('form_version_id')->constrained('form_versions')->restrictOnDelete();
            $table->nullableMorphs('subject');
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('data');
            $table->string('status', 32)->default('submitted');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('review_note')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'form_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('form_submissions');
        Schema::dropIfExists('form_versions');
        Schema::dropIfExists('forms');
    }
};
