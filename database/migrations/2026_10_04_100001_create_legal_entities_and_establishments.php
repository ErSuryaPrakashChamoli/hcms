<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 | Phase 5.1 — ADR-0001: Company › LegalEntity › Establishment (› Location).
 | Additive only. Existing companies are transitioned by `peopleos:legal-entities:backfill`
 | (audited, idempotent); new companies get a primary legal entity and establishment on creation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('legal_entities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('code', 32);
            $table->string('legal_name');
            $table->string('trade_name')->nullable();
            $table->string('legal_form', 64)->nullable();
            $table->string('country', 2);
            $table->string('incorporation_identifier', 64)->nullable();
            $table->boolean('is_primary')->default(false);
            $table->string('status', 16)->default('active');
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'code']);
            $table->index(['company_id', 'status']);
        });

        Schema::create('establishments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('legal_entity_id')->constrained()->cascadeOnDelete();
            $table->string('code', 32);
            $table->string('name');
            $table->text('address')->nullable();
            $table->string('country', 2);
            $table->string('state', 8)->nullable();
            $table->string('district', 128)->nullable();
            $table->string('postal_code', 16)->nullable();
            $table->string('establishment_type', 32)->default('office');
            $table->boolean('is_primary')->default(false);
            $table->string('status', 16)->default('active');
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['legal_entity_id', 'code']);
            $table->index(['company_id', 'status']);
            $table->index(['tenant_id', 'state']);
        });

        Schema::table('locations', function (Blueprint $table) {
            $table->foreignId('establishment_id')->nullable()->after('company_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('locations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('establishment_id');
        });
        Schema::dropIfExists('establishments');
        Schema::dropIfExists('legal_entities');
    }
};
