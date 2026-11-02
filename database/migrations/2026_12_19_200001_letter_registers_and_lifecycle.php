<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HROne parity WO-13 (matrix 23 §23.7.1, §23.7.2, §23.11): letter registers and lifecycle.
 *
 * - letters / letter_templates: up to four signatories (the first stays letter_signatory_id); letters keep the process that
 *   drafted them (`process`, e.g. confirmation.letter_on_extend), the letter they supersede, and who revoked them (D-WO5 /
 *   D-WO6: revoked letters stay, with their file).
 * - letter_schedules (+ letter_schedule_items, one row per employee and occurrence): letters generated later or around an
 *   employee date, run by `peopleos:letters:tick`.
 * - letter_downloads: queued zips of issued letters (bulk download), kept for a few hours.
 * - letter_emails: an issued letter e-mailed to the employee (recipients and copies; the body is never kept here).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('letter_templates', function (Blueprint $table) {
            foreach ([2, 3, 4] as $n) {
                $table->foreignId("letter_signatory_{$n}_id")->nullable()->after($n === 2 ? 'letter_signatory_id' : 'letter_signatory_'.($n - 1).'_id')
                    ->constrained('letter_signatories', 'id', "letter_templates_signatory_{$n}_fk")->nullOnDelete();
            }
        });

        Schema::create('letter_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->foreignId('letter_template_id')->constrained()->restrictOnDelete();
            $table->json('signatory_ids')->nullable();
            $table->string('trigger', 16);
            $table->timestamp('run_at')->nullable();
            $table->string('event', 32)->nullable();
            $table->integer('offset_days')->default(0);
            $table->string('audience', 24);
            $table->json('audience_criteria')->nullable();
            $table->json('employee_ids')->nullable();
            $table->json('extra')->nullable();
            $table->boolean('issue')->default(true);
            $table->json('email_to')->nullable();
            $table->string('status', 16)->default('saved');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->date('last_run_on')->nullable();
            $table->unsignedInteger('runs_count')->default(0);
            $table->unsignedInteger('letters_count')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
        });

        Schema::table('letters', function (Blueprint $table) {
            foreach ([2, 3, 4] as $n) {
                $table->foreignId("letter_signatory_{$n}_id")->nullable()->after($n === 2 ? 'letter_signatory_id' : 'letter_signatory_'.($n - 1).'_id')
                    ->constrained('letter_signatories', 'id', "letters_signatory_{$n}_fk")->nullOnDelete();
            }
            $table->string('process', 64)->nullable()->after('source_id');
            $table->foreignId('supersedes_letter_id')->nullable()->after('process')->constrained('letters', 'id', 'letters_supersedes_fk')->nullOnDelete();
            $table->foreignId('letter_schedule_id')->nullable()->after('supersedes_letter_id')->constrained('letter_schedules', 'id', 'letters_schedule_fk')->nullOnDelete();
            $table->timestamp('revoked_at')->nullable()->after('issued_at');
            $table->foreignId('revoked_by')->nullable()->after('revoked_at')->constrained('users', 'id', 'letters_revoked_by_fk')->nullOnDelete();
            $table->text('revoke_reason')->nullable()->after('revoked_by');

            $table->index(['tenant_id', 'status'], 'letters_tenant_status_idx');
            $table->index(['tenant_id', 'process'], 'letters_tenant_process_idx');
        });

        Schema::create('letter_schedule_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('letter_schedule_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->date('occurrence');
            $table->foreignId('letter_id')->nullable()->constrained()->nullOnDelete();
            $table->string('outcome', 16);
            $table->string('message', 255)->nullable();
            $table->timestamps();

            $table->unique(['letter_schedule_id', 'employee_id', 'occurrence'], 'letter_schedule_items_once');
            $table->index(['tenant_id', 'letter_schedule_id'], 'letter_schedule_items_tenant_idx');
        });

        Schema::create('letter_downloads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('requested_by')->constrained('users')->cascadeOnDelete();
            $table->json('letter_ids');
            $table->string('selection', 24)->default('selected');
            $table->string('status', 16)->default('queued');
            $table->unsignedInteger('total')->default(0);
            $table->unsignedInteger('included')->default(0);
            $table->unsignedInteger('skipped')->default(0);
            $table->string('file_disk', 32)->nullable();
            $table->string('file_path')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'requested_by']);
        });

        Schema::create('letter_emails', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('letter_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sent_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('to_kinds');
            $table->json('recipients');
            $table->json('cc')->nullable();
            $table->json('bcc')->nullable();
            $table->string('subject', 200);
            $table->foreignId('notification_delivery_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'letter_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('letter_emails');
        Schema::dropIfExists('letter_downloads');
        Schema::dropIfExists('letter_schedule_items');
        Schema::table('letters', function (Blueprint $table) {
            $table->dropIndex('letters_tenant_status_idx');
            $table->dropIndex('letters_tenant_process_idx');
            $table->dropForeign('letters_revoked_by_fk');
            $table->dropForeign('letters_schedule_fk');
            $table->dropForeign('letters_supersedes_fk');
            foreach ([2, 3, 4] as $n) {
                $table->dropForeign("letters_signatory_{$n}_fk");
            }
            $table->dropColumn(['letter_signatory_2_id', 'letter_signatory_3_id', 'letter_signatory_4_id', 'process', 'supersedes_letter_id', 'letter_schedule_id', 'revoked_at', 'revoked_by', 'revoke_reason']);
        });
        Schema::dropIfExists('letter_schedules');
        Schema::table('letter_templates', function (Blueprint $table) {
            foreach ([2, 3, 4] as $n) {
                $table->dropForeign("letter_templates_signatory_{$n}_fk");
            }
            $table->dropColumn(['letter_signatory_2_id', 'letter_signatory_3_id', 'letter_signatory_4_id']);
        });
    }
};
