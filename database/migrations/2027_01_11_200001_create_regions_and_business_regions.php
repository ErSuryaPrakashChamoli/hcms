<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Data import DI-1 (Core → Regions, Business regions): two organisation masters that group branches. A region (zone)
 * and a business region are independent groupings; a branch may sit in one of each (locations.region_id /
 * business_region_id, next migration).
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['regions', 'business_regions'] as $name) {
            Schema::create($name, function (Blueprint $table) use ($name) {
                $table->id();
                $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
                $table->string('code', 32);
                $table->string('name', 120);
                $table->string('description', 1000)->nullable();
                $table->string('status', 16)->default('active');
                $table->timestamps();

                $table->unique(['tenant_id', 'code'], "{$name}_tenant_code_unique");
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('business_regions');
        Schema::dropIfExists('regions');
    }
};
