<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HROne setup parity HP-9b (Engage → Mission statement): the organisation's mission, vision, purpose and core values,
 * for everyone or for a group of employees (audience rule conditions; empty = everyone), shown on Home.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mission_statements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('code', 40);
            $table->string('name', 120);
            $table->text('mission')->nullable();
            $table->text('vision')->nullable();
            $table->text('purpose')->nullable();
            $table->json('core_values')->nullable();
            $table->boolean('show_on_home')->default(true);
            $table->json('audience')->nullable();
            $table->string('status', 16)->default('active');
            $table->date('effective_from')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mission_statements');
    }
};
