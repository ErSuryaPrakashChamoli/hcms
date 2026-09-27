<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('articles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->string('slug', 191);
            $table->string('category', 32)->default('other');
            $table->text('summary')->nullable();
            $table->longText('body');
            $table->json('tags')->nullable();
            $table->json('audience')->nullable();
            $table->boolean('requires_acknowledgement')->default(false);
            $table->boolean('is_mandatory_reading')->default(false);
            $table->unsignedInteger('version')->default(1);
            $table->date('effective_from')->nullable();
            $table->string('status', 32)->default('draft');
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'slug']);
            $table->index(['tenant_id', 'status', 'category']);
        });

        Schema::create('article_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('article_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->string('title');
            $table->longText('body');
            $table->date('effective_from')->nullable();
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at');
            $table->timestamps();

            $table->unique(['article_id', 'version']);
        });

        Schema::create('article_reads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('article_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->timestamp('read_at');
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamps();

            $table->unique(['article_id', 'employee_id', 'version'], 'article_read_unique');
        });

        Schema::create('announcements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->string('type', 32)->default('announcement');
            $table->longText('body');
            $table->json('audience')->nullable();
            $table->boolean('is_pinned')->default(false);
            $table->boolean('requires_acknowledgement')->default(false);
            $table->timestamp('publish_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->string('status', 32)->default('draft');
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('article_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'status', 'publish_at']);
        });

        Schema::create('announcement_reads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('announcement_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->timestamp('read_at');
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamps();

            $table->unique(['announcement_id', 'employee_id']);
        });
    }

    public function down(): void
    {
        foreach (['announcement_reads', 'announcements', 'article_reads', 'article_versions', 'articles'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
