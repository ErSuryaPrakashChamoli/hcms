<?php

use App\Domain\Compliance\Models\ComplianceRule;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 | Phase 5.3 — verified statutory rule framework. Rule versions gain authority, country, official
 | source fields, reviewer, notes and a payload checksum; verification history is append-only.
 | Status vocabulary: DRAFT / REVIEW / VERIFIED / SUPERSEDED / REJECTED (illustrative → draft).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('compliance_rules', function (Blueprint $table) {
            $table->string('authority', 32)->nullable()->after('state');
            $table->string('country', 2)->nullable()->after('jurisdiction');
            $table->string('source_url', 500)->nullable()->after('source');
            $table->string('source_title')->nullable()->after('source_url');
            $table->date('source_published_date')->nullable()->after('source_title');
            $table->foreignId('verified_by')->nullable()->after('verified_at')->constrained('users')->nullOnDelete();
            $table->text('verification_notes')->nullable()->after('verified_by');
            $table->string('checksum', 64)->nullable()->after('parameters');
            $table->foreignId('superseded_by_id')->nullable()->after('verification_notes')->constrained('compliance_rules')->nullOnDelete();
        });

        $authorities = ['EPF' => 'EPFO', 'ESI' => 'ESIC', 'PT' => 'STATE_PT', 'LWF' => 'STATE_LWF', 'TDS' => 'INCOME_TAX', 'GRATUITY' => 'LABOUR', 'SS' => 'SOCIAL_SECURITY', 'TAX' => 'TAX_AUTHORITY'];

        foreach (DB::table('compliance_rules')->orderBy('id')->get() as $row) {
            DB::table('compliance_rules')->where('id', $row->id)->update([
                'country' => strtoupper((string) $row->jurisdiction),
                'authority' => $authorities[$row->code] ?? null,
                'verification_status' => $row->verification_status === 'verified' ? 'verified' : 'draft',
                'checksum' => ComplianceRule::checksumFor($row->jurisdiction, $row->code, $row->state, (int) $row->version, (string) $row->effective_from, $row->effective_to, json_decode($row->parameters, true)),
            ]);
        }

        Schema::create('compliance_rule_verifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('compliance_rule_id')->constrained()->restrictOnDelete();
            $table->string('action', 16);
            $table->string('from_status', 16)->nullable();
            $table->string('to_status', 16);
            $table->string('authority', 32)->nullable();
            $table->string('source_url', 500)->nullable();
            $table->string('source_title')->nullable();
            $table->date('source_published_date')->nullable();
            $table->date('effective_date')->nullable();
            $table->text('requirement_text')->nullable();
            $table->json('mapping')->nullable();
            $table->string('evidence_reference')->nullable();
            $table->string('evidence_checksum', 64)->nullable();
            $table->string('rule_checksum', 64);
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_label', 128)->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['compliance_rule_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('compliance_rule_verifications');
        DB::table('compliance_rules')->where('verification_status', '!=', 'verified')->update(['verification_status' => 'illustrative']);
        Schema::table('compliance_rules', function (Blueprint $table) {
            $table->dropConstrainedForeignId('superseded_by_id');
            $table->dropConstrainedForeignId('verified_by');
            $table->dropColumn(['authority', 'country', 'source_url', 'source_title', 'source_published_date', 'verification_notes', 'checksum']);
        });
    }
};
