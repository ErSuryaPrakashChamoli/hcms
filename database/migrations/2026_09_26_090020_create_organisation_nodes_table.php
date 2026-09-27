<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The structural hierarchy (blueprint §9–§10). Each node wraps exactly one organisation
        // master (company, business unit, division, department, team, location) via a morph.
        Schema::create('organisation_nodes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('organisation_nodes')->restrictOnDelete();
            $table->string('nodeable_type');
            $table->unsignedBigInteger('nodeable_id');
            $table->unsignedInteger('sort_order')->default(0);
            $table->unsignedSmallInteger('depth')->default(0);
            // Materialised path "/1/7/23/" for cheap subtree queries and cycle checks.
            $table->string('path', 700)->nullable();
            $table->string('status', 32)->default('active');
            $table->timestamps();

            $table->unique(['tenant_id', 'nodeable_type', 'nodeable_id']);
            $table->index(['tenant_id', 'parent_id', 'sort_order']);
            $table->index(['tenant_id', 'path']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organisation_nodes');
    }
};
