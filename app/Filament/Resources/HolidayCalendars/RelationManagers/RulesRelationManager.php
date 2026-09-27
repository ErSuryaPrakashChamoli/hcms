<?php

namespace App\Filament\Resources\HolidayCalendars\RelationManagers;

use App\Domain\Attendance\Models\HolidayCalendarRule;
use App\Domain\Organisation\Enums\ActiveStatus;
use App\Filament\Support\AuditReasonField;
use App\Filament\Support\RuleConditionsSchema;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class RulesRelationManager extends RelationManager
{
    protected static string $relationship = 'rules';

    protected static ?string $title = 'Applies to';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Grid::make(3)->schema([
                TextInput::make('name')->required()->maxLength(255),
                TextInput::make('priority')->numeric()->default(100),
                Select::make('status')->options(ActiveStatus::class)->default(ActiveStatus::Active)->required(),
            ]),
            RuleConditionsSchema::repeater(),
            AuditReasonField::make(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('priority')->sortable(),
                TextColumn::make('name'),
                TextColumn::make('conditions')->label('IF')->state(fn (HolidayCalendarRule $record) => RuleConditionsSchema::describe($record->conditions ?? []))->wrap(),
                TextColumn::make('status')->badge(),
            ])
            ->defaultSort('priority')
            ->headerActions([CreateAction::make()->modalWidth('4xl')->using(function (array $data, RelationManager $livewire) {
                $rule = new HolidayCalendarRule($data + ['holiday_calendar_id' => $livewire->getOwnerRecord()->id]);
                $rule->withAuditReason(AuditReasonField::extract($data))->save();

                return $rule;
            })])
            ->recordActions([
                EditAction::make()->modalWidth('4xl')->using(function (HolidayCalendarRule $record, array $data) {
                    $record->withAuditReason(AuditReasonField::extract($data))->update($data);

                    return $record;
                }),
                DeleteAction::make(),
            ]);
    }
}
