<?php

namespace App\Filament\Resources\Reports\RelationManagers;

use App\Domain\Analytics\Models\ReportSchedule;
use App\Domain\Identity\Models\User;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class SchedulesRelationManager extends RelationManager
{
    protected static string $relationship = 'schedules';

    protected static ?string $title = 'Schedules';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return auth()->user()?->can('update', $ownerRecord) ?? false;
    }

    public function form(Schema $schema): Schema
    {
        return $schema->columns(3)->components([
            Select::make('frequency')->options(config('peopleos.analytics.schedule_frequencies'))->default('weekly')->required()->live(),
            Select::make('day_of_week')->options([1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'])->default(1)->visible(fn (Get $get) => $get('frequency') === 'weekly'),
            TextInput::make('day_of_month')->numeric()->minValue(1)->maxValue(28)->default(1)->visible(fn (Get $get) => $get('frequency') === 'monthly'),
            TextInput::make('time')->default('07:00')->required()->regex('/^\d{2}:\d{2}$/')->helperText('24-hour, server time'),
            Select::make('recipient_user_ids')->label('Recipients')->multiple()->required()->searchable()->options(fn () => User::forCurrentTenant()->pluck('name', 'id')->all()),
            Select::make('status')->options(['active' => 'Active', 'paused' => 'Paused'])->default('active')->required(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('frequency')->badge()->color('gray'),
                TextColumn::make('when')->label('When')->state(fn (ReportSchedule $record) => match ($record->frequency) {
                    'weekly' => ['', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'][$record->day_of_week ?? 1].' '.$record->time, 'monthly' => 'Day '.$record->day_of_month.' at '.$record->time, default => 'Every day at '.$record->time
                }),
                TextColumn::make('recipients')->state(fn (ReportSchedule $record) => User::forCurrentTenant()->whereIn('id', $record->recipient_user_ids ?? [])->pluck('name')->implode(', '))->wrap(),
                TextColumn::make('last_run_at')->dateTime()->placeholder('Never'),
                TextColumn::make('next_run_at')->dateTime()->placeholder('—'),
                TextColumn::make('status')->badge()->color(fn (string $state) => $state === 'active' ? 'success' : 'gray'),
            ])
            ->headerActions([CreateAction::make()->label('Add schedule')])
            ->recordActions([EditAction::make(), DeleteAction::make()]);
    }
}
