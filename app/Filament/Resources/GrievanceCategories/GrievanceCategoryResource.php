<?php

namespace App\Filament\Resources\GrievanceCategories;

use App\Domain\Grievance\Models\GrievanceCategory;
use App\Domain\Identity\Models\Role;
use App\Domain\Organisation\Enums\ActiveStatus;
use App\Filament\Resources\GrievanceCategories\Pages\ManageGrievanceCategories;
use App\Filament\Support\AuditReasonField;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

class GrievanceCategoryResource extends Resource
{
    protected static ?string $model = GrievanceCategory::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTag;

    protected static string|UnitEnum|null $navigationGroup = 'Grievances';

    protected static ?string $navigationLabel = 'Categories & handlers';

    protected static ?int $navigationSort = 50;

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(3)->components([
            TextInput::make('name')->required()->maxLength(255),
            TextInput::make('code')->required()->maxLength(32)->alphaDash()->disabled(fn (string $operation) => $operation === 'edit')->dehydrated(),
            TextInput::make('sla_days')->label('Resolve within')->numeric()->minValue(1)->default(30)->suffix('days')->required(),
            Toggle::make('is_confidential')->label('Confidential (handlers only)')->default(true),
            Toggle::make('allow_anonymous')->label('Allow anonymous cases'),
            Select::make('status')->options(ActiveStatus::class)->default(ActiveStatus::Active)->required(),
            Select::make('handler_role_ids')->label('Handled by roles')->multiple()->options(fn () => Role::query()->orderBy('name')->pluck('name', 'id')->all())->helperText('e.g. the Internal Committee role for PoSH')->columnSpan(2),
            Textarea::make('description')->rows(2)->columnSpanFull(),
            AuditReasonField::make()->visibleOn('edit'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable(),
                TextColumn::make('code'),
                IconColumn::make('is_confidential')->label('Confidential')->boolean(),
                IconColumn::make('allow_anonymous')->label('Anonymous')->boolean(),
                TextColumn::make('handlers')->label('Handlers')->state(fn (GrievanceCategory $record) => Role::query()->whereIn('id', $record->handler_role_ids ?? [])->pluck('name')->implode(', '))->placeholder('Not set — assign manually'),
                TextColumn::make('sla_days')->label('SLA')->suffix(' d'),
                TextColumn::make('status')->badge(),
            ])
            ->recordActions([
                EditAction::make()->using(function (GrievanceCategory $record, array $data) {
                    $record->withAuditReason(AuditReasonField::extract($data))->update($data);

                    return $record;
                }),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageGrievanceCategories::route('/')];
    }
}
