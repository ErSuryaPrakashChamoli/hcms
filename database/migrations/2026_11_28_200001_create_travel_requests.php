<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HROne setup parity EX-F — Travel requests (HROne "Travel plan"): a trip an employee asks to make, with its legs
 * (journeys and stays, each in a travel-linked expense category), the travel form fields (currency, estimated cost,
 * international travel with visa and passport numbers — encrypted —, attachments, custom fields), an optional advance
 * decided with the trip, approval and cancellation with approval, and company bookings raised as helpdesk requests for
 * the travel desk (booking details come back on the leg). Expense receipts, advances and reports point at the trip.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('travel_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('number', 40);
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->string('purpose', 500);
            $table->date('start_date');
            $table->date('end_date');
            $table->boolean('is_international')->default(false);
            $table->char('currency', 3);
            $table->decimal('estimated_cost', 14, 2)->nullable()->comment('in the trip currency');
            $table->decimal('exchange_rate', 18, 8)->default(1);
            $table->decimal('estimated_cost_base', 14, 2)->nullable();
            $table->text('visa_number')->nullable()->comment('encrypted');
            $table->text('passport_number')->nullable()->comment('encrypted');
            $table->json('attachments')->nullable()->comment('paths on the private documents disk');
            $table->string('backdated_reason', 500)->nullable();
            $table->decimal('advance_amount', 14, 2)->nullable()->comment('advance asked for with the trip, in the trip currency');
            $table->foreignId('expense_advance_id')->nullable()->constrained()->nullOnDelete();
            $table->string('workflow_group', 120)->nullable()->comment('all | category:<code> | group:<code> (expense.travel.workflow.*)');
            $table->string('status', 24)->default('submitted')->comment('submitted|approved|booking|booked|rejected|cancel_requested|cancelled');
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('decision_note', 500)->nullable();
            $table->string('cancel_reason', 500)->nullable();
            $table->timestamp('cancel_requested_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'number']);
            $table->index(['tenant_id', 'employee_id', 'start_date', 'end_date'], 'travel_req_emp_dates_idx');
            $table->index(['tenant_id', 'status'], 'travel_req_status_idx');
        });

        Schema::create('travel_request_legs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('travel_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('sequence')->default(1);
            $table->string('kind', 16)->comment('travel | accommodation (the category travel form)');
            $table->foreignId('expense_category_id')->constrained()->restrictOnDelete();
            $table->string('mode', 16)->nullable()->comment('peopleos.expense.travel_modes (journeys)');
            $table->string('from_city', 120)->nullable();
            $table->string('from_state', 64)->nullable();
            $table->string('to_city', 120)->nullable()->comment('the destination, or the city of a stay');
            $table->string('to_state', 64)->nullable();
            $table->date('starts_on')->comment('journey date, or check-in');
            $table->date('ends_on')->nullable()->comment('return date, or check-out');
            $table->string('preferred_time', 5)->nullable()->comment('HH:MM');
            $table->string('booked_by', 16)->default('employee')->comment('employee | company');
            $table->string('status', 16)->default('pending')->comment('pending | booking | booked | cancelled');
            $table->foreignId('booking_ticket_id')->nullable()->constrained('tickets')->nullOnDelete();
            $table->json('booking_details')->nullable()->comment('reference (PNR), provider, cost, currency, notes');
            $table->decimal('booking_cost_base', 14, 2)->nullable();
            $table->timestamp('booked_at')->nullable();
            $table->foreignId('booked_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('notes', 500)->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'travel_request_id'], 'travel_legs_trip_idx');
            $table->index(['tenant_id', 'status'], 'travel_legs_status_idx');
        });

        Schema::create('expense_report_travel', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('expense_report_id')->constrained()->cascadeOnDelete();
            $table->foreignId('travel_request_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'expense_report_id', 'travel_request_id'], 'exp_report_travel_unique');
        });

        Schema::table('expense_receipts', function (Blueprint $table) {
            $table->foreignId('travel_request_id')->nullable()->after('expense_report_part_id')->constrained()->nullOnDelete();
        });

        Schema::table('expense_advances', function (Blueprint $table) {
            $table->foreignId('travel_request_id')->nullable()->after('policy_version_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('expense_advances', function (Blueprint $table) {
            $table->dropConstrainedForeignId('travel_request_id');
        });
        Schema::table('expense_receipts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('travel_request_id');
        });
        Schema::dropIfExists('expense_report_travel');
        Schema::dropIfExists('travel_request_legs');
        Schema::dropIfExists('travel_requests');
    }
};
