<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The permission catalogue is platform-owned (config/peopleos.php) and synced here so
        // tenants can attach permissions to roles; tenants never define new permission keys.
        Schema::create('permissions', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('module', 64);
            $table->string('description')->nullable();
            $table->timestamps();

            $table->index('module');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('permissions');
    }
};
