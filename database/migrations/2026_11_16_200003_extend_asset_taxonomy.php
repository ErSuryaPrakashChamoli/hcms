<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HROne setup parity AS-A — Asset taxonomy and masters: a third level under the asset type (category → sub type →
 * product / model) with its duplicate-serial and acknowledgement rules; the owner role and due days of a type; the
 * product's description, life, deployment type, licence basis and documents; and the acknowledgement reminder stamp.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_subtypes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('asset_category_id')->constrained()->restrictOnDelete();
            $table->string('name', 120);
            $table->string('code', 32);
            $table->boolean('allow_duplicate_serial')->default(true);
            $table->boolean('acknowledgement_required')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->string('status', 32)->default('active');
            $table->timestamps();

            $table->unique(['tenant_id', 'code']);
        });

        Schema::table('asset_categories', function (Blueprint $table) {
            $table->foreignId('owner_role_id')->nullable()->after('default_life_months')->constrained('roles')->nullOnDelete();
            $table->unsignedSmallInteger('fulfilment_days')->nullable()->after('owner_role_id');
        });

        Schema::table('asset_models', function (Blueprint $table) {
            $table->foreignId('asset_subtype_id')->nullable()->after('asset_category_id')->constrained()->nullOnDelete();
            $table->text('description')->nullable()->after('name');
            $table->unsignedSmallInteger('life_months')->nullable()->after('description');
            $table->string('deployment_type', 32)->nullable()->after('life_months')->comment('peopleos.assets.deployment_types');
            $table->string('licence_basis', 32)->nullable()->after('deployment_type')->comment('peopleos.assets.licence_bases');
            $table->json('documents')->nullable()->after('specifications')->comment('paths on the private documents disk');
        });

        Schema::table('assets', function (Blueprint $table) {
            $table->foreignId('asset_subtype_id')->nullable()->after('asset_category_id')->constrained()->nullOnDelete();
        });

        Schema::table('asset_assignments', function (Blueprint $table) {
            $table->timestamp('acknowledgement_reminded_at')->nullable()->after('acknowledged_at');
        });
    }

    public function down(): void
    {
        Schema::table('asset_assignments', function (Blueprint $table) {
            $table->dropColumn('acknowledgement_reminded_at');
        });
        Schema::table('assets', function (Blueprint $table) {
            $table->dropConstrainedForeignId('asset_subtype_id');
        });
        Schema::table('asset_models', function (Blueprint $table) {
            $table->dropConstrainedForeignId('asset_subtype_id');
            $table->dropColumn(['description', 'life_months', 'deployment_type', 'licence_basis', 'documents']);
        });
        Schema::table('asset_categories', function (Blueprint $table) {
            $table->dropConstrainedForeignId('owner_role_id');
            $table->dropColumn('fulfilment_days');
        });
        Schema::dropIfExists('asset_subtypes');
    }
};
