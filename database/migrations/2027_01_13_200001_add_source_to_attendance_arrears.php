<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Data import DI-35 (matrix 24 §24.9 #4, #15):
 * - `source`: an arrear is worked out by the calculation (a correction to a day already used downstream) or set by HR for an
 *   earlier month from an upload ("Override arrear days"); `covers_from` is the first day of the month HR's figure covers
 *   (its `date` is the month's last day). While HR's figure covers a month the calculation records no arrears for its days.
 * - `overtime_minutes`: approved overtime added (+) or taken back (−) on a locked day ("Override previous month overtime"),
 *   a quantity a later month takes, beside the paid-day difference in `delta_days`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_arrears', function (Blueprint $table) {
            $table->string('source', 16)->default('calculation')->after('delta_days')->comment('calculation|upload');
            $table->date('covers_from')->nullable()->after('source');
            $table->integer('overtime_minutes')->default(0)->after('covers_from');
        });
    }

    public function down(): void
    {
        Schema::table('attendance_arrears', function (Blueprint $table) {
            $table->dropColumn(['source', 'covers_from', 'overtime_minutes']);
        });
    }
};
