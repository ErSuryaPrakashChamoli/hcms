<?php

namespace App\Filament\Resources\WorkforceReasons;

use App\Domain\Employment\Models\WorkforceReason;
use App\Filament\Resources\WorkforceReasons\Pages\ManageWorkforceReasons;
use App\Filament\Support\AuditReasonField;
use App\Filament\Support\TenantUnique;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

/**
 * HROne setup parity (Workforce): separation, transfer and blacklist reasons. CA-3 (Core HR → Separation reasons,
 * Blacklist reason): a separation reason's kind, who it is offered to and who must add a remark.
 */
class WorkforceReasonResource extends Resource
{
    protected static ?string $model = WorkforceReason::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedListBullet;

    protected static string|UnitEnum|null $navigationGroup = 'Admin';

    protected static ?string $navigationLabel = 'Workforce reasons';

    protected static ?string $modelLabel = 'reason';

    protected static ?int $navigationSort = 80;

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('employee.lifecycle') ?? false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            Section::make('Reason')->columns(2)->schema([
                Select::make('kind')->options(WorkforceReason::KINDS)->required()->native(false)->live(),
                TextInput::make('name')->required()->maxLength(120)
                    ->unique(ignoreRecord: true, modifyRuleUsing: fn ($rule, Get $get) => TenantUnique::rule()($rule)->where('kind', $get('kind'))),
                Select::make('separation_kind')->label('Type')->options(config('peopleos.people.separation_kinds'))->placeholder('—')->native(false)
                    ->visible(fn (Get $get) => $get('kind') === 'separation'),
            ]),
            Section::make('Offered to and remarks')->columns(2)->schema([
                Toggle::make('for_employee')->label('Offered to employees')->default(true),
                Toggle::make('for_approver')->label('Offered to HR / approvers')->default(true)->visible(fn (Get $get) => $get('kind') === 'separation'),
                Toggle::make('remark_required')->label('A remark is required'),
                Toggle::make('approver_remark_required')->label('The approver\'s remark is required')->visible(fn (Get $get) => $get('kind') === 'separation'),
            ]),
            Section::make('Status and order')->columns(2)->schema([
                Select::make('status')->options(['active' => 'Active', 'inactive' => 'Inactive'])->default('active')->required()->native(false),
                TextInput::make('sort_order')->numeric()->default(0),
            ]),
            AuditReasonField::make()->visibleOn('edit'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('kind')->badge()->formatStateUsing(fn (string $state) => WorkforceReason::KINDS[$state] ?? $state),
                TextColumn::make('name')->searchable(),
                TextColumn::make('separation_kind')->label('Type')->placeholder('—')->formatStateUsing(fn (?string $state) => config("peopleos.people.separation_kinds.{$state}", $state)),
                IconColumn::make('for_employee')->label('Employees')->boolean(),
                IconColumn::make('for_approver')->label('Approvers')->boolean(),
                IconColumn::make('remark_required')->label('Remark')->boolean(),
                IconColumn::make('approver_remark_required')->label('Approver remark')->boolean()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('status')->badge()->color(fn (string $state) => $state === 'active' ? 'success' : 'gray'),
            ])
            ->filters([SelectFilter::make('kind')->options(WorkforceReason::KINDS)])
            ->defaultSort('sort_order')
            ->recordActions([EditAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageWorkforceReasons::route('/')];
    }
}
