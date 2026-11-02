<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Data import DI-1 (Core → Customers, Project Management → Projects): customers are organisations projects are run
 * for (not business_partners, which are individual contacts); a project has an optional customer and company, dates,
 * a manager, a billable flag and a status; project_members are the employees on it with a role, an allocation and
 * the dates they are on it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('code', 32);
            $table->string('name', 160);
            $table->string('contact_name', 120)->nullable();
            $table->string('email', 255)->nullable();
            $table->string('phone', 32)->nullable();
            $table->string('address', 500)->nullable();
            $table->char('country_code', 2)->nullable();
            $table->string('tax_id', 32)->nullable()->comment('tax registration number (GST, VAT …)');
            $table->string('status', 16)->default('active');
            $table->timestamps();

            $table->unique(['tenant_id', 'code'], 'customers_tenant_code_unique');
        });

        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('code', 32);
            $table->string('name', 160);
            $table->foreignId('customer_id')->nullable()->constrained('customers', indexName: 'projects_customer_fk')->restrictOnDelete();
            $table->foreignId('company_id')->nullable()->constrained('companies', indexName: 'projects_company_fk')->restrictOnDelete();
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->foreignId('manager_employee_id')->nullable()->constrained('employees', indexName: 'projects_manager_fk')->nullOnDelete();
            $table->boolean('is_billable')->default(false);
            $table->string('status', 16)->default('planned')->comment('planned|active|on_hold|completed|cancelled');
            $table->text('description')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'code'], 'projects_tenant_code_unique');
            $table->index(['tenant_id', 'status'], 'projects_tenant_status_idx');
        });

        Schema::create('project_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained('projects', indexName: 'project_members_project_fk')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees', indexName: 'project_members_employee_fk')->cascadeOnDelete();
            $table->string('role', 64)->nullable();
            $table->decimal('allocation_percent', 5, 2)->nullable();
            $table->date('from_date')->nullable();
            $table->date('to_date')->nullable();
            $table->timestamps();

            $table->index(['project_id', 'employee_id'], 'project_members_project_employee_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_members');
        Schema::dropIfExists('projects');
        Schema::dropIfExists('customers');
    }
};
