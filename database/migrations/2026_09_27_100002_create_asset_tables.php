<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('code', 32);
            $table->boolean('requires_serial')->default(false);
            $table->boolean('is_it_asset')->default(false);
            $table->unsignedSmallInteger('default_life_months')->nullable();
            $table->string('status', 32)->default('active');
            $table->timestamps();

            $table->unique(['tenant_id', 'code']);
        });

        Schema::create('asset_models', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('asset_category_id')->constrained()->restrictOnDelete();
            $table->string('manufacturer')->nullable();
            $table->string('name');
            $table->json('specifications')->nullable();
            $table->string('status', 32)->default('active');
            $table->timestamps();
        });

        Schema::create('assets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('asset_category_id')->constrained()->restrictOnDelete();
            $table->foreignId('asset_model_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('location_id')->nullable()->constrained()->nullOnDelete();
            $table->string('asset_tag', 64);
            $table->string('name');
            $table->string('serial_number', 128)->nullable();
            $table->string('status', 32)->default('in_stock');
            $table->string('condition', 32)->default('good');
            $table->foreignId('custodian_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->date('purchase_date')->nullable();
            $table->decimal('purchase_cost', 14, 2)->nullable();
            $table->string('vendor')->nullable();
            $table->string('invoice_number', 64)->nullable();
            $table->date('warranty_until')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'asset_tag']);
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'custodian_id']);
        });

        Schema::create('asset_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('asset_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->date('assigned_on');
            $table->date('expected_return_on')->nullable();
            $table->date('returned_on')->nullable();
            $table->string('condition_out', 32)->nullable();
            $table->string('condition_in', 32)->nullable();
            $table->timestamp('acknowledged_at')->nullable();
            $table->string('status', 32)->default('active');
            $table->text('note')->nullable();
            $table->text('return_note')->nullable();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'employee_id', 'status']);
        });

        Schema::create('asset_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('asset_id')->constrained()->cascadeOnDelete();
            $table->string('type', 32);
            $table->foreignId('from_employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->foreignId('to_employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->foreignId('from_location_id')->nullable()->constrained('locations')->nullOnDelete();
            $table->foreignId('to_location_id')->nullable()->constrained('locations')->nullOnDelete();
            $table->string('status_after', 32)->nullable();
            $table->text('note')->nullable();
            $table->timestamp('occurred_at');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['asset_id', 'occurred_at']);
        });

        Schema::create('asset_repairs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('asset_id')->constrained()->cascadeOnDelete();
            $table->string('vendor')->nullable();
            $table->text('issue');
            $table->date('sent_on');
            $table->date('returned_on')->nullable();
            $table->decimal('cost', 14, 2)->nullable();
            $table->text('resolution')->nullable();
            $table->string('status', 32)->default('open');
            $table->timestamps();
        });

        Schema::create('asset_disposals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('asset_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('method', 32);
            $table->date('disposed_on');
            $table->decimal('value', 14, 2)->nullable();
            $table->string('reference')->nullable();
            $table->text('note')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['asset_disposals', 'asset_repairs', 'asset_movements', 'asset_assignments', 'assets', 'asset_models', 'asset_categories'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
