<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HROne parity WO-4 (matrix 23 §23.3.1 #11, "download all documents"): one employee's documents as a zip, prepared by a queued
 * job for the person who asked (the documents they may open), kept on the documents disk for a few hours, fetched only by
 * that person through a signed link. The row stays as the record of the download after the zip expires.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_document_downloads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees', 'id', 'employee_document_downloads_employee_fk')->cascadeOnDelete();
            $table->foreignId('requested_by')->constrained('users', 'id', 'employee_document_downloads_requester_fk')->cascadeOnDelete();
            $table->string('status', 16)->default('queued');
            $table->unsignedInteger('total')->default(0);
            $table->unsignedInteger('included')->default(0);
            $table->unsignedInteger('skipped')->default(0);
            $table->string('file_disk', 32)->nullable();
            $table->string('file_path')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'requested_by'], 'employee_document_downloads_tenant_requester_idx');
            $table->index(['tenant_id', 'status', 'expires_at'], 'employee_document_downloads_tenant_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_document_downloads');
    }
};
