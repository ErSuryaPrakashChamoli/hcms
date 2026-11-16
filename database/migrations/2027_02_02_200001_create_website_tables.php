<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Public website (platform-owned, no tenant_id): the marketing site, its menus and pages, and the sign-in page's
 * content, kept as whole-site revisions. Operators edit one draft, preview it, and publish it; the published
 * revision is what visitors see, and earlier ones stay as history that can be restored into the draft.
 * Enquiries are the "talk to us" form's submissions (Markedge's own prospect records).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('website_revisions', function (Blueprint $table) {
            $table->id();
            // draft (at most one: the working copy) | published (at most one: live) | archived (an earlier live copy)
            $table->string('status', 16)->default('draft');
            $table->longText('content');
            $table->string('note', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'published_at'], 'website_revisions_status');
        });

        Schema::create('website_enquiries', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('email', 190);
            $table->string('company', 160)->nullable();
            $table->string('phone', 40)->nullable();
            $table->string('team_size', 32)->nullable();
            $table->string('interest', 64)->nullable();
            $table->text('message')->nullable();
            $table->string('source_path', 190)->nullable();
            // new | contacted | closed
            $table->string('status', 16)->default('new');
            $table->string('ip_hash', 64)->nullable();
            $table->foreignId('handled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('handled_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at'], 'website_enquiries_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('website_enquiries');
        Schema::dropIfExists('website_revisions');
    }
};
