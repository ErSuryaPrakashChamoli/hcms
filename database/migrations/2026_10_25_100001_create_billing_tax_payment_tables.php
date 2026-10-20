<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SaaS.7 billing, tax and payments (global-ready; India GST is one jurisdiction). Additive only and no row is
 * written: no market, price, tax rule, supplier, billing profile, invoice or payment exists until an operator
 * creates one. Money is BIGINT minor units with an ISO-4217 code on every monetary row; never a float.
 *
 * Platform catalogue (no tenant): billing_markets, plan_prices, plan_price_versions, billing_supplier_profiles,
 * tax_rules, invoice_number_series, payment_provider_events.
 * Tenant-owned (BelongsToTenant, fail-closed; the tenant key is RESTRICTED on delete because financial records
 * are retained): tenant_billing_profiles, subscription_billing_terms, invoices, invoice_lines, invoice_tax_lines,
 * payments.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_markets', function (Blueprint $table) {
            $table->id();
            $table->string('code', 16)->unique();
            $table->string('name', 120);
            $table->char('currency', 3);
            $table->json('countries'); // ISO 3166-1 alpha-2, informational (who the market is meant for)
            $table->string('supplier_entity', 32); // the Markedge entity that sells in this market
            $table->string('locale', 16); // display locale for amounts; never decides the currency
            $table->string('status', 16)->default('active');
            $table->string('reason', 500);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('plan_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_version_id')->constrained()->restrictOnDelete();
            $table->foreignId('market_id')->constrained('billing_markets')->restrictOnDelete();
            $table->string('interval', 8); // month | year
            $table->string('basis', 32); // flat | per_active_employee
            $table->string('reason', 500);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['plan_version_id', 'market_id', 'interval'], 'plan_prices_unique');
        });

        Schema::create('plan_price_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_price_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->string('status', 16); // draft | published | retired
            $table->char('currency', 3);
            $table->bigInteger('unit_amount_minor');
            $table->date('effective_from')->nullable(); // set at publication
            $table->string('reason', 500);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->foreignId('retired_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('retired_at')->nullable();
            $table->timestamps();
            $table->unsignedBigInteger('draft_price_id')->nullable()->virtualAs("CASE WHEN status = 'draft' THEN plan_price_id END");
            $table->date('published_from')->nullable()->virtualAs("CASE WHEN status <> 'draft' THEN effective_from END");

            $table->unique(['plan_price_id', 'version'], 'plan_price_versions_number_unique');
            $table->unique('draft_price_id', 'plan_price_versions_one_draft_unique');
            $table->unique(['plan_price_id', 'published_from'], 'plan_price_versions_from_unique');
        });

        Schema::create('billing_supplier_profiles', function (Blueprint $table) {
            $table->id();
            $table->string('entity_code', 32);
            $table->unsignedInteger('version');
            $table->string('legal_name', 200);
            $table->string('address_line1', 200);
            $table->string('address_line2', 200)->nullable();
            $table->string('city', 100);
            $table->string('postal_code', 20)->nullable();
            $table->char('country', 2);
            $table->string('subdivision', 8)->nullable(); // ISO 3166-2
            $table->string('tax_id_type', 32)->nullable();
            $table->string('tax_id_value', 32)->nullable();
            $table->date('effective_from');
            $table->string('reason', 500);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['entity_code', 'version'], 'billing_supplier_profiles_version_unique');
        });

        Schema::create('tax_rules', function (Blueprint $table) {
            $table->id();
            $table->string('regime', 32);
            $table->char('country', 2);
            $table->string('subdivision', 8)->default(''); // '' = the whole country
            $table->string('tax_category', 64);
            $table->unsignedInteger('version');
            $table->date('effective_from');
            $table->json('outcomes'); // {outcome key: [{type, rate}]}
            $table->string('rounding_mode', 16);
            $table->string('rounding_stage', 16);
            $table->json('classification')->nullable(); // jurisdiction metadata, e.g. {"sac": "..."}
            $table->string('status', 16); // draft | review | verified | retired
            $table->string('reason', 500);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->string('verification_reference', 200)->nullable();
            $table->string('verification_notes', 1000)->nullable();
            $table->foreignId('retired_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('retired_at')->nullable();
            $table->timestamps();

            $table->unique(['regime', 'country', 'subdivision', 'tax_category', 'version'], 'tax_rules_version_unique');
            $table->index(['regime', 'country', 'tax_category', 'status', 'effective_from'], 'tax_rules_lookup_index');
        });

        Schema::create('invoice_number_series', function (Blueprint $table) {
            $table->id();
            $table->string('supplier_entity', 32);
            $table->string('document_type', 16); // invoice
            $table->string('prefix', 16);
            $table->date('starts_on');
            $table->date('ends_on');
            $table->unsignedBigInteger('next_sequence')->default(1);
            $table->unsignedTinyInteger('padding');
            $table->unsignedTinyInteger('max_length')->nullable(); // from the jurisdiction's invoice requirements
            $table->string('status', 16)->default('open'); // open | closed
            $table->string('reason', 500);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->unique(['supplier_entity', 'document_type', 'prefix'], 'invoice_series_prefix_unique');
        });

        Schema::create('tenant_billing_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->date('effective_from');
            $table->foreignId('market_id')->constrained('billing_markets')->restrictOnDelete();
            $table->string('customer_type', 16); // business | consumer
            $table->string('legal_name', 200);
            $table->string('billing_email', 254);
            $table->string('billing_contact', 120)->nullable();
            $table->string('address_line1', 200);
            $table->string('address_line2', 200)->nullable();
            $table->string('city', 100);
            $table->string('postal_code', 20)->nullable();
            $table->char('country', 2);
            $table->string('subdivision', 8)->nullable();
            $table->string('tax_registration', 16); // registered | unregistered | not_applicable
            $table->string('tax_id_type', 32)->nullable();
            $table->string('tax_id_value', 32)->nullable();
            $table->string('tax_id_status', 16)->nullable(); // format_valid | not_validated
            $table->string('special_tax_status', 32)->nullable(); // jurisdiction-specific (e.g. India: sez, uin)
            $table->string('reason', 500);
            $table->string('reference', 100)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'version'], 'tenant_billing_profiles_version_unique');
            $table->index(['tenant_id', 'effective_from'], 'tenant_billing_profiles_from_index');
        });

        Schema::create('subscription_billing_terms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('subscription_id')->constrained('tenant_subscriptions')->restrictOnDelete();
            $table->foreignId('plan_price_version_id')->constrained()->restrictOnDelete();
            $table->foreignId('plan_price_id')->constrained()->restrictOnDelete();
            $table->foreignId('plan_version_id')->constrained()->restrictOnDelete();
            $table->foreignId('market_id')->constrained('billing_markets')->restrictOnDelete();
            $table->char('currency', 3);
            $table->string('interval', 8);
            $table->string('basis', 32);
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->string('status', 16); // active | cancelled
            $table->string('reason', 500);
            $table->string('reference', 100)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->string('close_reason', 500)->nullable();
            $table->unsignedBigInteger('superseded_by')->nullable();
            $table->timestamps();
            $table->date('live_from')->nullable()->virtualAs("CASE WHEN status = 'active' THEN effective_from END");

            $table->unique(['subscription_id', 'live_from'], 'billing_terms_live_unique');
            $table->index(['tenant_id', 'subscription_id', 'effective_from'], 'billing_terms_lookup_index');
        });

        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->ulid('reference')->unique();
            $table->string('document_type', 16)->default('invoice');
            $table->string('status', 16); // draft | issued | paid | discarded
            $table->foreignId('market_id')->constrained('billing_markets')->restrictOnDelete();
            $table->string('supplier_entity', 32);
            $table->char('currency', 3);
            $table->foreignId('subscription_id')->nullable()->constrained('tenant_subscriptions')->restrictOnDelete();
            $table->date('period_start')->nullable();
            $table->date('period_end')->nullable();
            $table->foreignId('series_id')->nullable()->constrained('invoice_number_series')->restrictOnDelete();
            $table->unsignedBigInteger('sequence')->nullable();
            $table->string('number', 32)->nullable();
            $table->date('issue_date')->nullable();
            $table->date('due_date')->nullable();
            $table->bigInteger('subtotal_minor')->default(0);
            $table->bigInteger('tax_minor')->default(0);
            $table->bigInteger('total_minor')->default(0);
            $table->string('tax_regime', 32)->nullable();
            $table->string('tax_treatment', 16)->nullable();
            $table->foreignId('billing_profile_id')->nullable()->constrained('tenant_billing_profiles')->restrictOnDelete();
            $table->foreignId('supplier_profile_id')->nullable()->constrained('billing_supplier_profiles')->restrictOnDelete();
            $table->foreignId('tax_rule_id')->nullable()->constrained('tax_rules')->restrictOnDelete();
            $table->json('snapshot')->nullable(); // supplier, customer, tax determination, presentation (at issue)
            $table->string('idempotency_key', 100)->nullable()->unique();
            $table->string('reason', 500);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('issued_at')->nullable();
            $table->foreignId('discarded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('discarded_at')->nullable();
            $table->string('discard_reason', 500)->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->unsignedBigInteger('paid_by_payment_id')->nullable();
            $table->timestamps();

            $table->unique(['series_id', 'sequence'], 'invoices_series_sequence_unique');
            $table->unique(['supplier_entity', 'number'], 'invoices_number_unique');
            $table->index(['tenant_id', 'status'], 'invoices_tenant_status_index');
            $table->index(['status', 'issue_date'], 'invoices_status_index');
        });

        Schema::create('invoice_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('invoice_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('line_no');
            $table->string('description', 300);
            $table->string('tax_category', 64);
            $table->unsignedInteger('quantity');
            $table->bigInteger('unit_amount_minor');
            $table->bigInteger('amount_minor');
            $table->char('currency', 3);
            $table->foreignId('plan_price_version_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('plan_version_id')->nullable()->constrained()->restrictOnDelete();
            $table->date('period_start')->nullable();
            $table->date('period_end')->nullable();
            $table->timestamps();

            $table->unique(['invoice_id', 'line_no'], 'invoice_lines_number_unique');
        });

        Schema::create('invoice_tax_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('invoice_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('line_no');
            $table->string('regime', 32);
            $table->char('country', 2);
            $table->string('subdivision', 8)->nullable();
            $table->string('tax_type', 16); // CGST, SGST, UTGST, IGST, VAT, …
            $table->string('treatment', 16);
            $table->decimal('rate', 9, 4);
            $table->bigInteger('taxable_minor');
            $table->bigInteger('tax_minor');
            $table->char('currency', 3);
            $table->foreignId('tax_rule_id')->constrained('tax_rules')->restrictOnDelete();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['invoice_id', 'line_no', 'tax_type'], 'invoice_tax_lines_unique');
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->ulid('reference')->unique();
            $table->foreignId('invoice_id')->constrained()->restrictOnDelete();
            $table->string('provider', 32);
            $table->string('provider_reference', 191)->nullable();
            $table->string('idempotency_key', 100)->unique();
            $table->string('method', 24)->nullable(); // card | bank_transfer | direct_debit | wallet | local | other
            $table->bigInteger('amount_minor');
            $table->char('currency', 3);
            $table->bigInteger('settlement_amount_minor')->nullable(); // recorded only (option A: billing = payment currency)
            $table->char('settlement_currency', 3)->nullable();
            $table->string('status', 16); // initiated | pending | succeeded | failed | cancelled
            $table->timestamp('initiated_at');
            $table->timestamp('completed_at')->nullable();
            $table->string('failure_code', 32)->nullable();
            $table->string('failure_message', 300)->nullable();
            $table->string('reconciliation_status', 16); // unreconciled | matched | exception | resolved
            $table->string('reconciliation_code', 32)->nullable();
            $table->string('reconciliation_note', 500)->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->string('reason', 500);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            // One open provider payment per invoice (operator-recorded transfers are reconciled at once and never hold the slot).
            $table->unsignedBigInteger('open_invoice_id')->nullable()->virtualAs("CASE WHEN status IN ('initiated', 'pending') AND provider <> 'manual' THEN invoice_id END");

            $table->unique(['provider', 'provider_reference'], 'payments_provider_reference_unique');
            $table->unique('open_invoice_id', 'payments_one_open_per_invoice');
            $table->index(['tenant_id', 'invoice_id'], 'payments_invoice_index');
            $table->index(['status', 'reconciliation_status'], 'payments_status_index');
        });

        Schema::create('payment_provider_events', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 32);
            $table->string('event_id', 191);
            $table->string('type', 64);
            $table->text('payload'); // encrypted
            $table->char('payload_sha256', 64);
            $table->string('provider_reference', 191)->nullable();
            $table->string('status', 16); // received | processing | applied | ignored | exception | failed
            $table->string('outcome', 48)->nullable();
            $table->unsignedBigInteger('resolved_tenant_id')->nullable()->index(); // resolved from a verified reference (a platform record: no tenant owns it)
            $table->unsignedBigInteger('payment_id')->nullable()->index();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('claimed_until')->nullable();
            $table->timestamp('received_at');
            $table->timestamp('processed_at')->nullable();
            $table->string('last_error', 500)->nullable();
            $table->timestamps();

            $table->unique(['provider', 'event_id'], 'payment_provider_events_unique');
            $table->index(['status', 'received_at'], 'payment_provider_events_due_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_provider_events');
        Schema::dropIfExists('payments');
        Schema::dropIfExists('invoice_tax_lines');
        Schema::dropIfExists('invoice_lines');
        Schema::dropIfExists('invoices');
        Schema::dropIfExists('subscription_billing_terms');
        Schema::dropIfExists('tenant_billing_profiles');
        Schema::dropIfExists('invoice_number_series');
        Schema::dropIfExists('tax_rules');
        Schema::dropIfExists('billing_supplier_profiles');
        Schema::dropIfExists('plan_price_versions');
        Schema::dropIfExists('plan_prices');
        Schema::dropIfExists('billing_markets');
    }
};
