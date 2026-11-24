<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Master uploads (workbooks): a workbook upload is an employee_imports row of a workbook_* type, and each of its
 * populated worksheets becomes an ordinary upload of its own type that points at the workbook (parent_id), names the
 * worksheet (sheet) and its place in the run order (stage). Individual uploads leave the three columns empty.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employee_imports', function (Blueprint $table) {
            $table->foreignId('parent_id')->nullable()->after('type')
                ->constrained('employee_imports', indexName: 'employee_imports_parent_id_foreign')->nullOnDelete();
            $table->string('sheet', 64)->nullable()->after('parent_id');
            $table->unsignedSmallInteger('stage')->nullable()->after('sheet');
        });
    }

    public function down(): void
    {
        Schema::table('employee_imports', function (Blueprint $table) {
            $table->dropForeign('employee_imports_parent_id_foreign');
            $table->dropColumn(['parent_id', 'sheet', 'stage']);
        });
    }
};
