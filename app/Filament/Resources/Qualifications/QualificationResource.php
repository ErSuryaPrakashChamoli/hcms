<?php

namespace App\Filament\Resources\Qualifications;

use App\Domain\People\Models\Qualification;
use App\Filament\Resources\Qualifications\Pages\ManageQualifications;
use App\Filament\Support\AuditReasonField;
use App\Filament\Support\TenantUnique;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

/** HROne setup parity CA-3 (Employee → Qualification): qualifications and their specialisations. */
class QualificationResource extends Resource
{
    protected static ?string $model = Qualification::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAcademicCap;

    protected static string|UnitEnum|null $navigationGroup = 'People Setup';

    protected static ?string $navigationLabel = 'Qualifications';

    protected static ?int $navigationSort = 92;

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            TextInput::make('name')->required()->maxLength(120)->placeholder('B.Tech, MBA, Diploma …')
                ->unique(ignoreRecord: true, modifyRuleUsing: TenantUnique::rule()),
            Select::make('level')->options(config('peopleos.people.qualification_levels'))->native(false),
            Select::make('status')->options(['active' => 'Active', 'inactive' => 'Inactive'])->default('active')->required()->native(false),
            TextInput::make('sort_order')->numeric()->default(0),
            Repeater::make('specialisations')->relationship()->columnSpanFull()->defaultItems(0)->columns(2)
                ->schema([
                    TextInput::make('name')->required()->maxLength(120)->distinct(),
                    Select::make('status')->options(['active' => 'Active', 'inactive' => 'Inactive'])->default('active')->required()->native(false),
                ])
                ->addActionLabel('Add specialisation'),
            AuditReasonField::make()->visibleOn('edit'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->withCount('specialisations'))
            ->columns([
                TextColumn::make('name')->searchable(),
                TextColumn::make('level')->formatStateUsing(fn (?string $state) => config("peopleos.people.qualification_levels.{$state}", $state))->placeholder('—'),
                TextColumn::make('specialisations_count')->label('Specialisations'),
                TextColumn::make('status')->badge()->color(fn (string $state) => $state === 'active' ? 'success' : 'gray'),
            ])
            ->filters([SelectFilter::make('level')->options(config('peopleos.people.qualification_levels'))])
            ->defaultSort('sort_order')
            ->recordActions([
                EditAction::make()->using(function (Qualification $record, array $data) {
                    $record->withAuditReason(AuditReasonField::extract($data))->update($data);

                    return $record;
                }),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageQualifications::route('/')];
    }
}
