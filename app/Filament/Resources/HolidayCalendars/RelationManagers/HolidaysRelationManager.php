<?php

namespace App\Filament\Resources\HolidayCalendars\RelationManagers;

use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class HolidaysRelationManager extends RelationManager
{
    protected static string $relationship = 'holidays';

    public function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            DatePicker::make('date')->native(false)->required(),
            TextInput::make('name')->required()->maxLength(255),
            Select::make('type')->options(config('peopleos.attendance.holiday_types'))->default('public')->required(),
            Toggle::make('is_half_day')->label('Half day'),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('date')->date('D, d M Y')->sortable(),
                TextColumn::make('name')->searchable(),
                TextColumn::make('type')->badge()->formatStateUsing(fn (string $state) => config("peopleos.attendance.holiday_types.{$state}", $state)),
                IconColumn::make('is_half_day')->label('Half day')->boolean(),
            ])
            ->defaultSort('date')
            ->headerActions([CreateAction::make()])
            ->recordActions([EditAction::make(), DeleteAction::make()]);
    }
}
