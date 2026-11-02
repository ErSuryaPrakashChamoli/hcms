<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Data import DI-1 (Core → States, Districts, Cities): the organisation's own list of places, one table with a kind.
 * A state may name its region on the platform's ISO 3166-2 list (country_regions, by country and code); a district
 * belongs to a state; a city belongs to a state and optionally to one of its districts. Codes are unique per kind.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('geo_places', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 16)->comment('state|district|city');
            $table->string('code', 32);
            $table->string('name', 120);
            $table->char('country_code', 2)->default('IN');
            $table->string('region_code', 16)->nullable()->comment('states: code on the platform country_regions list (MH for IN-MH)');
            $table->foreignId('state_id')->nullable()->constrained('geo_places', indexName: 'geo_places_state_fk')->restrictOnDelete();
            $table->foreignId('district_id')->nullable()->constrained('geo_places', indexName: 'geo_places_district_fk')->restrictOnDelete();
            $table->string('status', 16)->default('active');
            $table->timestamps();

            $table->unique(['tenant_id', 'kind', 'code'], 'geo_places_tenant_kind_code_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('geo_places');
    }
};
