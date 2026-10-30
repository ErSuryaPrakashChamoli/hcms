<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HROne parity MS-3 (matrix 22 §22.2.0 #5–8, §22.2.12): self-service profile changes.
 *
 * - profile_change_requests: one change an employee asked for on My profile, per section. The new values live only in
 *   `payload` (encrypted) until the request is decided, then they are purged (as Phase 12 purges service requests);
 *   `fields` keeps the field names with masked earlier / new values for the history. `open_key` is set while the request
 *   is pending ("employee:section") and unique per tenant: one open request per section, even under a race.
 * - custom_fields.editable_by_employee: HR marks the custom fields an employee may change themselves (default false).
 * - employee_documents.upload_comment: the uploader's comment (My HR upload, a document attached to a profile change).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('profile_change_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('requested_by')->constrained('users');
            $table->string('section', 32);
            $table->string('route', 16)->nullable();
            $table->string('status', 16)->default('pending');
            $table->string('comment', 500);
            $table->text('payload')->nullable();
            $table->json('fields')->nullable();
            $table->json('approver_permissions')->nullable();
            $table->json('document_ids')->nullable();
            $table->string('open_key', 64)->nullable();
            $table->string('operation_id', 26)->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->string('decision_note', 1000)->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->json('applied_records')->nullable();
            $table->timestamp('purged_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'open_key'], 'profile_change_requests_open_unique');
            $table->index(['tenant_id', 'employee_id', 'status'], 'profile_change_requests_employee_status_index');
            $table->index(['tenant_id', 'status', 'section'], 'profile_change_requests_status_section_index');
        });

        Schema::table('custom_fields', function (Blueprint $table) {
            $table->boolean('editable_by_employee')->default(false)->after('visible_to_employee');
        });

        Schema::table('employee_documents', function (Blueprint $table) {
            $table->string('upload_comment', 500)->nullable()->after('review_note');
        });
    }

    public function down(): void
    {
        Schema::table('employee_documents', function (Blueprint $table) {
            $table->dropColumn('upload_comment');
        });
        Schema::table('custom_fields', function (Blueprint $table) {
            $table->dropColumn('editable_by_employee');
        });
        Schema::dropIfExists('profile_change_requests');
    }
};
