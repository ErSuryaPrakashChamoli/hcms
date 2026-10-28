<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Compliance report packs: one request to generate, in one go, every register, challan working and statement due for
 * a company and a period (a month, quarter, half-year or year, on the financial or calendar basis). The pack is built
 * in the background as the person who asked (their access scope applies), stored as one ZIP of workbooks with an
 * index, and downloadable only by that person. manifest records each report generated, empty or skipped and why.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('compliance_report_packs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('frequency', 16)->comment('monthly|quarterly|half_yearly|yearly');
            $table->string('basis', 16)->comment('financial|calendar');
            $table->string('period_key', 16)->comment('M:2026-09, FY:2026:Q2, CY:2026:H1, FY:2026…');
            $table->date('period_start');
            $table->date('period_end');
            $table->json('options')->nullable()->comment('establishment_ids, states, report_keys, include_shorter, unmasked');
            $table->string('status', 16)->default('queued')->comment('queued|running|completed|failed');
            $table->unsignedInteger('report_count')->default(0);
            $table->unsignedInteger('empty_count')->default(0);
            $table->json('manifest')->nullable();
            $table->string('disk', 32)->nullable();
            $table->string('path')->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->string('sha256', 64)->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'company_id', 'period_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('compliance_report_packs');
    }
};
