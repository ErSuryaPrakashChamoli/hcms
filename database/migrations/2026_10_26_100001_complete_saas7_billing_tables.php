<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SaaS.7 completion (the approved commercial decisions B-1 to B-15). Additive only; no row is written: no price,
 * period, notice, approval, credit note, refund or TDS claim exists until an operator (or the billing run, for
 * subscriptions an operator priced) creates one.
 *
 * - plan_price_versions.minimum_quantity (B-1): optional floor of billable employees (0 = none).
 * - subscription_billing_terms.committed_quantity (B-3 annual) and price_notice_id (B-15).
 * - billing_periods (B-2/B-3): one calculated period (monthly in arrears, annual in advance, monthly true-up) with
 *   its frozen quantity evidence and the invoice it drafted; unique per subscription, kind and start.
 * - price_change_notices (B-15): written notice of a price increase, at least 30 days before it takes effect.
 * - financial_approvals (B-13): maker-checker requests (platform-level; subject_tenant_id names the tenant).
 * - credit_notes (B-12): issued credit notes (own number series), referencing their invoice.
 * - refunds (B-12): money returned against an approved credit note, referencing the payment.
 * - invoice_tds_claims (B-11): customer TDS declared by an operator (one per invoice), certified by its certificate reference.
 * - invoice_lines: the billing period, days billed and the frozen quantity evidence a generated line was priced from.
 * - invoices: closure (credited / written off) fields.
 * - payments: provider transaction reference (refunds) and the settlement FX snapshot (B-14), separate from the
 *   invoice and payment amounts, which never change.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plan_price_versions', function (Blueprint $table) {
            $table->unsignedInteger('minimum_quantity')->default(0)->after('unit_amount_minor');
        });

        Schema::table('subscription_billing_terms', function (Blueprint $table) {
            $table->unsignedInteger('committed_quantity')->nullable()->after('basis');
            $table->unsignedBigInteger('price_notice_id')->nullable()->after('committed_quantity');
        });

        Schema::create('billing_periods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('subscription_id')->constrained('tenant_subscriptions')->restrictOnDelete();
            $table->foreignId('billing_term_id')->constrained('subscription_billing_terms')->restrictOnDelete();
            $table->foreignId('plan_price_version_id')->constrained()->restrictOnDelete();
            $table->foreignId('plan_version_id')->constrained()->restrictOnDelete();
            $table->foreignId('market_id')->constrained('billing_markets')->restrictOnDelete();
            $table->string('kind', 24); // monthly_arrears | annual_advance | annual_true_up
            $table->date('period_start');
            $table->date('period_end');
            $table->unsignedSmallInteger('days_in_period');
            $table->unsignedSmallInteger('days_billed');
            $table->char('currency', 3);
            $table->bigInteger('unit_amount_minor');
            $table->unsignedInteger('minimum_quantity');
            $table->unsignedInteger('committed_quantity')->nullable();
            $table->unsignedInteger('measured_peak')->nullable();
            $table->unsignedInteger('billed_quantity');
            $table->bigInteger('amount_minor');
            $table->json('evidence'); // peak day, employee ids on that day, method, computed at
            $table->string('status', 16); // drafted | nothing_due | exception
            $table->string('exception', 300)->nullable();
            $table->foreignId('invoice_id')->nullable()->constrained()->restrictOnDelete();
            $table->timestamps();

            $table->unique(['subscription_id', 'kind', 'period_start'], 'billing_periods_unique');
            $table->index(['tenant_id', 'period_start'], 'billing_periods_tenant_index');
        });

        Schema::create('price_change_notices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('subscription_id')->constrained('tenant_subscriptions')->restrictOnDelete();
            $table->foreignId('from_price_version_id')->constrained('plan_price_versions')->restrictOnDelete();
            $table->foreignId('to_price_version_id')->constrained('plan_price_versions')->restrictOnDelete();
            $table->date('notice_date');
            $table->date('effective_from');
            $table->string('reference', 100)->nullable(); // the letter or e-mail sent
            $table->string('reason', 500);
            $table->string('status', 16)->default('pending'); // pending | applied
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['subscription_id', 'to_price_version_id'], 'price_change_notices_unique');
        });

        Schema::create('financial_approvals', function (Blueprint $table) {
            $table->id();
            $table->ulid('reference')->unique();
            $table->string('action', 32); // price_publication | credit_note | refund | invoice_write_off | exception_resolution
            $table->string('subject_type', 64);
            $table->unsignedBigInteger('subject_id');
            $table->unsignedBigInteger('subject_tenant_id')->nullable()->index(); // a platform record: no tenant owns it
            $table->json('payload');
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->string('status', 16); // pending | approved | rejected | withdrawn
            $table->string('correlation_key', 150)->unique();
            $table->foreignId('maker_id')->constrained('users')->restrictOnDelete();
            $table->string('maker_reason', 500);
            $table->timestamp('requested_at');
            $table->foreignId('checker_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('checker_reason', 500)->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamp('executed_at')->nullable();
            $table->string('result', 150)->nullable(); // what the execution created (e.g. a credit note number)
            $table->timestamps();

            $table->index(['status', 'action'], 'financial_approvals_status_index');
        });

        Schema::create('credit_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->ulid('reference')->unique();
            $table->foreignId('invoice_id')->constrained()->restrictOnDelete();
            $table->string('kind', 16); // full | partial
            $table->string('supplier_entity', 32);
            $table->foreignId('series_id')->constrained('invoice_number_series')->restrictOnDelete();
            $table->unsignedBigInteger('sequence');
            $table->string('number', 32);
            $table->date('issue_date');
            $table->char('currency', 3);
            $table->bigInteger('subtotal_minor');
            $table->bigInteger('tax_minor');
            $table->bigInteger('total_minor');
            $table->json('snapshot'); // credited lines and tax lines (original rates), invoice number, parties
            $table->string('reason', 500);
            $table->foreignId('approval_id')->constrained('financial_approvals')->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['series_id', 'sequence'], 'credit_notes_series_sequence_unique');
            $table->unique(['supplier_entity', 'number'], 'credit_notes_number_unique');
            $table->unique('approval_id', 'credit_notes_approval_unique');
        });

        Schema::create('refunds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->ulid('reference')->unique();
            $table->foreignId('credit_note_id')->constrained()->restrictOnDelete();
            $table->foreignId('payment_id')->constrained()->restrictOnDelete();
            $table->string('provider', 32);
            $table->string('provider_refund_reference', 191)->nullable();
            $table->bigInteger('amount_minor');
            $table->char('currency', 3);
            $table->string('status', 16); // processing | succeeded | failed
            $table->string('failure_code', 32)->nullable();
            $table->string('failure_message', 300)->nullable();
            $table->foreignId('approval_id')->constrained('financial_approvals')->restrictOnDelete();
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'provider_refund_reference'], 'refunds_provider_reference_unique');
            $table->unique('approval_id', 'refunds_approval_unique');
        });

        Schema::create('invoice_tds_claims', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('invoice_id')->constrained()->restrictOnDelete();
            $table->bigInteger('amount_minor');
            $table->char('currency', 3);
            $table->string('status', 24); // pending_certificate | certified
            $table->string('certificate_reference', 100)->nullable();
            $table->string('reason', 500);
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('certified_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('certified_at')->nullable();
            $table->timestamps();

            $table->unique('invoice_id', 'invoice_tds_claims_invoice_unique');
        });

        Schema::table('invoice_lines', function (Blueprint $table) {
            $table->foreignId('billing_period_id')->nullable()->after('period_end')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('days_billed')->nullable()->after('billing_period_id');
            $table->unsignedSmallInteger('days_in_period')->nullable()->after('days_billed');
            $table->json('quantity_evidence')->nullable()->after('days_in_period');
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->timestamp('closed_at')->nullable()->after('paid_by_payment_id'); // credited in full or written off
            $table->unsignedBigInteger('closure_approval_id')->nullable()->after('closed_at');
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->string('provider_transaction_reference', 191)->nullable()->after('provider_reference');
            $table->decimal('settlement_fx_rate', 24, 10)->nullable()->after('settlement_currency');
            $table->string('settlement_fx_source', 64)->nullable()->after('settlement_fx_rate');
            $table->timestamp('settlement_recorded_at')->nullable()->after('settlement_fx_source');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn(['provider_transaction_reference', 'settlement_fx_rate', 'settlement_fx_source', 'settlement_recorded_at']);
        });
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn(['closed_at', 'closure_approval_id']);
        });
        Schema::table('invoice_lines', function (Blueprint $table) {
            $table->dropConstrainedForeignId('billing_period_id');
            $table->dropColumn(['days_billed', 'days_in_period', 'quantity_evidence']);
        });
        Schema::dropIfExists('invoice_tds_claims');
        Schema::dropIfExists('refunds');
        Schema::dropIfExists('credit_notes');
        Schema::dropIfExists('financial_approvals');
        Schema::dropIfExists('price_change_notices');
        Schema::dropIfExists('billing_periods');
        Schema::table('subscription_billing_terms', function (Blueprint $table) {
            $table->dropColumn(['committed_quantity', 'price_notice_id']);
        });
        Schema::table('plan_price_versions', function (Blueprint $table) {
            $table->dropColumn('minimum_quantity');
        });
    }
};
