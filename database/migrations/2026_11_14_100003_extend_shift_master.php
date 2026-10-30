<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HROne setup parity (TO-5): the shift master's timings, absence criteria and advanced rules (colour, half lengths,
 * how early check-in counts, absent halves, time before the start and after a cut-off not counted, which requests a
 * shift allows, shift allowance), and automatic shift correction on work schedules.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shifts', function (Blueprint $table) {
            $table->string('colour', 16)->nullable()->after('code');
            $table->unsignedSmallInteger('first_half_minutes')->nullable()->after('half_day_minutes')->comment('null = half of the shift');
            $table->unsignedSmallInteger('early_checkin_minutes')->nullable()->after('grace_out_minutes')->comment('how early before the start a check-in counts; null = the organisation punch window');
            $table->unsignedSmallInteger('absent_first_half_after')->nullable()->comment('no check-in within N minutes of the start: first half absent');
            $table->unsignedSmallInteger('absent_full_day_after')->nullable()->comment('no check-in within N minutes of the start: absent');
            $table->unsignedSmallInteger('absent_second_half_before')->nullable()->comment('check-out N minutes or more before the end: second half absent');
            $table->boolean('ignore_before_start')->default(false)->comment('time before the shift start is not counted');
            $table->time('cutoff_time')->nullable()->comment('time after this is not counted');
            $table->boolean('requestable')->default(true)->comment('offered in shift change requests');
            $table->boolean('allow_short_leave')->default(true);
            $table->boolean('allow_regularisation')->default(true);
            $table->boolean('allow_on_duty')->default(true);
            $table->boolean('allow_leave')->default(true);
            $table->boolean('allowance_eligible')->default(false)->comment('days worked on this shift earn the shift allowance');
        });

        Schema::table('work_schedules', function (Blueprint $table) {
            $table->json('auto_shift_ids')->nullable()->after('pattern')->comment('shifts tried for automatic shift correction');
            $table->boolean('auto_shift_on_off_days')->default(false)->after('auto_shift_ids');
        });
    }

    public function down(): void
    {
        Schema::table('work_schedules', fn (Blueprint $table) => $table->dropColumn(['auto_shift_ids', 'auto_shift_on_off_days']));
        Schema::table('shifts', fn (Blueprint $table) => $table->dropColumn(['colour', 'first_half_minutes', 'early_checkin_minutes', 'absent_first_half_after', 'absent_full_day_after', 'absent_second_half_before',
            'ignore_before_start', 'cutoff_time', 'requestable', 'allow_short_leave', 'allow_regularisation', 'allow_on_duty', 'allow_leave', 'allowance_eligible']));
    }
};
