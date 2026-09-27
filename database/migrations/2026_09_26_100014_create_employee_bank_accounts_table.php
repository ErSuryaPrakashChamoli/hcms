<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_bank_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->string('account_holder_name');
            $table->string('bank_name');
            $table->string('branch_name')->nullable();
            $table->text('account_number');
            $table->string('account_number_last4', 4)->nullable();
            $table->string('ifsc', 16)->nullable();
            $table->string('account_type', 32)->nullable();
            $table->boolean('is_primary')->default(false);
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'employee_id', 'is_primary']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_bank_accounts');
    }
};
