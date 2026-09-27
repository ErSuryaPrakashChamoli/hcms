<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('departments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->nullable()->constrained('companies')->restrictOnDelete();
            $table->string('name');
            $table->string('code', 32);
            $table->foreignId('cost_centre_id')->nullable()->constrained('cost_centres')->restrictOnDelete();
            $table->text('description')->nullable();
            $table->string('status', 32)->default('active');
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('departments');
    }
};
