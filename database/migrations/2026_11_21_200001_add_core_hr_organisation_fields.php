<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HROne setup parity — Core HR (CA-2): company contact details, industry, social links and logo; sub-departments
 * (a department's parent department); designation experience range, rich job description, hazardous flag and
 * minimum-wage skill category (a vocabulary only); the organisation's contacts (HR, decision maker, technical admin).
 * Every column is nullable or defaults to today's behaviour.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('industry', 32)->nullable()->after('legal_name')->comment('peopleos.organisation.industries');
            $table->string('phone', 32)->nullable()->after('industry');
            $table->string('fax', 32)->nullable()->after('phone');
            $table->foreignId('contact_employee_id')->nullable()->after('fax')->constrained('employees')->nullOnDelete();
            $table->json('social_links')->nullable()->after('contact_employee_id')->comment('{linkedin, facebook, x, instagram, youtube}');
            $table->string('logo_path', 255)->nullable()->after('social_links')->comment('documents disk');
        });

        Schema::table('departments', function (Blueprint $table) {
            $table->foreignId('parent_department_id')->nullable()->after('company_id')->constrained('departments')->nullOnDelete();
        });

        Schema::table('designations', function (Blueprint $table) {
            $table->decimal('min_experience_years', 4, 1)->nullable()->after('description');
            $table->decimal('max_experience_years', 4, 1)->nullable()->after('min_experience_years');
            $table->longText('job_description')->nullable()->after('max_experience_years')->comment('rich text');
            $table->boolean('is_hazardous')->default(false)->after('job_description');
            $table->string('wage_skill_category', 16)->nullable()->after('is_hazardous')->comment('peopleos.compliance.wage_skill_categories');
        });

        Schema::create('organisation_contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete()->comment('null = every company');
            $table->string('type', 24)->comment('hr|decision_maker|technical_admin');
            $table->string('name', 120);
            $table->string('email', 255)->nullable();
            $table->string('mobile', 32)->nullable();
            $table->string('designation', 120)->nullable();
            $table->string('status', 16)->default('active');
            $table->timestamps();

            $table->index(['tenant_id', 'type'], 'org_contacts_tenant_type_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organisation_contacts');
        Schema::table('designations', fn (Blueprint $table) => $table->dropColumn(['min_experience_years', 'max_experience_years', 'job_description', 'is_hazardous', 'wage_skill_category']));
        Schema::table('departments', fn (Blueprint $table) => $table->dropConstrainedForeignId('parent_department_id'));
        Schema::table('companies', function (Blueprint $table) {
            $table->dropConstrainedForeignId('contact_employee_id');
            $table->dropColumn(['industry', 'phone', 'fax', 'social_links', 'logo_path']);
        });
    }
};
