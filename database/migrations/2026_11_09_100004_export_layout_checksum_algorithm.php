<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * D7: an export layout's checksum records the algorithm that sealed it. The original algorithm hashes the
 * specification in the order it was written, but MySQL's json column re-sorts object keys on storage, so a layout
 * read back from MySQL can never reproduce its checksum. New layouts are sealed with the canonical form (keys sorted
 * at every level, list order kept), which no database can disturb.
 *
 * Existing rows are not touched: the column is added empty, NULL means "sealed with the original algorithm", and
 * their checksums stay exactly as stored. How (or whether) to re-establish integrity for original-algorithm rows on
 * MySQL is owner decision D7 (docs/compliance-research/compliance-owner-decisions.md).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('statutory_export_layouts', function (Blueprint $table) {
            $table->string('checksum_algorithm', 16)->nullable()->after('checksum')->comment('NULL = original (as written); canonical-v2 = keys sorted');
        });
    }

    public function down(): void
    {
        Schema::table('statutory_export_layouts', function (Blueprint $table) {
            $table->dropColumn('checksum_algorithm');
        });
    }
};
