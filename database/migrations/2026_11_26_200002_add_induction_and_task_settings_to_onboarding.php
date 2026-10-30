<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HROne setup parity P13 / 08.2: onboarding templates of kind induction that may start N days before joining, checklist
 * items that need a profile section filled, a declaration the owner confirms, or a feedback template answered, and plans
 * of kind task_campaign for items issued to current employees. Existing templates and plans are kind onboarding.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('onboarding_templates', function (Blueprint $table) {
            $table->string('kind', 16)->default('onboarding')->after('key')->comment('onboarding|induction');
            $table->unsignedSmallInteger('starts_days_before_joining')->nullable()->after('kind');
        });

        Schema::table('onboarding_template_items', function (Blueprint $table) {
            $table->string('profile_section', 32)->nullable()->after('form_id');
            $table->foreignId('feedback_template_id')->nullable()->after('profile_section')->constrained()->nullOnDelete();
            $table->text('declaration_text')->nullable()->after('feedback_template_id');
        });

        Schema::table('onboarding_plans', function (Blueprint $table) {
            $table->string('kind', 16)->default('onboarding')->after('onboarding_template_id')->comment('onboarding|induction|task_campaign');
        });

        Schema::table('onboarding_tasks', function (Blueprint $table) {
            $table->string('profile_section', 32)->nullable()->after('form_id');
            $table->foreignId('feedback_template_id')->nullable()->after('profile_section')->constrained()->nullOnDelete();
            $table->text('declaration_text')->nullable()->after('feedback_template_id');
            $table->timestamp('declared_at')->nullable()->after('declaration_text');
            $table->string('declared_ip', 45)->nullable()->after('declared_at');
        });
    }

    public function down(): void
    {
        Schema::table('onboarding_tasks', function (Blueprint $table) {
            $table->dropConstrainedForeignId('feedback_template_id');
            $table->dropColumn(['profile_section', 'declaration_text', 'declared_at', 'declared_ip']);
        });
        Schema::table('onboarding_plans', fn (Blueprint $table) => $table->dropColumn('kind'));
        Schema::table('onboarding_template_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('feedback_template_id');
            $table->dropColumn(['profile_section', 'declaration_text']);
        });
        Schema::table('onboarding_templates', fn (Blueprint $table) => $table->dropColumn(['kind', 'starts_days_before_joining']));
    }
};
