<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HROne setup parity EX-A — Expense masters: expense groups, expense categories (amount or mileage, with every rule
 * HROne's category form has) and their limits by employee dimension and location group, location groups with the
 * places (state, city) they cover, and expense tax types. Receipts, reports, advances and travel come later (EX-C …).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expense_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('code', 32);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->string('status', 16)->default('active');
            $table->timestamps();

            $table->unique(['tenant_id', 'code']);
        });

        Schema::create('expense_location_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('code', 32);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->string('status', 16)->default('active');
            $table->timestamps();

            $table->unique(['tenant_id', 'code']);
        });

        Schema::create('expense_location_group_places', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('expense_location_group_id')->constrained()->cascadeOnDelete();
            $table->char('country_code', 2)->default('IN');
            $table->string('state_code', 64)->comment('IN: state code (peopleos.compliance.states); elsewhere the state as typed');
            $table->string('city', 120)->nullable()->comment('null = the whole state');
            $table->string('city_key', 120)->default('')->comment('lower-case city; empty = the whole state');
            $table->timestamps();

            // A place belongs to one group.
            $table->unique(['tenant_id', 'country_code', 'state_code', 'city_key'], 'exp_loc_places_unique');
        });

        Schema::create('expense_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('expense_group_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('name', 120);
            $table->string('code', 32);
            $table->text('description')->nullable();
            $table->string('type', 16)->default('amount')->comment('amount | mileage');
            // Mileage.
            $table->decimal('rate_per_km', 10, 4)->nullable();
            $table->boolean('odometer_required')->default(false);
            $table->string('location_input', 16)->default('none')->comment('none | text | map');
            $table->boolean('from_location_required')->default(false);
            // Limits.
            $table->string('limit_basis', 16)->default('none')->comment('none | amount | percent_of_ctc | km');
            $table->string('limit_dimension', 32)->nullable()->comment('employee position column the limits vary by; null = everyone');
            $table->foreignId('ctc_component_id')->nullable()->constrained('salary_components')->nullOnDelete();
            $table->boolean('limit_exception_allowed')->default(false);
            $table->boolean('hourly_constraints_enabled')->default(false);
            $table->json('hourly_constraints')->nullable()->comment('[{from_minutes, to_minutes, percent}]');
            $table->boolean('auto_fill_worked_hours')->default(false);
            // Claim rules.
            $table->boolean('description_required')->default(false);
            $table->boolean('receipt_required')->default(false);
            $table->decimal('receipt_required_above', 14, 2)->nullable();
            $table->boolean('reimbursable')->default(true);
            $table->string('claim_frequency_period', 16)->nullable();
            $table->unsignedSmallInteger('claim_frequency_count')->nullable();
            $table->boolean('tax_applicable')->default(false);
            $table->string('gst_supply_type', 16)->nullable()->comment('goods | services');
            // Travel.
            $table->string('travel_link', 16)->default('none')->comment('none | reference | plan');
            $table->string('travel_form', 16)->nullable()->comment('travel | accommodation');
            $table->foreignId('booking_service_id')->nullable()->constrained('service_definitions')->nullOnDelete();
            $table->string('booked_by', 16)->default('employee')->comment('employee | company | either');
            // Eligibility.
            $table->boolean('before_joining_allowed')->default(false);
            $table->json('attendance_rule')->nullable()->comment('{mode, min_minutes, on_duty, on_duty_types}; null = no restriction');
            $table->json('submission_window_days')->nullable()->comment('{from, to} days after the expense date');
            $table->boolean('declaration_required')->default(false);
            $table->text('declaration_text')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->string('status', 16)->default('active');
            $table->timestamps();

            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'status', 'type'], 'exp_cat_tenant_status_type_idx');
        });

        Schema::create('expense_category_limits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('expense_category_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('dimension_value_id')->nullable()->comment('id in the category\'s limit_dimension; null = every other employee');
            $table->foreignId('expense_location_group_id')->nullable()->constrained()->restrictOnDelete();
            $table->decimal('amount', 14, 2)->nullable();
            $table->decimal('percent', 5, 2)->nullable()->comment('percent of CTC (or of the category\'s CTC component)');
            $table->decimal('km_limit', 10, 2)->nullable();
            $table->string('period', 16)->default('per_claim');
            $table->string('match_key', 64)->comment('dimension value|location group (empty = any): one row per combination');
            $table->timestamps();

            $table->unique(['expense_category_id', 'match_key'], 'exp_cat_limits_match_unique');
        });

        Schema::create('expense_tax_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name', 60);
            $table->string('code', 32);
            $table->decimal('default_rate', 5, 2)->nullable()->comment('a suggestion only; the receipt carries the rate');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->string('status', 16)->default('active');
            $table->timestamps();

            $table->unique(['tenant_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expense_tax_types');
        Schema::dropIfExists('expense_category_limits');
        Schema::dropIfExists('expense_categories');
        Schema::dropIfExists('expense_location_group_places');
        Schema::dropIfExists('expense_location_groups');
        Schema::dropIfExists('expense_groups');
    }
};
