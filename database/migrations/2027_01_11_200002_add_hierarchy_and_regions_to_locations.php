<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Data import DI-1 (Core → Sub branches, Branches): a location may sit inside another location of the same company
 * (a sub branch inside its branch), and a branch may belong to a region and a business region. All nullable: every
 * location on file stays a top-level branch with no region.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('locations', function (Blueprint $table) {
            $table->foreignId('parent_location_id')->nullable()->after('establishment_id')->constrained('locations', indexName: 'locations_parent_location_fk')->nullOnDelete();
            $table->foreignId('region_id')->nullable()->after('parent_location_id')->constrained('regions', indexName: 'locations_region_fk')->restrictOnDelete();
            $table->foreignId('business_region_id')->nullable()->after('region_id')->constrained('business_regions', indexName: 'locations_business_region_fk')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('locations', function (Blueprint $table) {
            $table->dropForeign('locations_business_region_fk');
            $table->dropForeign('locations_region_fk');
            $table->dropForeign('locations_parent_location_fk');
            $table->dropColumn(['business_region_id', 'region_id', 'parent_location_id']);
        });
    }
};
