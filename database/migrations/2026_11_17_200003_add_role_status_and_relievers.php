<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HROne setup parity (Automation → Role): a role can be switched off (an inactive role grants no permissions and is
 * given no new workflow tasks) and can name a reliever, the person who takes the role's approvals while nobody active
 * holds it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->string('status', 16)->default('active')->after('is_system');
            $table->foreignId('reliever_user_id')->nullable()->after('status')
                ->constrained('users', indexName: 'roles_reliever_user_fk')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->dropForeign('roles_reliever_user_fk');
            $table->dropColumn(['status', 'reliever_user_id']);
        });
    }
};
