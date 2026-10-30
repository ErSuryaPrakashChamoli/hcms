<?php

namespace App\Filament\Support;

use App\Domain\Attendance\Models\Holiday;
use App\Domain\Attendance\Models\Shift;
use App\Domain\Attendance\Models\TimeOfficeRequest;
use App\Domain\Attendance\Services\HolidayResolver;
use App\Domain\Attendance\Services\TimeOfficeRequests;
use App\Domain\Configuration\Services\PolicyResolver;
use App\Domain\Employment\Models\Employee;
use Closure;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Utilities\Get;
use RuntimeException;

/** HP-1: the time-office request form (fields follow the request type) and its submit, shared by self-service and HR. */
final class TimeOfficeActions
{
    /**
     * @param  Closure(): ?Employee  $employee  the employee the request is for (for restricted-holiday options)
     * @return list<Component|Field>
     */
    public static function form(Closure $employee): array
    {
        $is = fn (string ...$types) => fn (Get $get) => in_array($get('type'), $types, true);

        return [
            // HROne parity RQ-2: whose request it is.
            RequestChrome::header(fn () => $employee()),
            Select::make('type')->label('Request')->options(TimeOfficeRequest::TYPES)->required()->live()->native(false),
            DatePicker::make('from_date')->label(fn (Get $get) => match ($get('type')) {
                'on_duty', 'shift_change' => 'From', 'week_off_change' => 'Weekly off to work', default => 'Date',
            })->native(false)->required()
                ->visible(fn (Get $get) => $get('type') !== null && $get('type') !== 'restricted_holiday'),
            // HROne setup parity (TO-3): the day taken off instead of the weekly off worked.
            DatePicker::make('swap_date')->label('Day to take off instead')->native(false)->required()->different('from_date')->visible($is('week_off_change')),
            DatePicker::make('to_date')->label('To')->native(false)->afterOrEqual('from_date')
                ->visible(fn (Get $get) => $get('type') === 'shift_change' || ($get('type') === 'on_duty' && ($get('session') ?? 'full') === 'full')),
            Select::make('session')->label('Part of the day')->options(['full' => 'Full day', 'first_half' => 'First half', 'second_half' => 'Second half', 'hours' => 'Hours'])->default('full')->live()->native(false)
                ->visible($is('on_duty')),
            TimePicker::make('start_time')->label('From time')->seconds(false)->required()
                ->visible(fn (Get $get) => $get('type') === 'short_leave' || ($get('type') === 'on_duty' && $get('session') === 'hours')),
            TimePicker::make('end_time')->label('To time')->seconds(false)->required()->after('start_time')
                ->visible(fn (Get $get) => $get('type') === 'short_leave' || ($get('type') === 'on_duty' && $get('session') === 'hours')),
            TextInput::make('minutes')->label('Overtime (minutes)')->numeric()->minValue(1)->maxValue(960)->required()->visible($is('overtime')),
            Select::make('shift_id')->label('Shift to work')->options(fn () => Shift::query()->orderBy('name')->pluck('name', 'id'))->required(fn (Get $get) => $get('type') === 'shift_change')->native(false)
                ->placeholder(fn (Get $get) => $get('type') === 'week_off_change' ? 'The shift of the day taken off' : null)->visible($is('shift_change', 'week_off_change')),
            Select::make('category')->label('On-duty type')->native(false)->visible(fn (Get $get) => $get('type') === 'on_duty' && self::onDutyTypes($employee()) !== [])
                ->options(fn () => array_combine(self::onDutyTypes($employee()), self::onDutyTypes($employee())))->required(),
            Select::make('holiday_id')->label('Optional holiday')->required()->native(false)->visible($is('restricted_holiday'))
                ->options(fn () => self::optionalHolidays($employee())),
            Textarea::make('reason')->required()->maxLength(500)->rows(2),
            FileUpload::make('attachments')->label('Supporting documents')->multiple()->maxFiles(5)->disk(config('peopleos.documents.disk', 'local'))->directory('time-office')->visibility('private')
                ->acceptedFileTypes(['image/jpeg', 'image/png', 'application/pdf'])->maxSize((int) config('peopleos.documents.max_kb', 10240))
                ->visible(fn (Get $get) => in_array($get('type'), ['on_duty', 'short_leave', 'shift_change', 'week_off_change'], true)),
            // HROne parity RQ-2: who approves the chosen kind (a read-only preview), who is informed, and the attendance policy.
            RequestChrome::route(fn (Get $get) => filled($get('type')) ? 'time_office.'.$get('type') : null, fn () => $employee()),
            // HROne parity RQ-1: the person's own open requests of the time-office family (hidden on an on-behalf form).
            PendingRequestsPanel::make(fn () => $employee()),
        ];
    }

    /** @return list<string> the on-duty types the employee's time-office policy offers (HROne setup parity) */
    public static function onDutyTypes(?Employee $employee): array
    {
        if ($employee === null || ! $employee->exists) {
            return [];
        }

        return array_values(array_filter((array) (app(PolicyResolver::class)->resolve('time_office', $employee, now())?->setting('on_duty_types') ?? [])));
    }

    /** @param  array<string, mixed>  $data */
    public static function submit(Employee $employee, array $data): void
    {
        try {
            $request = app(TimeOfficeRequests::class)->request($employee, (string) $data['type'], $data, (string) $data['reason']);
            RequestChrome::confirmed('time_office.'.$request->type, $request, $request->status === 'approved' ? $request->typeLabel().' approved' : $request->typeLabel().' sent for approval');
        } catch (RuntimeException $e) {
            Notification::make()->danger()->title('Not sent')->body($e->getMessage())->send();
        }
    }

    /** @return array<int, string> the employee's optional holidays from today on */
    public static function optionalHolidays(?Employee $employee): array
    {
        if ($employee === null || ! $employee->exists) {
            return [];
        }
        $calendar = app(HolidayResolver::class)->calendarFor($employee);
        if ($calendar === null || ! $calendar->optional_requires_selection) {
            return [];
        }

        return Holiday::query()->where('holiday_calendar_id', $calendar->id)->where('type', 'optional')->whereDate('date', '>=', now()->startOfYear()->toDateString())
            ->orderBy('date')->get()->mapWithKeys(fn (Holiday $h) => [$h->id => $h->date->format('D, d M Y').' · '.$h->name])->all();
    }
}
