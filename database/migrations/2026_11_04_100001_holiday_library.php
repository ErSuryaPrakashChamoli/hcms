<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Holiday library: platform-owned holiday lists per country, region and year (no tenant_id). Tenants copy
 * the holidays they observe into their own calendars; a copied holiday remembers its library entry.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('holiday_library_lists', function (Blueprint $table) {
            $table->id();
            $table->char('country_code', 2);
            // '*' = national: the holidays of every region of the country.
            $table->string('region_code', 16)->default('*');
            $table->string('region_name')->nullable();
            $table->unsignedSmallInteger('year');
            $table->string('status', 16)->default('draft');
            $table->string('source', 500)->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('uploaded_at')->nullable();
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->unique(['country_code', 'region_code', 'year'], 'holiday_library_lists_key');
            $table->index(['status', 'country_code', 'year'], 'holiday_library_lists_status');
        });

        Schema::create('holiday_library_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('holiday_library_list_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->string('name');
            $table->string('type', 32)->default('public');
            $table->boolean('is_half_day')->default(false);
            $table->timestamps();

            $table->unique(['holiday_library_list_id', 'date'], 'holiday_library_entries_date');
        });

        Schema::table('holidays', function (Blueprint $table) {
            $table->foreignId('holiday_library_entry_id')->nullable()->after('holiday_calendar_id')
                ->constrained('holiday_library_entries', indexName: 'holidays_library_entry_fk')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('holidays', function (Blueprint $table) {
            $table->dropForeign('holidays_library_entry_fk');
            $table->dropColumn('holiday_library_entry_id');
        });
        Schema::dropIfExists('holiday_library_entries');
        Schema::dropIfExists('holiday_library_lists');
    }
};
