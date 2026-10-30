<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HROne parity CA-10 (Setup → Access rights → CXO access; matrix 01 §5.3, matrix 21 §21.1.1): a dashboard can be a shared
 * view. Every new column is null on existing dashboards, which keeps them exactly as they were (role audience, live
 * figures, never e-mailed).
 *
 * - preset: the HROne dashboard the view was made from (movement, hr_ops, payroll, helpdesk, engage, nine_box,
 *   performance, survey, pulse, time_office, happiness, expense); null = a dashboard built by hand.
 * - applicability: the organisation slice the view shows, {dimension: [ids]} (null = the whole organisation). It only
 *   narrows each viewer's own access scope (AccessScopes slice), never widens it.
 * - viewer_user_ids: people the view is listed for besides its roles ("share view with"); they still need the rights.
 * - email_user_ids + email_frequency / day / time / next / last: the periodic notice with the view's link (never figures).
 * - refresh_day: day of the month (1–28) the view's payroll figures move to the payroll finalized before it; null = live.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dashboards', function (Blueprint $table) {
            $table->string('preset', 32)->nullable()->after('slug');
            $table->json('applicability')->nullable()->after('role_ids');
            $table->json('viewer_user_ids')->nullable()->after('applicability');
            $table->json('email_user_ids')->nullable()->after('viewer_user_ids');
            $table->string('email_frequency', 16)->nullable()->after('email_user_ids');
            $table->unsignedTinyInteger('email_day_of_week')->nullable()->after('email_frequency');
            $table->unsignedTinyInteger('email_day_of_month')->nullable()->after('email_day_of_week');
            $table->string('email_time', 5)->nullable()->after('email_day_of_month');
            $table->timestamp('email_next_run_at')->nullable()->after('email_time');
            $table->timestamp('email_last_run_at')->nullable()->after('email_next_run_at');
            $table->unsignedTinyInteger('refresh_day')->nullable()->after('email_last_run_at');
            $table->index(['tenant_id', 'email_next_run_at'], 'dashboards_tenant_email_next_idx');
        });
    }

    public function down(): void
    {
        Schema::table('dashboards', function (Blueprint $table) {
            $table->dropIndex('dashboards_tenant_email_next_idx');
            $table->dropColumn(['preset', 'applicability', 'viewer_user_ids', 'email_user_ids', 'email_frequency', 'email_day_of_week', 'email_day_of_month', 'email_time', 'email_next_run_at', 'email_last_run_at', 'refresh_day']);
        });
    }
};
