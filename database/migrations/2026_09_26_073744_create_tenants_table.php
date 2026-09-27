<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('status', 32)->default('active');
            $table->char('country_code', 2)->default('IN');
            $table->string('timezone', 64)->default('Asia/Kolkata');
            $table->string('locale', 16)->default('en');
            $table->char('currency', 3)->default('INR');
            $table->timestamp('trial_ends_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenants');
    }
};
