<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SaaS.9 data export, offboarding and retention. Additive: no existing table changes; the tenant status column (a
 * string) gains the values `closed` and `deleted` in the TenantStatus enum.
 *
 * - tenant_offboardings: one offboarding case per exit (open_key keeps one open case per tenant).
 * - tenant_exports: asynchronous, resumable, encrypted export packages (in_flight_key: one export generating per tenant).
 * - legal_holds: holds that stop deletion (and retention purges) of the categories they cover; release needs a second
 *   operator.
 * - retention_policies: platform configuration, versioned and effective-dated per data category and jurisdiction, with an
 *   optional configured legal minimum; proposed by one operator and approved by another.
 * - tenant_deletions / tenant_deletion_steps: a reviewed deletion plan, its maker-checker approval, and its resumable,
 *   checkpointed execution and certificate (in_flight_key: one deletion planned, approved or running per tenant).
 *
 * Every row that names a tenant keeps it with restrictOnDelete: the tenant row is a tombstone that is never deleted, so
 * these records (the evidence of the exit) and the retained financial and audit records keep their tenant.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_offboardings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->ulid('reference')->unique();
            $table->string('status', 16); // requested | closed | completed | cancelled
            $table->unsignedBigInteger('open_key')->nullable()->unique(); // the tenant id while requested or closed
            $table->text('reason');
            $table->string('customer_reference', 120)->nullable();
            $table->date('service_end_date')->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('requested_at');
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->string('closed_from_status', 16)->nullable();
            $table->boolean('warehouse_was_enabled')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancel_reason')->nullable();
            $table->foreignId('export_waived_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('export_waived_at')->nullable();
            $table->text('export_waiver_reason')->nullable();
            $table->timestamp('deletion_started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
        });

        Schema::create('tenant_exports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('offboarding_id')->nullable()->constrained('tenant_offboardings')->restrictOnDelete();
            $table->ulid('reference')->unique();
            $table->string('purpose', 16); // offboarding | customer
            $table->string('status', 16); // queued | generating | ready | failed | expired | cancelled
            $table->unsignedBigInteger('in_flight_key')->nullable()->unique(); // the tenant id while queued or generating
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reason');
            $table->json('checkpoint')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('heartbeat_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->string('failure', 500)->nullable();
            $table->string('disk', 32);
            $table->string('path', 255)->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->char('sha256', 64)->nullable();
            $table->json('manifest')->nullable();
            $table->char('manifest_sha256', 64)->nullable();
            $table->char('manifest_signature', 64)->nullable();
            $table->text('passphrase')->nullable(); // encrypted with the application key
            $table->unsignedBigInteger('record_count')->nullable();
            $table->unsignedInteger('file_count')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('expired_at')->nullable();
            $table->unsignedInteger('download_count')->default(0);
            $table->timestamp('last_downloaded_at')->nullable();
            $table->foreignId('delivered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('delivered_at')->nullable();
            $table->string('delivery_note', 500)->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
            $table->index(['status', 'heartbeat_at']);
        });

        Schema::create('legal_holds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->ulid('reference')->unique();
            $table->string('status', 20); // active | release_requested | released
            $table->string('scope', 16); // tenant | categories
            $table->json('categories')->nullable();
            $table->text('reason');
            $table->string('matter_reference', 120)->nullable();
            $table->date('starts_on');
            $table->foreignId('placed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('placed_at');
            $table->foreignId('release_requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('release_requested_at')->nullable();
            $table->text('release_reason')->nullable();
            $table->foreignId('released_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('released_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
        });

        Schema::create('retention_policies', function (Blueprint $table) {
            $table->id();
            $table->string('category', 40);
            $table->string('jurisdiction', 8); // ISO 3166-1 alpha-2, or * for every jurisdiction without its own policy
            $table->unsignedInteger('version');
            $table->unsignedSmallInteger('legal_minimum_months')->nullable(); // null = LEGAL_MINIMUM_NOT_CONFIGURED
            $table->text('legal_basis')->nullable();
            $table->string('legal_reference', 500)->nullable();
            $table->unsignedSmallInteger('policy_months');
            $table->text('policy_basis')->nullable();
            $table->date('effective_from');
            $table->string('status', 16); // pending | approved | rejected | withdrawn
            $table->foreignId('proposed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('proposed_at');
            $table->text('reason');
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_reason')->nullable();
            $table->timestamps();

            $table->unique(['category', 'jurisdiction', 'version'], 'retention_policies_version_unique');
            $table->index(['category', 'jurisdiction', 'status', 'effective_from'], 'retention_policies_lookup_index');
        });

        Schema::create('tenant_deletions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('offboarding_id')->constrained('tenant_offboardings')->restrictOnDelete();
            $table->ulid('reference')->unique();
            $table->string('status', 16); // planned | approved | in_progress | completed | failed | rejected | cancelled
            $table->unsignedBigInteger('in_flight_key')->nullable()->unique(); // the tenant id while planned, approved, in progress or failed
            $table->json('plan');
            $table->char('plan_sha256', 64);
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('requested_at');
            $table->text('request_reason');
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->text('approval_reason')->nullable();
            $table->foreignId('rejected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('rejected_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancel_reason')->nullable();
            $table->timestamp('scheduled_for')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->string('failure', 1000)->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('heartbeat_at')->nullable();
            $table->json('certificate')->nullable();
            $table->char('certificate_sha256', 64)->nullable();
            $table->char('certificate_signature', 64)->nullable();
            $table->timestamp('certified_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
            $table->index(['status', 'scheduled_for']);
        });

        Schema::create('tenant_deletion_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_deletion_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('position');
            $table->string('category', 40);
            $table->string('kind', 16); // children | rows | anonymise | files | audit | verify | external
            $table->string('target', 160);
            $table->string('status', 24); // pending | done | skipped | failed | external_required | external_requested | external_confirmed | external_unsupported
            $table->unsignedBigInteger('planned_count')->nullable();
            $table->unsignedBigInteger('processed_count')->default(0);
            $table->json('detail')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_deletion_id', 'position']);
            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_deletion_steps');
        Schema::dropIfExists('tenant_deletions');
        Schema::dropIfExists('retention_policies');
        Schema::dropIfExists('legal_holds');
        Schema::dropIfExists('tenant_exports');
        Schema::dropIfExists('tenant_offboardings');
    }
};
