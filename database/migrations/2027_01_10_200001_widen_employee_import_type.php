<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Data import: every HROne import process gets an upload type on the staged pipeline, and their names
 * ("designation_skills", "reimbursement_opening") outgrow the 16 characters employee_imports.type was given
 * when it only held employees and punches.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employee_imports', function (Blueprint $table) {
            $table->string('type', 32)->default('employees')->change();
        });
    }

    public function down(): void
    {
        Schema::table('employee_imports', function (Blueprint $table) {
            $table->string('type', 16)->default('employees')->change();
        });
    }
};
