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
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
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
        $targets = collect(config('peopleos.servicedesk.priorities'))->map(fn (string $label, string $key) => Section::make($label)->columns(2)->compact()->schema([
            TextInput::make("targets.{$key}.first_response_hours")->label('First response (hours)')->numeric()->minValue(0)->required(),
            TextInput::make("targets.{$key}.resolution_hours")->label('Resolution (hours)')->numeric()->minValue(0.25)->required(),
        ]))->values()->all();

        return $schema->columns(2)->components([
            TextInput::make('name')->required()->maxLength(255),
            TextInput::make('code')->required()->maxLength(32)->alphaDash(),
            Select::make('calendar')->options(['business' => 'Business hours (service days, holidays, location timezone)', 'calendar' => 'Calendar hours (24 × 7)'])->required()->default('business'),
            DatePicker::make('effective_from')->native(false)->required()->default(now()),
            TextInput::make('warn_percent')->label('Warn when this share of the time is used (%)')->numeric()->minValue(1)->maxValue(99)->default(75),
            Select::make('escalation_role_id')->label('Escalate to (role)')->options(fn () => Role::query()->orderBy('name')->pluck('name', 'id')->all()),
            TextInput::make('escalation_repeat_hours')->label('Escalate again every (hours)')->numeric()->minValue(1)->default(24),
            TextInput::make('max_escalation_level')->label('Highest escalation level')->numeric()->minValue(1)->maxValue(9)->default(3),
            CheckboxList::make('pause_statuses')->label('SLA paused while (empty = defaults)')->options(collect(config('peopleos.servicedesk.statuses'))->only(['waiting_employee', 'awaiting_approval', 'waiting_hr'])->all())->columnSpanFull(),
            Select::make('status')->options(['active' => 'Active', 'inactive' => 'Inactive'])->default('active')->required(),
            Section::make('Targets per priority')->columns(2)->columnSpanFull()->schema($targets),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable(),
                TextColumn::make('code'),
                TextColumn::make('calendar')->badge(),
                TextColumn::make('normal')->label('Normal: response / resolution')->state(fn (ServiceSlaPolicy $record) => ($t = $record->targetFor('normal'))['first_response_hours'].'h / '.$t['resolution_hours'].'h'),
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
