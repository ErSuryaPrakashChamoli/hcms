<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Birthdays. The date of birth on official papers is not always the day a person celebrates, so a person may carry a
 * celebrated birthday (day and month, no year). birthday_key (month * 100 + day) is derived by the Person model from
 * the celebrated birthday, else the date of birth, so "whose birthday is on / in" is one indexed query on every
 * database. Wishes colleagues send on the day are kept per sender, recipient and year (one each).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('people', function (Blueprint $table) {
            $table->unsignedTinyInteger('birthday_month')->nullable()->after('date_of_birth');
            $table->unsignedTinyInteger('birthday_day')->nullable()->after('birthday_month');
            $table->unsignedSmallInteger('birthday_key')->nullable()->after('birthday_day');
            $table->boolean('birthday_shared')->default(true)->after('birthday_key');

            $table->index(['tenant_id', 'birthday_key']);
        });

        DB::table('people')->whereNotNull('date_of_birth')->select(['id', 'date_of_birth'])->chunkById(500, function ($rows) {
            foreach ($rows as $row) {
                $dob = Carbon::parse($row->date_of_birth);
                DB::table('people')->where('id', $row->id)->update(['birthday_key' => $dob->month * 100 + $dob->day]);
            }
        });

        Schema::create('birthday_wishes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sender_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('sender_employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->unsignedSmallInteger('year');
            $table->text('message');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->unique(['employee_id', 'sender_user_id', 'year']);
            $table->index(['tenant_id', 'employee_id', 'year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('birthday_wishes');

        Schema::table('people', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'birthday_key']);
            $table->dropColumn(['birthday_month', 'birthday_day', 'birthday_key', 'birthday_shared']);
        });
    }
};
