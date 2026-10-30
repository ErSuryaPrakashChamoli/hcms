<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HROne setup parity — Core HR (CA-3): qualification and specialisation masters (the profile keeps its text too);
 * separation reasons gain their kind (voluntary / involuntary), use by approvers and the approver remark rule; an exit
 * that bars rehire names its blacklist reason; tenant-extendable option lists (family relations, document categories).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('qualifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('level', 16)->nullable()->comment('school|diploma|graduate|postgraduate|doctorate|other');
            $table->string('status', 16)->default('active');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['tenant_id', 'name'], 'qualifications_tenant_name_unique');
        });

        Schema::create('qualification_specialisations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('qualification_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('status', 16)->default('active');
            $table->timestamps();

            $table->unique(['qualification_id', 'name'], 'qual_spec_qualification_name_unique');
        });

        Schema::table('person_qualifications', function (Blueprint $table) {
            $table->foreignId('qualification_id')->nullable()->after('person_id')->constrained()->nullOnDelete();
            $table->foreignId('specialisation_id')->nullable()->after('qualification_id')->constrained('qualification_specialisations')->nullOnDelete();
        });

        Schema::table('workforce_reasons', function (Blueprint $table) {
            $table->string('separation_kind', 16)->nullable()->after('kind')->comment('voluntary|involuntary (separation reasons)');
            $table->boolean('for_approver')->default(true)->after('for_employee')->comment('offered to HR / approvers');
            $table->boolean('approver_remark_required')->default(false)->after('remark_required');
        });

        Schema::table('exit_cases', function (Blueprint $table) {
            $table->foreignId('rehire_block_reason_id')->nullable()->after('is_rehire_eligible')->constrained('workforce_reasons')->nullOnDelete();
        });

        Schema::create('option_lists', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('list_key', 48)->comment('OptionLists::LISTS');
            $table->string('code', 48);
            $table->string('label', 120);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->string('status', 16)->default('active')->comment('inactive hides a built-in option');
            $table->timestamps();

            $table->unique(['tenant_id', 'list_key', 'code'], 'option_lists_tenant_list_code_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('option_lists');
        Schema::table('exit_cases', fn (Blueprint $table) => $table->dropConstrainedForeignId('rehire_block_reason_id'));
        Schema::table('workforce_reasons', fn (Blueprint $table) => $table->dropColumn(['separation_kind', 'for_approver', 'approver_remark_required']));
        Schema::table('person_qualifications', function (Blueprint $table) {
            $table->dropConstrainedForeignId('specialisation_id');
            $table->dropConstrainedForeignId('qualification_id');
        });
        Schema::dropIfExists('qualification_specialisations');
        Schema::dropIfExists('qualifications');
    }
};
