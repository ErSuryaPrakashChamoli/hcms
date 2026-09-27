<?php

namespace App\Filament\Resources\Skills;

use App\Domain\Organisation\Enums\ActiveStatus;
use App\Domain\People\Models\Skill;
use App\Filament\Resources\Skills\Pages\ManageSkills;
use App\Filament\Support\AuditReasonField;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

class SkillResource extends Resource
{
    protected static ?string $model = Skill::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSparkles;

    protected static string|UnitEnum|null $navigationGroup = 'People Setup';

    protected static ?int $navigationSort = 80;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->required()->maxLength(255),
            TextInput::make('code')->required()->maxLength(64)->alphaDash(),
            TextInput::make('category')->maxLength(64)->placeholder('technical, behavioural, domain…'),
            Select::make('status')->options(ActiveStatus::class)->default(ActiveStatus::Active)->required(),
            AuditReasonField::make()->visibleOn('edit'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('code')->searchable(),
                TextColumn::make('category')->badge()->color('gray')->placeholder('—'),
                TextColumn::make('status')->badge(),
            ])
            ->filters([SelectFilter::make('status')->options(ActiveStatus::class)])
            ->defaultSort('name')
            ->recordActions([
                EditAction::make()->using(function (Skill $record, array $data) {
                    $record->withAuditReason(AuditReasonField::extract($data))->update($data);

                    return $record;
                }),
                DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageSkills::route('/'),
        ];
    }
}
