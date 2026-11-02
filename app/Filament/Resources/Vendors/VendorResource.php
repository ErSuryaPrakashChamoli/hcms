<?php

namespace App\Filament\Resources\Vendors;

use App\Domain\Configuration\Services\OptionLists;
use App\Domain\Partners\Models\Vendor;
use App\Domain\Partners\Services\Vendors;
use App\Filament\Resources\Vendors\Pages\ManageVendors;
use App\Filament\Support\AuditReasonField;
use App\Filament\Support\PartnerForms;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
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
 * HROne parity WO-15 (setup matrix 01 §1.33, HROne Setup → Vendor): the vendor master — suppliers, agencies and consultants,
 * with their GST registration, PAN (encrypted; shown by its last four), contact and address. Business partners name the
 * vendor they belong to. Every write goes through App\Domain\Partners\Services\Vendors (validation, audit); vendors are
 * inactivated, never deleted. `partner.view` to see, `partner.manage` to add and edit.
 */
class VendorResource extends Resource
{
    protected static ?string $model = Vendor::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingStorefront;

    protected static string|UnitEnum|null $navigationGroup = 'Organisation';

    protected static ?string $navigationLabel = 'Vendors';

    protected static ?string $modelLabel = 'vendor';

    protected static ?int $navigationSort = 96;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Vendor')->columns(2)->schema([
                TextInput::make('code')->required()->maxLength(32)->regex(Vendors::CODE_PATTERN)
                    ->helperText('Letters, digits, - _ and /; unique in the organisation.'),
                TextInput::make('name')->label('Company name')->required()->maxLength(200),
                Select::make('kind')->options(fn (?Vendor $record) => self::kindOptions($record))->required()->native(false),
                Select::make('status')->options(Vendor::STATUSES)->default('active')->required()->native(false),
            ]),
            Section::make('Tax registration')->columns(2)->schema([
                TextInput::make('gstin')->label('GST registration number')->maxLength(15)
                    ->helperText('15 characters: the state code, the PAN, the entity number, Z and a check character.'),
                TextInput::make('pan')->label('PAN')->maxLength(10)
                    ->helperText(fn (?Vendor $record) => $record?->maskedPan() !== null
                        ? 'On file: '.$record->maskedPan().'. Leave blank to keep it.'
                        : 'Format ABCDE1234F. Kept encrypted; shown by its last four characters.'),
            ]),
            Section::make('Contact')->columns(3)->schema([
                TextInput::make('contact_name')->label('Contact person')->maxLength(120),
                TextInput::make('email')->label('E-mail')->email()->maxLength(255),
                TextInput::make('phone')->tel()->maxLength(32),
            ]),
            Section::make('Address')->columns(2)->schema([
                TextInput::make('address_line1')->label('Address line 1')->maxLength(255),
                TextInput::make('address_line2')->label('Address line 2')->maxLength(255),
                TextInput::make('city')->maxLength(120),
                TextInput::make('state')->label('State / region code')->maxLength(16)
                    ->datalist(fn () => array_keys((array) config('peopleos.compliance.states', [])))
                    ->helperText('For India, the state code (KA, MH …): a GST registration number must start with its code.'),
                TextInput::make('postal_code')->label('PIN / postal code')->maxLength(16),
                TextInput::make('country')->label('Country code')->required()->default('IN')->length(2),
            ]),
            AuditReasonField::make()->visibleOn('edit'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')->searchable()->sortable(),
                TextColumn::make('name')->searchable()->sortable()->description(fn (Vendor $record) => $record->contact_name),
                TextColumn::make('kind')->badge()->color('gray')->formatStateUsing(fn (?string $state) => app(OptionLists::class)->label('vendor_kinds', $state)),
                TextColumn::make('gstin')->label('GST number')->placeholder('—')->toggleable(),
                TextColumn::make('pan_last4')->label('PAN')->formatStateUsing(fn (?string $state) => $state === null ? null : str_repeat('•', 6).$state)->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('city')->description(fn (Vendor $record) => collect([$record->state, $record->country])->filter()->implode(' · '))->placeholder('—'),
                TextColumn::make('email')->label('E-mail')->placeholder('—')->toggleable(),
                TextColumn::make('phone')->placeholder('—')->toggleable(),
                TextColumn::make('business_partners_count')->label('Partners')->counts('businessPartners')->sortable()->toggleable(),
                TextColumn::make('status')->badge()->formatStateUsing(fn (string $state) => Vendor::STATUSES[$state] ?? $state)
                    ->color(fn (string $state) => $state === 'active' ? 'success' : 'gray'),
            ])
            ->filters([
                SelectFilter::make('kind')->options(fn () => app(OptionLists::class)->labels('vendor_kinds')),
                SelectFilter::make('status')->options(Vendor::STATUSES),
            ])
            ->defaultSort('name')
            ->recordActions([
                EditAction::make()->using(fn (Vendor $record, array $data, Action $action) => self::save($action, $record, $data)),
            ]);
    }

    /** Create or update through the service; a blank PAN on edit keeps the one on file. */
    public static function save(Action $action, ?Vendor $record, array $data): ?Vendor
    {
        if ($record !== null && blank($data['pan'] ?? null)) {
            unset($data['pan']);
        }

        return PartnerForms::run($action, fn () => $record === null
            ? app(Vendors::class)->create($data, auth()->user())
            : app(Vendors::class)->update($record, $data, auth()->user()));
    }

    /** @return array<string, string> the kinds offered, and the vendor's own kind when it was hidden since */
    private static function kindOptions(?Vendor $record): array
    {
        $options = app(OptionLists::class)->options('vendor_kinds');
        $current = $record?->getAttributes()['kind'] ?? null;
        if ($current !== null && ! isset($options[$current])) {
            $options[$current] = app(OptionLists::class)->label('vendor_kinds', $current);
        }

        return $options;
    }

    public static function getPages(): array
    {
        return ['index' => ManageVendors::route('/')];
    }
}
