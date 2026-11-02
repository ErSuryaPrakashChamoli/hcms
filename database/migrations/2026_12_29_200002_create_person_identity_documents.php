<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HROne parity MS-4 (matrix 22 §22.2.2 #3, #4): passport and driving licence on the person. The number is encrypted at
 * rest (`number`, text for the ciphertext) and never searchable; `number_last4` keeps the masked form for lists. One
 * record per kind and person (a renewal replaces it; the audit trail keeps the earlier one, masked). `expires_on` feeds
 * the daily lifecycle reminders.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('person_identity_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('person_id')->constrained('people')->cascadeOnDelete();
            $table->string('kind', 24);
            $table->text('number');
            $table->string('number_last4', 8)->nullable();
            $table->date('issued_on')->nullable();
            $table->date('expires_on')->nullable();
            $table->string('issuing_country', 2)->nullable();
            $table->string('place_of_issue', 128)->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'person_id', 'kind'], 'person_identity_documents_person_kind_unique');
            $table->index(['tenant_id', 'expires_on'], 'person_identity_documents_expiry_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('person_identity_documents');
    }
};
