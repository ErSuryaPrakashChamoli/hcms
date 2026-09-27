<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Configuration Change Centre (§71–§75): every governed change is a row here, whether it
        // was applied immediately, is waiting for approval, is scheduled, or was rolled back.
        Schema::create('configuration_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('subject_type');
            $table->unsignedBigInteger('subject_id');
            $table->string('subject_label')->nullable();
            $table->string('change_type', 32)->default('update');
            $table->string('risk_level', 16)->default('low');
            $table->string('status', 32)->default('pending_approval');
            $table->json('before')->nullable();
            $table->json('payload');
            $table->json('impact')->nullable();
            $table->date('effective_from')->nullable();
            $table->string('reason')->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('review_note')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->foreignId('rolled_back_by_change_id')->nullable()->constrained('configuration_changes')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'status', 'effective_from'], 'configuration_changes_status_from_idx');
            $table->index(['subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('configuration_changes');
    }
};
