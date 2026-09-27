<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // MySQL DDL is not transactional: guard every column so a partial earlier run can be completed.
        Schema::table('users', function (Blueprint $table) {
            foreach ([
                'locale' => fn () => $table->string('locale', 8)->nullable(),
                'app_authentication_secret' => fn () => $table->text('app_authentication_secret')->nullable(),
                'app_authentication_recovery_codes' => fn () => $table->text('app_authentication_recovery_codes')->nullable(),
                'password_changed_at' => fn () => $table->timestamp('password_changed_at')->nullable(),
                'sso_subject' => fn () => $table->string('sso_subject')->nullable(),
                'sso_connection_id' => fn () => $table->foreignId('sso_connection_id')->nullable(),
                'external_id' => fn () => $table->string('external_id')->nullable()->comment('SCIM externalId'),
            ] as $column => $add) {
                if (! Schema::hasColumn('users', $column)) {
                    $add();
                }
            }
        });

        Schema::table('tenants', function (Blueprint $table) {
            if (! Schema::hasColumn('tenants', 'tier')) {
                $table->string('tier', 16)->default('shared');
            }
            if (! Schema::hasColumn('tenants', 'region')) {
                $table->string('region', 8)->nullable();
            }
        });

        Schema::create('sso_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('provider', 16)->default('oidc');
            $table->string('slug', 64);
            $table->string('client_id');
            $table->text('client_secret');
            $table->string('authorization_url');
            $table->string('token_url');
            $table->string('userinfo_url');
            $table->string('scopes')->default('openid profile email');
            $table->json('allowed_domains')->nullable();
            $table->boolean('auto_provision')->default(true);
            $table->foreignId('default_role_id')->nullable()->constrained('roles')->nullOnDelete();
            $table->boolean('enforce')->default(false);
            $table->string('status', 16)->default('active');
            $table->timestamp('last_login_at')->nullable();
            $table->timestamps();

            $table->unique('slug');
        });

        Schema::create('webhook_endpoints', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('url');
            $table->text('secret');
            $table->json('events');
            $table->string('status', 16)->default('active');
            $table->timestamp('last_delivered_at')->nullable();
            $table->unsignedInteger('failure_count')->default(0);
            $table->timestamps();
        });

        Schema::create('webhook_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('webhook_endpoint_id')->constrained()->cascadeOnDelete();
            $table->string('event', 64);
            $table->string('event_id', 40);
            $table->json('payload');
            $table->string('status', 16)->default('pending');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->unsignedSmallInteger('response_code')->nullable();
            $table->text('response_excerpt')->nullable();
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status', 'next_attempt_at']);
        });

        Schema::create('exchange_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->char('from_currency', 3);
            $table->char('to_currency', 3);
            $table->decimal('rate', 18, 8);
            $table->date('effective_on');
            $table->string('source', 32)->default('manual');
            $table->timestamps();

            $table->unique(['tenant_id', 'from_currency', 'to_currency', 'effective_on'], 'exchange_rate_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exchange_rates');
        Schema::dropIfExists('webhook_deliveries');
        Schema::dropIfExists('webhook_endpoints');
        Schema::dropIfExists('sso_connections');
        Schema::table('tenants', fn (Blueprint $table) => $table->dropColumn(['tier', 'region']));
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['locale', 'app_authentication_secret', 'app_authentication_recovery_codes', 'password_changed_at', 'sso_subject', 'sso_connection_id', 'external_id']));
    }
};
