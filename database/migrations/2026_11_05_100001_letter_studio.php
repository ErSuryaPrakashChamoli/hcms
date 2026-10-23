<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Letter studio: signing authorities, rich-text and uploaded Word (.docx) templates, and the file a .docx letter
 * is rendered to. Existing markdown templates become rich text (their {{ variables }} are kept as typed); letters
 * already generated stay markdown, as they were frozen.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('letter_signatories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('designation')->nullable();
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->string('signature_disk', 32)->nullable();
            $table->string('signature_path')->nullable();
            $table->boolean('is_default')->default(false);
            $table->string('status', 32)->default('active');
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
        });

        Schema::table('letter_templates', function (Blueprint $table) {
            $table->string('format', 16)->default('html')->after('type');
            $table->string('file_disk', 32)->nullable()->after('body');
            $table->string('file_path')->nullable()->after('file_disk');
            $table->string('file_name')->nullable()->after('file_path');
            $table->foreignId('letter_signatory_id')->nullable()->after('requires_approval')->constrained()->nullOnDelete();
        });

        Schema::table('letters', function (Blueprint $table) {
            $table->string('format', 16)->default('markdown')->after('type');
            $table->string('file_disk', 32)->nullable()->after('body');
            $table->string('file_path')->nullable()->after('file_disk');
            $table->foreignId('letter_signatory_id')->nullable()->after('letter_template_id')->constrained()->nullOnDelete();
        });

        // Direct rows, not the model: converting the format is not an edit of the wording, so no version bump or audit.
        DB::table('letter_templates')->orderBy('id')->each(function (object $row): void {
            DB::table('letter_templates')->where('id', $row->id)->update(['body' => Str::markdown((string) $row->body), 'format' => 'html']);
        });
    }

    public function down(): void
    {
        Schema::table('letters', function (Blueprint $table) {
            $table->dropConstrainedForeignId('letter_signatory_id');
            $table->dropColumn(['format', 'file_disk', 'file_path']);
        });
        Schema::table('letter_templates', function (Blueprint $table) {
            $table->dropConstrainedForeignId('letter_signatory_id');
            $table->dropColumn(['format', 'file_disk', 'file_path', 'file_name']);
        });
        Schema::dropIfExists('letter_signatories');
    }
};
