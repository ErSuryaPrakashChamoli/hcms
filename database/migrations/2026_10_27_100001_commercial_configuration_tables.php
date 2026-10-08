<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SaaS.7 commercial configuration: prices, deals, statutory rules and Markedge policies are data, versioned and
 * effective-dated. Additive: no row is written (the statutory dataset is loaded by an operator command and activated
 * by a second operator; prices and deals are entered by operators).
 *
 * - tax_rules: an expiry date, evaluable conditions, a rule code, the amount basis, the statutory source (authority,
 *   reference, URL, date), the dataset it was loaded from, and rejection.
 * - supplier_profiles.registrations: registrations and undertakings of a selling entity (e.g. an Indian LUT, a UK
 *   VAT number, a US state permit) with validity, which tax conditions require.
 * - negotiated_prices / negotiated_price_versions: a customer's agreed price for its subscription (PEPM or fixed,
 *   minimum, discount, contract window), versioned and published by maker-checker like the standard catalogue.
 * - subscription_billing_terms, billing_periods, invoice_lines: the price source is a standard or a negotiated
 *   version (exactly one); periods freeze the discount and the source.
 * - configuration_versions: Markedge policies (payment terms, B2B only, notice days …) and statutory parameters
 *   (invoice-number length, a product's tax classification) as approved, effective-dated versions.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tax_rules', function (Blueprint $table) {
            $table->date('effective_to')->nullable()->after('effective_from');
            $table->string('rule_code', 64)->nullable()->after('tax_category');
            $table->json('conditions')->nullable()->after('classification');
            $table->json('statutory_notes')->nullable()->after('conditions'); // reference data recorded, not evaluated (nexus, evidence, use tax)
            $table->string('amount_basis', 16)->default('exclusive')->after('conditions');
            $table->string('source', 150)->nullable()->after('amount_basis');
            $table->string('source_reference', 500)->nullable()->after('source');
            $table->string('source_url', 500)->nullable()->after('source_reference');
            $table->date('source_date')->nullable()->after('source_url');
            $table->string('origin', 24)->default('operator')->after('source_date');
            $table->string('dataset_version', 32)->nullable()->after('origin');
            $table->string('dataset_key', 120)->nullable()->after('dataset_version');
            $table->string('dataset_status', 24)->nullable()->after('dataset_key'); // source_verified | pending_verification
            $table->foreignId('rejected_by')->nullable()->after('retired_at')->constrained('users')->nullOnDelete();
            $table->timestamp('rejected_at')->nullable()->after('rejected_by');

            $table->unique(['dataset_version', 'dataset_key'], 'tax_rules_dataset_unique');
        });

        Schema::table('billing_supplier_profiles', function (Blueprint $table) {
            $table->json('registrations')->nullable()->after('tax_id_value');
        });

        Schema::create('negotiated_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->ulid('reference')->unique();
            $table->foreignId('subscription_id')->constrained('tenant_subscriptions')->restrictOnDelete();
            $table->foreignId('plan_version_id')->constrained()->restrictOnDelete();
            $table->foreignId('market_id')->constrained('billing_markets')->restrictOnDelete();
            $table->char('currency', 3);
            $table->string('interval', 8);
            $table->string('basis', 32);
            $table->date('contract_start');
            $table->date('contract_end')->nullable();
            $table->string('contract_reference', 100)->nullable();
            $table->text('notes')->nullable();
            $table->string('reason', 500);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['subscription_id', 'plan_version_id', 'market_id', 'interval', 'contract_start'], 'negotiated_prices_unique'); // windows never overlap (NegotiatedPrices)
        });

        Schema::create('negotiated_price_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('negotiated_price_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->string('status', 16); // draft | published | retired
            $table->char('currency', 3);
            $table->bigInteger('unit_amount_minor');
            $table->unsignedInteger('minimum_quantity')->default(0);
            $table->decimal('discount_percent', 7, 4)->nullable();
            $table->foreignId('based_on_price_version_id')->nullable()->constrained('plan_price_versions')->nullOnDelete();
            $table->date('effective_from')->nullable();
            $table->string('reason', 500);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->foreignId('retired_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('retired_at')->nullable();
            $table->timestamps();

            $table->unique(['negotiated_price_id', 'version'], 'negotiated_price_versions_unique');
        });

        Schema::table('subscription_billing_terms', function (Blueprint $table) {
            $table->unsignedBigInteger('plan_price_version_id')->nullable()->change();
            $table->unsignedBigInteger('plan_price_id')->nullable()->change();
            $table->foreignId('negotiated_price_version_id')->nullable()->after('plan_price_id')->constrained()->restrictOnDelete();
        });

        Schema::table('billing_periods', function (Blueprint $table) {
            $table->unsignedBigInteger('plan_price_version_id')->nullable()->change();
            $table->unsignedBigInteger('billing_term_id')->nullable()->change(); // a NO_PRICE_CONFIGURED exception has no terms
            $table->foreignId('negotiated_price_version_id')->nullable()->after('plan_price_version_id')->constrained()->restrictOnDelete();
            $table->string('price_source', 16)->default('standard')->after('negotiated_price_version_id'); // standard | negotiated
            $table->decimal('discount_percent', 7, 4)->nullable()->after('unit_amount_minor');
            $table->bigInteger('net_unit_amount_minor')->nullable()->after('discount_percent');
        });

        Schema::table('invoice_lines', function (Blueprint $table) {
            $table->foreignId('negotiated_price_version_id')->nullable()->after('plan_price_version_id')->constrained()->nullOnDelete();
        });

        Schema::create('configuration_versions', function (Blueprint $table) {
            $table->id();
            $table->ulid('reference')->unique();
            $table->string('domain', 24); // company_policy | statutory
            $table->string('key', 64);
            $table->string('scope', 64)->default('');
            $table->json('value');
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->unsignedInteger('version');
            $table->string('status', 16); // pending | approved | rejected | withdrawn
            $table->string('reason', 500);
            $table->string('source', 150)->nullable();
            $table->string('source_reference', 500)->nullable();
            $table->string('source_url', 500)->nullable();
            $table->date('source_date')->nullable();
            $table->string('origin', 24)->default('operator'); // operator | statutory_dataset
            $table->string('dataset_version', 32)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approval_id')->nullable()->constrained('financial_approvals')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();

            $table->unique(['domain', 'key', 'scope', 'version'], 'configuration_versions_unique');
            $table->index(['key', 'scope', 'status'], 'configuration_versions_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('configuration_versions');
        Schema::table('invoice_lines', function (Blueprint $table) {
            $table->dropConstrainedForeignId('negotiated_price_version_id');
        });
        Schema::table('billing_periods', function (Blueprint $table) {
            $table->dropConstrainedForeignId('negotiated_price_version_id');
            $table->dropColumn(['price_source', 'discount_percent', 'net_unit_amount_minor']);
        });
        Schema::table('subscription_billing_terms', function (Blueprint $table) {
            $table->dropConstrainedForeignId('negotiated_price_version_id');
        });
        Schema::dropIfExists('negotiated_price_versions');
        Schema::dropIfExists('negotiated_prices');
        Schema::table('billing_supplier_profiles', function (Blueprint $table) {
            $table->dropColumn('registrations');
        });
        Schema::table('tax_rules', function (Blueprint $table) {
            $table->dropUnique('tax_rules_dataset_unique');
            $table->dropConstrainedForeignId('rejected_by');
            $table->dropColumn(['effective_to', 'rule_code', 'conditions', 'statutory_notes', 'amount_basis', 'source', 'source_reference', 'source_url', 'source_date', 'origin',
                'dataset_version', 'dataset_key', 'dataset_status', 'rejected_at']);
        });
        // The price-version columns made nullable stay nullable: narrowing them back could fail on rows written meanwhile.
    }
};
