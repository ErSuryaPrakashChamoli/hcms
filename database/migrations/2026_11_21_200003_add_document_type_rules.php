<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HROne setup parity — Core HR → Employee → Document (CA-4): a sample file and instructions per document type, what
 * the employee may do with it (view, upload), whether every employee must have it, its own expiry alert, and a
 * profile field it supports (a document of the type is required when that field is entered). Defaults keep today's
 * behaviour: employees see their documents, only HR uploads, nothing is mandatory for employees, no field link.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_types', function (Blueprint $table) {
            $table->string('sample_path', 255)->nullable()->after('mandatory_for_onboarding')->comment('documents disk');
            $table->string('sample_instructions', 1000)->nullable()->after('sample_path');
            $table->boolean('employee_can_view')->default(true)->after('sample_instructions');
            $table->boolean('employee_can_upload')->default(false)->after('employee_can_view');
            $table->boolean('mandatory_for_employee')->default(false)->after('employee_can_upload');
            $table->unsignedSmallInteger('expiry_alert_days')->nullable()->after('mandatory_for_employee')->comment('null = documents.expiry.reminder_days');
            $table->string('linked_field', 32)->nullable()->after('expiry_alert_days')->comment('peopleos.documents.linked_fields');
            $table->boolean('required_with_field')->default(false)->after('linked_field');
        });
    }

    public function down(): void
    {
        Schema::table('document_types', fn (Blueprint $table) => $table->dropColumn(['sample_path', 'sample_instructions', 'employee_can_view', 'employee_can_upload', 'mandatory_for_employee', 'expiry_alert_days', 'linked_field', 'required_with_field']));
    }
};
