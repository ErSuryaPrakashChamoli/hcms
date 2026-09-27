<?php

namespace App\Filament\Resources\LeaveTypes;

use App\Domain\Leave\Models\LeaveType;
use App\Domain\Organisation\Enums\ActiveStatus;
use App\Filament\Resources\LeaveTypes\Pages\ManageLeaveTypes;
use App\Filament\Support\AuditReasonField;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\ColorColumn;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

/** Leave types (§25). Entitlements are on leave policies (Policies → type "Leave policy"). */
class LeaveTypeResource extends Resource
{
    protected static ?string $model = LeaveType::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSwatch;

    protected static string|UnitEnum|null $navigationGroup = 'Leave';

    protected static ?string $navigationLabel = 'Leave types';

    protected static ?int $navigationSort = 40;

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            TextInput::make('name')->required()->maxLength(255),
            TextInput::make('code')->required()->maxLength(16)->alphaDash()->disabled(fn (string $operation) => $operation === 'edit')->dehydrated(),
            Select::make('category')->options(config('peopleos.leave.categories'))->required(),
            Select::make('applicable_gender')->options(config('peopleos.people.genders'))->placeholder('Everyone'),
            Toggle::make('is_paid')->label('Paid')->default(true),
            Toggle::make('allow_half_day')->label('Half days allowed')->default(true),
            Toggle::make('is_encashable')->label('Encashable'),
            Textarea::make('description')->maxLength(1000)->columnSpanFull(),
            Select::make('unit')->options(config('peopleos.leave.units'))->default('days')->required()->helperText('Hourly requests are not available yet; hour-based types can be configured for later use.'),
            TextInput::make('min_request_units')->label('Minimum per request')->numeric()->minValue(0),
            TextInput::make('max_request_units')->label('Maximum per request')->numeric()->minValue(0),
            Toggle::make('requires_document')->label('Supporting document always required'),
            Toggle::make('requires_approval')->label('Requires approval')->default(true),
            Select::make('cancellation_policy')->label('Cancelling approved leave')->options(config('peopleos.leave.cancellation_policies'))->default('self')->required(),
            DatePicker::make('effective_from')->native(false),
            DatePicker::make('effective_to')->native(false)->afterOrEqual('effective_from'),
            ColorPicker::make('colour'),
            TextInput::make('sort_order')->numeric()->default(0),
            Select::make('status')->options(ActiveStatus::class)->default(ActiveStatus::Active)->required(),
            AuditReasonField::make()->visibleOn('edit'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                ColorColumn::make('colour')->label(''),
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('code'),
                TextColumn::make('category')->badge()->color('gray')->formatStateUsing(fn (string $state) => config("peopleos.leave.categories.{$state}", $state)),
                IconColumn::make('is_paid')->label('Paid')->boolean(),
                IconColumn::make('allow_half_day')->label('Half day')->boolean(),
                IconColumn::make('is_encashable')->label('Encash')->boolean(),
                TextColumn::make('unit')->badge()->color('gray')->toggleable(),
                IconColumn::make('requires_approval')->label('Approval')->boolean()->toggleable(),
                TextColumn::make('applicable_gender')->label('For')->placeholder('Everyone')->formatStateUsing(fn (?string $state) => config("peopleos.people.genders.{$state}", $state)),
                TextColumn::make('status')->badge(),
            ])
            ->defaultSort('sort_order')
            ->recordActions([
                EditAction::make()->using(function (LeaveType $record, array $data) {
                    $record->withAuditReason(AuditReasonField::extract($data))->update($data);

                    return $record;
                }),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageLeaveTypes::route('/')];
    }
}
