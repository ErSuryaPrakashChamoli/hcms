<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HROne parity GO-3 (matrix 27 §27.3.4 #2, HROne "Normalize review rating"): HR normalises the ratings of a periodic
 * review (HP-8i) per review period. The reviewer's own rating stays as submitted; the normalised rating sits next to it
 * (with the reason, who and when) and is the one the performance policy's roll-up reads. Nothing is deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('performance_period_reviews', function (Blueprint $table) {
            $table->decimal('normalised_rating', 5, 2)->nullable()->comment('HR\'s normalised rating; null = the reviewer\'s rating stands');
            $table->string('normalisation_note', 500)->nullable();
            $table->foreignId('normalised_by')->nullable()->constrained('users', 'id', 'perf_period_reviews_normalised_by_fk')->nullOnDelete();
            $table->timestamp('normalised_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('performance_period_reviews', function (Blueprint $table) {
            $table->dropForeign('perf_period_reviews_normalised_by_fk');
            $table->dropColumn(['normalised_rating', 'normalisation_note', 'normalised_by', 'normalised_at']);
        });
    }
};
