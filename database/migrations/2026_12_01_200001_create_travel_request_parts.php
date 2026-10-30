<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Owner decision 2026-10-29 (EX-F, trips spanning several workflow groups): one trip, one record, approved by every
 * workflow group its legs belong to. A trip whose legs fall in several groups gets one approval part per group (as an
 * expense report does): each part holds its legs and is decided on its own (a workflow run or an Approval Center item);
 * the trip is approved when every part is, and rejected when any part is. Trips of one group have no parts (as before).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('travel_request_parts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('travel_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->string('workflow_group', 120)->comment('category:<code> | group:<code> | all (expense.travel.workflow.*)');
            $table->string('label', 255);
            $table->string('status', 16)->default('pending')->comment('pending | approved | rejected | cancelled');
            $table->timestamp('decided_at')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('decision_note', 500)->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'travel_request_id'], 'travel_parts_trip_idx');
            $table->index(['tenant_id', 'status'], 'travel_parts_status_idx');
        });

        Schema::table('travel_request_legs', function (Blueprint $table) {
            $table->foreignId('travel_request_part_id')->nullable()->after('travel_request_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('travel_request_legs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('travel_request_part_id');
        });
        Schema::dropIfExists('travel_request_parts');
    }
};
