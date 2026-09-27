<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('code', 32);
            $table->string('category', 32);
            $table->boolean('requires_expiry')->default(false);
            $table->boolean('mandatory_for_onboarding')->default(false);
            $table->string('status', 32)->default('active');
            $table->timestamps();

            $table->unique(['tenant_id', 'code']);
        });

        // Files live in object/private storage (config peopleos.documents.disk); never a public path (§39, §83).
        Schema::create('employee_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('document_type_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title');
            $table->string('disk', 32);
            $table->string('path');
            $table->string('original_name');
            $table->string('mime_type', 128)->nullable();
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->unsignedInteger('version')->default(1);
            $table->string('status', 32)->default('pending');
            $table->date('issued_on')->nullable();
            $table->date('expires_on')->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->string('review_note')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'employee_id', 'status']);
            $table->index(['tenant_id', 'expires_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_documents');
        Schema::dropIfExists('document_types');
    }
};
