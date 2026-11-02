<?php

namespace App\Filament\Resources\Customers;

use App\Domain\Organisation\Enums\ActiveStatus;
use App\Domain\Projects\Models\Customer;
use App\Filament\RelationManagers\AuditHistoryRelationManager;
use App\Filament\Resources\Customers\Pages\CreateCustomer;
use App\Filament\Resources\Customers\Pages\EditCustomer;
use App\Filament\Resources\Customers\Pages\ListCustomers;
use App\Filament\Support\AuditReasonField;
use App\Filament\Support\TenantUnique;
use App\Support\Geography\Countries;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Data import DI-1 (Core → Customers): the organisations projects are run for. Kept apart from business partners
 * (individual contacts of vendors and partners).
 */
class CustomerResource extends Resource
{
    protected static ?string $model = Customer::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingStorefront;

    protected static string|UnitEnum|null $navigationGroup = 'Workforce';

    protected static ?int $navigationSort = 80;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $modelLabel = 'customer';

    protected static ?string $pluralModelLabel = 'customers';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Customer')->columns(2)->schema([
                TextInput::make('name')->required()->maxLength(160),
                TextInput::make('code')->required()->maxLength(32)->alphaDash()->helperText('Short unique code, used by projects and uploads.')
                    ->unique(ignoreRecord: true, modifyRuleUsing: TenantUnique::rule()),
                TextInput::make('tax_id')->label('Tax registration number')->helperText('e.g. the customer\'s GST or VAT number.')->maxLength(32),
                Select::make('status')->options(ActiveStatus::class)->default(ActiveStatus::Active)->required(),
            ]),
            Section::make('Contact')->columns(2)->schema([
                TextInput::make('contact_name')->label('Contact name')->maxLength(120),
                TextInput::make('email')->label('E-mail')->email()->maxLength(255),
                TextInput::make('phone')->tel()->maxLength(32),
                Select::make('country_code')->label('Country')->options(fn () => Countries::all())->searchable(),
                Textarea::make('address')->rows(2)->maxLength(500)->columnSpanFull(),
            ]),
            AuditReasonField::make()->visibleOn('edit'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->withCount('projects'))
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('code')->searchable()->sortable(),
                TextColumn::make('contact_name')->label('Contact')->toggleable()->placeholder('—'),
                TextColumn::make('email')->label('E-mail')->toggleable()->placeholder('—'),
                TextColumn::make('country_code')->label('Country')->toggleable()->placeholder('—'),
                TextColumn::make('projects_count')->label('Projects')->sortable(),
                TextColumn::make('status')->badge(),
            ])
            ->filters([SelectFilter::make('status')->options(ActiveStatus::class)])
            ->defaultSort('name')
            ->recordActions([EditAction::make()]);
    }

    public static function getRelations(): array
    {
        return [AuditHistoryRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCustomers::route('/'),
            'create' => CreateCustomer::route('/create'),
            'edit' => EditCustomer::route('/{record}/edit'),
        ];
    }
}
