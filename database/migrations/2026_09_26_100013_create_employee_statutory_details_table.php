<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // India-first statutory identifiers. Identifier columns are encrypted at rest (text) and
        // masked in audit diffs; viewing them is itself audited (blueprint §66, §80).
        Schema::create('employee_statutory_details', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->unique()->constrained()->cascadeOnDelete();
            $table->text('pan')->nullable();
            $table->text('aadhaar_reference')->nullable();
            $table->text('uan')->nullable();
            $table->text('pf_number')->nullable();
            $table->text('esic_number')->nullable();
            $table->boolean('pf_applicable')->default(true);
            $table->boolean('esic_applicable')->default(false);
            $table->boolean('pt_applicable')->default(true);
            $table->string('tax_regime', 16)->nullable();
            $table->string('pt_state_code', 16)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_statutory_details');
    }
};
