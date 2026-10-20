<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SaaS.7 configuration closure. Additive; existing rows keep their meaning.
 *
 * - tax_rules.locality: a rule may apply to one local tax jurisdiction (county, city, district) inside a
 *   subdivision ('' = the whole subdivision or country). US local rates become data: a state rule that requires
 *   local rates is completed by the verified rule of the customer's locality. The version is unique per scope,
 *   locality included.
 * - tenant_billing_profiles.tax_locality: the customer's local tax jurisdiction code, as Markedge's rate source
 *   names it (recorded by an operator; never derived).
 * - billing_supplier_profiles: status, checker and approval: a selling entity's identity and registrations (the
 *   LUT, VAT, TRN, state permits) are statutory configuration, proposed by one operator and approved by another.
 *   Existing versions are approved.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tax_rules', function (Blueprint $table) {
            $table->string('locality', 40)->default('')->after('subdivision');
        });
        Schema::table('tax_rules', function (Blueprint $table) {
            $table->dropUnique('tax_rules_version_unique');
            $table->unique(['regime', 'country', 'subdivision', 'locality', 'tax_category', 'version'], 'tax_rules_version_unique');
        });

        Schema::table('tenant_billing_profiles', function (Blueprint $table) {
            $table->string('tax_locality', 40)->nullable()->after('subdivision');
        });

        Schema::table('billing_supplier_profiles', function (Blueprint $table) {
            $table->string('status', 16)->default('approved')->after('registrations'); // pending | approved | rejected | withdrawn
            $table->foreignId('approval_id')->nullable()->after('status')->constrained('financial_approvals')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->after('approval_id')->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable()->after('approved_by');
        });
        DB::table('billing_supplier_profiles')->update(['status' => 'approved']);
    }

    public function down(): void
    {
        Schema::table('billing_supplier_profiles', function (Blueprint $table) {
            $table->dropConstrainedForeignId('approved_by');
            $table->dropConstrainedForeignId('approval_id');
            $table->dropColumn(['status', 'approved_at']);
        });
        Schema::table('tenant_billing_profiles', function (Blueprint $table) {
            $table->dropColumn('tax_locality');
        });
        Schema::table('tax_rules', function (Blueprint $table) {
            $table->dropUnique('tax_rules_version_unique');
            $table->unique(['regime', 'country', 'subdivision', 'tax_category', 'version'], 'tax_rules_version_unique');
        });
        Schema::table('tax_rules', function (Blueprint $table) {
            $table->dropColumn('locality');
        });
    }
};
