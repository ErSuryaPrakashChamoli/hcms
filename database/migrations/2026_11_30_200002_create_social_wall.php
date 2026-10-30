<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HROne setup parity HP-9e (Engage → Social wall): posts (HR posts written on the wall, and posts made by the system for
 * badges, rewards, celebrations, announcements, new joiners and achievements), one reaction per person and post, and
 * comments. Moderators hide and restore posts and comments (who, when, why) and pin posts; nothing is deleted except a
 * person's own reaction. `company_id` is the company of the person the post is about (or of its author), for the
 * company-wide wall; `dedupe_key` keeps the system from posting the same event twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wall_posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('type', 24);
            $table->string('subject_type', 120)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->foreignId('employee_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('author_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title', 200);
            $table->text('body')->nullable();
            $table->json('meta')->nullable();
            $table->json('attachments')->nullable();
            $table->boolean('pinned')->default(false);
            $table->string('status', 16)->default('published');
            $table->timestamp('hidden_at')->nullable();
            $table->foreignId('hidden_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('hide_reason', 255)->nullable();
            $table->string('dedupe_key', 80)->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'dedupe_key'], 'wall_posts_dedupe_unique');
            $table->index(['tenant_id', 'status', 'created_at'], 'wall_posts_feed_index');
            $table->index(['tenant_id', 'subject_type', 'subject_id'], 'wall_posts_subject_index');
        });

        Schema::create('wall_reactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('wall_post_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('reaction', 16);
            $table->timestamps();

            $table->unique(['wall_post_id', 'user_id'], 'wall_reactions_once_unique');
        });

        Schema::create('wall_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('wall_post_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->text('body');
            $table->string('status', 16)->default('published');
            $table->timestamp('hidden_at')->nullable();
            $table->foreignId('hidden_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('hide_reason', 255)->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'wall_post_id', 'status'], 'wall_comments_post_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wall_comments');
        Schema::dropIfExists('wall_reactions');
        Schema::dropIfExists('wall_posts');
    }
};
