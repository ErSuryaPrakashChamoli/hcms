<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 | Phase 6.1 — statutory rule evidence (§6), parameter-level coverage (§7), corrections (§8) and
 | regulatory change notices (§10, §12). Platform-level tables (no tenant): rules are shared.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('compliance_rules', function (Blueprint $table) {
            $table->foreignId('corrects_rule_id')->nullable()->after('superseded_by_id')->constrained('compliance_rules')->nullOnDelete();
            $table->text('correction_reason')->nullable()->after('corrects_rule_id');
        });

        Schema::table('compliance_rule_verifications', function (Blueprint $table) {
            $table->date('retrieved_at')->nullable()->after('effective_date');
        });

        Schema::create('compliance_evidence_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('compliance_rule_id')->constrained()->restrictOnDelete();
            $table->string('filename');
            $table->string('path');
            $table->string('mime', 128)->nullable();
            $table->unsignedBigInteger('size')->default(0);
            $table->string('sha256', 64);
            $table->string('source_url', 500)->nullable();
            $table->date('retrieved_at');
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('uploaded_label', 128)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->unique(['compliance_rule_id', 'sha256']);
        });

        Schema::create('compliance_rule_parameters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('compliance_rule_id')->constrained()->restrictOnDelete();
            $table->foreignId('compliance_rule_verification_id')->constrained(indexName: 'rule_parameters_verification_fk')->restrictOnDelete();
            $table->string('parameter', 128);
            $table->string('status', 16);
            $table->text('requirement_excerpt')->nullable();
            $table->text('note')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['compliance_rule_id', 'compliance_rule_verification_id'], 'rule_parameters_lookup_idx');
        });

        Schema::create('compliance_rule_notices', function (Blueprint $table) {
            $table->id();
            $table->string('jurisdiction', 4);
            $table->string('code', 32);
            $table->string('state', 4)->nullable();
            $table->json('affects_versions');
            $table->date('effective_date');
            $table->string('title');
            $table->text('summary');
            $table->json('references');
            $table->date('retrieved_at');
            $table->string('checksum', 64)->unique();
            $table->string('status', 16)->default('open');
            $table->foreignId('resolved_by_rule_id')->nullable()->constrained('compliance_rules')->nullOnDelete();
            $table->text('resolution_note')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['jurisdiction', 'code', 'status']);
        });

        // Coverage for Phase 5 submissions, read from their mapping with the Phase 6 rules
        // ("NOT CONFIRMED" → not confirmed, "NOT APPLICABLE…" → not applicable, else covered).
        foreach (DB::table('compliance_rule_verifications')->where('action', 'submitted')->orderBy('id')->get() as $submission) {
            foreach ((array) json_decode((string) $submission->mapping, true) as $parameter => $text) {
                $text = trim(is_array($text) ? (string) ($text['excerpt'] ?? '') : (string) $text);
                $status = stripos($text, 'NOT CONFIRMED') !== false ? 'not_confirmed' : (stripos($text, 'NOT APPLICABLE') === 0 ? 'not_applicable' : 'covered');
                DB::table('compliance_rule_parameters')->insert([
                    'compliance_rule_id' => $submission->compliance_rule_id, 'compliance_rule_verification_id' => $submission->id, 'parameter' => $parameter,
                    'status' => $status, 'requirement_excerpt' => $status === 'covered' ? $text : null, 'note' => $status === 'covered' ? null : $text, 'created_at' => $submission->created_at,
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('compliance_rule_notices');
        Schema::dropIfExists('compliance_rule_parameters');
        Schema::dropIfExists('compliance_evidence_documents');
        Schema::table('compliance_rule_verifications', fn (Blueprint $table) => $table->dropColumn('retrieved_at'));
        Schema::table('compliance_rules', function (Blueprint $table) {
            $table->dropConstrainedForeignId('corrects_rule_id');
            $table->dropColumn('correction_reason');
        });
    }
};
