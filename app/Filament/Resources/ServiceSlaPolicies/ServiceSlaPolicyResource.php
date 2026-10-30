<?php

namespace App\Filament\Resources\ServiceSlaPolicies;

use App\Domain\Identity\Models\Role;
use App\Domain\ServiceDesk\Models\ServiceSlaPolicy;
use App\Filament\RelationManagers\AuditHistoryRelationManager;
use App\Filament\Resources\ServiceSlaPolicies\Pages\CreateServiceSlaPolicy;
use App\Filament\Resources\ServiceSlaPolicies\Pages\EditServiceSlaPolicy;
use App\Filament\Resources\ServiceSlaPolicies\Pages\ListServiceSlaPolicies;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Phase 12: SLA policies — per-priority first-response and resolution targets in business or calendar
 * hours, with a warning threshold, escalation and pause statuses. Business hours use the employee's
 * attendance holiday calendar and work-location timezone. A policy in use by an approved service is
 * not edited: create a new one from a later date.
 */
class ServiceSlaPolicyResource extends Resource
{
    protected static ?string $model = ServiceSlaPolicy::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    protected static string|UnitEnum|null $navigationGroup = 'Service Desk';

    protected static ?string $navigationLabel = 'SLA policies';

    protected static ?string $modelLabel = 'SLA policy';

    protected static ?int $navigationSort = 6;

    public static function form(Schema $schema): Schema
    {
        $targets = collect(config('peopleos.servicedesk.priorities'))->map(fn (string $label, string $key) => Section::make($label)->columns(3)->compact()->schema([
            TextInput::make("targets.{$key}.first_response_hours")->label('First response (hours)')->numeric()->minValue(0)->required(),
            TextInput::make("targets.{$key}.resolution_hours")->label('Resolution (hours)')->numeric()->minValue(0.25)->required(),
            // HROne setup parity HD-B: "Due days" — converted to service hours (days × the length of a service day).
            TextInput::make("targets.{$key}.resolution_days")->label('or due days')->numeric()->minValue(0)->dehydrated(false)->live(onBlur: true)
                ->helperText('Fills the resolution hours.')
                ->afterStateUpdated(function (Set $set, Get $get, $state) use ($key): void {
                    if (is_numeric($state) && (float) $state > 0) {
                        $set("targets.{$key}.resolution_hours", round((float) $state * self::serviceDayHours($get('calendar'), $get('business_hours.start'), $get('business_hours.end')), 2));
                    }
                }),
        ]))->values()->all();
        $weekdays = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'];

        return $schema->columns(2)->components([
            Section::make('Policy')->columns(2)->columnSpanFull()->schema([
                TextInput::make('name')->required()->maxLength(255),
                TextInput::make('code')->required()->maxLength(32)->alphaDash(),
                Select::make('calendar')->options(['business' => 'Business hours (service days, holidays, location timezone)', 'calendar' => 'Calendar hours (24 × 7)'])->required()->default('business')->live(),
                DatePicker::make('effective_from')->native(false)->required()->default(now()),
                Select::make('status')->options(['active' => 'Active', 'inactive' => 'Inactive'])->default('active')->required(),
            ]),
            Section::make('Warnings, escalation and pauses')->columns(2)->columnSpanFull()->schema([
                TextInput::make('warn_percent')->label('Warn when this share of the time is used (%)')->numeric()->minValue(1)->maxValue(99)->default(75),
                Select::make('escalation_role_id')->label('Escalate to (role)')->options(fn () => Role::query()->orderBy('name')->pluck('name', 'id')->all()),
                TextInput::make('escalation_repeat_hours')->label('Escalate again every (hours)')->numeric()->minValue(1)->default(24),
                TextInput::make('max_escalation_level')->label('Highest escalation level')->numeric()->minValue(1)->maxValue(9)->default(3),
                CheckboxList::make('pause_statuses')->label('SLA paused while (empty = defaults)')->options(collect(config('peopleos.servicedesk.statuses'))->only(['waiting_employee', 'awaiting_approval', 'waiting_hr'])->all())->columnSpanFull(),
            ]),
            // HROne setup parity HD-B: the resolver's working days ("will not resolve tickets on" = the unticked days) and hours.
            Section::make('Service days and working hours')->columns(2)->columnSpanFull()
                ->description('Blank: the organisation default ('.self::defaultHoursText().'). Holidays come from the employee\'s holiday calendar.')
                ->visible(fn (Get $get) => $get('calendar') !== 'calendar')
                ->schema([
                    CheckboxList::make('business_hours.days')->label('Resolvers work on')->options($weekdays)->columns(7)->columnSpanFull()
                        ->helperText('Unticked days are the resolvers\' weekly offs: the SLA clock does not run on them.'),
                    TimePicker::make('business_hours.start')->label('Start time')->seconds(false),
                    TimePicker::make('business_hours.end')->label('End time')->seconds(false),
                ]),
            Section::make('Targets per priority')->columns(2)->columnSpanFull()->schema($targets),
        ]);
    }

    /** Hours in one service day: calendar policies count 24, business policies their (or the default) working hours. */
    public static function serviceDayHours(?string $calendar, ?string $start, ?string $end): float
    {
        if ($calendar === 'calendar') {
            return 24.0;
        }
        $default = (array) config('peopleos.servicedesk.business_hours');
        $minutes = fn (string $t) => ((int) substr($t, 0, 2)) * 60 + (int) substr($t, 3, 2);
        $length = $minutes((string) ($end ?: $default['end'])) - $minutes((string) ($start ?: $default['start']));

        return $length > 0 ? $length / 60 : 9.0;
    }

    private static function defaultHoursText(): string
    {
        $default = (array) config('peopleos.servicedesk.business_hours');
        $names = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'];

        return implode(', ', array_map(fn ($d) => $names[(int) $d] ?? $d, (array) ($default['days'] ?? []))).' '.($default['start'] ?? '').'–'.($default['end'] ?? '');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable(),
                TextColumn::make('code'),
                TextColumn::make('calendar')->badge(),
                TextColumn::make('normal')->label('Normal: response / resolution')->state(fn (ServiceSlaPolicy $record) => ($t = $record->targetFor('normal'))['first_response_hours'].'h / '.$t['resolution_hours'].'h'),
                TextColumn::make('hours')->label('Service days and hours')->state(fn (ServiceSlaPolicy $record) => $record->calendar === 'calendar' ? '24 × 7' : ($record->business_hours ? implode(',', array_map(fn ($d) => ['', 'Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa', 'Su'][(int) $d] ?? $d, $record->business_hours['days'] ?? [])).' '.($record->business_hours['start'] ?? '').'–'.($record->business_hours['end'] ?? '') : 'Default'))->toggleable(),
                TextColumn::make('effective_from')->date(),
                TextColumn::make('status')->badge()->color(fn (string $state) => $state === 'active' ? 'success' : 'gray'),
            ])
            ->recordActions([EditAction::make()]);
    }

    public static function getRelations(): array
    {
        return [AuditHistoryRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListServiceSlaPolicies::route('/'),
            'create' => CreateServiceSlaPolicy::route('/create'),
            'edit' => EditServiceSlaPolicy::route('/{record}/edit'),
        ];
    }
}
