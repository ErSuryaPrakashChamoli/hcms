<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->string('source', 32)->default('manual')->after('lifecycle_state');
            $table->string('external_reference', 128)->nullable()->after('source');
            $table->date('expected_joining_date')->nullable()->after('joining_date');
            $table->timestamp('offer_accepted_at')->nullable()->after('expected_joining_date');

            $table->index(['tenant_id', 'external_reference']);
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'external_reference']);
            $table->dropColumn(['source', 'external_reference', 'expected_joining_date', 'offer_accepted_at']);
        });
    }
};
