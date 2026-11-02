<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HROne parity WO-15 (matrix 23 §23.8, setup matrix 01 §1.33): the vendor master and business partners.
 *
 * - vendors: the organisation's suppliers, agencies and consultants (code unique per tenant, GST registration, PAN kept
 *   encrypted with its last four apart, a contact, an address, active / inactive). Never deleted: inactivated.
 * - business_partners: people outside the workforce who may be given a sign-in (customers, BGV agents, vendors' staff,
 *   system consultants). A partner is never an employee; user_id is set only when the partner is invited, and one sign-in
 *   belongs to at most one partner.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vendors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('code', 32);
            $table->string('name', 200);
            $table->string('kind', 32);
            $table->string('gstin', 15)->nullable();
            $table->text('pan')->nullable();
            $table->string('pan_last4', 4)->nullable();
            $table->string('contact_name', 120)->nullable();
            $table->string('email')->nullable();
            $table->string('phone', 32)->nullable();
            $table->string('address_line1')->nullable();
            $table->string('address_line2')->nullable();
            $table->string('city', 120)->nullable();
            $table->string('state', 16)->nullable();
            $table->string('postal_code', 16)->nullable();
            $table->string('country', 2)->default('IN');
            $table->string('status', 16)->default('active');
            $table->timestamps();

            $table->unique(['tenant_id', 'code'], 'vendors_tenant_code_unique');
            $table->index(['tenant_id', 'status'], 'vendors_tenant_status_idx');
        });

        Schema::create('business_partners', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('code', 32);
            $table->string('salutation', 32)->nullable();
            $table->string('first_name', 120);
            $table->string('middle_name', 120)->nullable();
            $table->string('last_name', 120)->nullable();
            $table->date('date_of_birth')->nullable();
            $table->string('gender', 32)->nullable();
            $table->string('nationality', 64)->nullable();
            $table->string('partner_type', 32);
            $table->string('isd_code', 4)->nullable();
            $table->string('mobile', 32)->nullable();
            $table->string('personal_email')->nullable();
            $table->string('official_email')->nullable();
            $table->foreignId('vendor_id')->nullable()->constrained('vendors', 'id', 'business_partners_vendor_fk')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users', 'id', 'business_partners_user_fk')->nullOnDelete();
            $table->string('status', 16)->default('active');
            $table->timestamps();

            $table->unique(['tenant_id', 'code'], 'business_partners_tenant_code_unique');
            $table->unique('user_id', 'business_partners_user_unique');
            $table->index(['tenant_id', 'partner_type', 'status'], 'business_partners_tenant_type_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_partners');
        Schema::dropIfExists('vendors');
    }
};
