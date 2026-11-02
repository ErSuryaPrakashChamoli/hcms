<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HROne parity MS-4 (matrix 22 §22.2.3 #10, #12–14): nominations per benefit. A nomination names one family member of
 * the person for one benefit (option list `nomination_benefits`) with a share; the shares of a benefit total 100
 * (checked when the set is saved, ChangeNominationsAction). A minor nominee (from the family member's date of birth)
 * carries a guardian name and relation. The family member flags `is_nominee` / `nominee_share` stay as they were.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('person_nominations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('person_id')->constrained('people')->cascadeOnDelete();
            $table->foreignId('family_member_id')->constrained('person_family_members', indexName: 'person_nominations_family_member_foreign')->cascadeOnDelete();
            $table->string('benefit', 32);
            $table->decimal('share', 5, 2);
            $table->string('address', 500)->nullable();
            $table->string('remarks', 255)->nullable();
            $table->string('guardian_name', 255)->nullable();
            $table->string('guardian_relation', 64)->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'person_id', 'benefit', 'family_member_id'], 'person_nominations_member_benefit_unique');
            $table->index(['tenant_id', 'person_id', 'benefit'], 'person_nominations_person_benefit_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('person_nominations');
    }
};
