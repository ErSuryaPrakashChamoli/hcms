<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 | Phase 11.4 — compensation reminder throttling (one reminder per kind, subject and day; the same
 | pattern as the Phase 8–10 reminder logs). Additive only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('compensation_reminder_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('reminder', 32);
            $table->string('subject_type', 64);
            $table->unsignedBigInteger('subject_id');
            $table->date('reminded_on');
            $table->timestamp('created_at')->nullable();

            $table->unique(['tenant_id', 'reminder', 'subject_type', 'subject_id', 'reminded_on'], 'compensation_reminder_logs_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('compensation_reminder_logs');
    }
};
