<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HROne parity AC-3 (matrix 23 §23.9 #22, "Product enhancement request"): an organisation's suggestions to Markedge — the
 * module and process, new feature or improvement, the challenge, how it is managed today, the ideal solution, the impact and
 * supporting files — and Markedge's answer (status, response). The tenant's own record: it is exported and deleted with the
 * tenant's data (DataInventory: service_desk). The reference (IDEA-000042) comes from the id, so it is unique across tenants.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_ideas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('reference', 20)->nullable()->unique('product_ideas_reference_unique');
            $table->string('module', 40);
            $table->string('process', 150);
            $table->string('kind', 16);
            $table->string('title', 150);
            $table->text('challenge');
            $table->text('current_workaround')->nullable();
            $table->text('ideal_solution');
            $table->unsignedInteger('impact_people')->nullable();
            $table->string('priority', 8)->nullable();
            $table->json('attachments')->nullable();
            $table->string('status', 16)->default('submitted');
            $table->text('platform_response')->nullable();
            $table->timestamp('status_changed_at')->nullable();
            $table->foreignId('submitted_by')->nullable()->constrained('users', 'id', 'product_ideas_submitted_by_fk')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status'], 'product_ideas_tenant_status_idx');
            $table->index(['status', 'id'], 'product_ideas_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_ideas');
    }
};
