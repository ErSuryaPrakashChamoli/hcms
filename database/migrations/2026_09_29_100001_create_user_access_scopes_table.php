<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 0.2 (ABAC): organisational access scopes per user. A user with no rows is tenant-wide
 * (the pre-0.2 behaviour); rows restrict the employees and organisation units the user can reach.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_access_scopes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('dimension', 32)->comment('company|location|business_unit|division|department|team');
            $table->unsignedBigInteger('scope_id');
            $table->timestamps();

            $table->unique(['user_id', 'dimension', 'scope_id']);
            $table->index(['tenant_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_access_scopes');
    }
};
