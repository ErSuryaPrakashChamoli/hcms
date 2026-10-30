<?php

namespace App\Filament\Resources\DocumentTypes;

use App\Domain\Configuration\Services\OptionLists;
use App\Domain\Documents\Models\DocumentType;
use App\Domain\Organisation\Enums\ActiveStatus;
use App\Filament\Resources\DocumentTypes\Pages\ManageDocumentTypes;
use App\Filament\Support\AuditReasonField;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

class DocumentTypeResource extends Resource
{
    protected static ?string $model = DocumentType::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentDuplicate;

    protected static string|UnitEnum|null $navigationGroup = 'People Setup';

    protected static ?string $navigationLabel = 'Document types';

    protected static ?int $navigationSort = 90;

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            TextInput::make('name')->required()->maxLength(255),
            TextInput::make('code')->required()->maxLength(32)->alphaDash(),
            // HROne setup parity CA-4: categories are the organisation's list (Option lists → Document categories).
            Select::make('category')->options(fn () => app(OptionLists::class)->options('document_categories'))->required(),
            Select::make('status')->options(ActiveStatus::class)->default(ActiveStatus::Active)->required(),
            Toggle::make('requires_expiry')->label('Has an expiry date')->live(),
            TextInput::make('expiry_alert_days')->label('Expiry alert (days before)')->numeric()->minValue(0)->maxValue(365)
                ->placeholder('Organisation setting')->visible(fn (Get $get) => (bool) $get('requires_expiry')),
            Section::make('Employee')->columns(2)->compact()->columnSpanFull()->schema([
                Toggle::make('employee_can_view')->label('Employee can view')->default(true),
                Toggle::make('employee_can_upload')->label('Employee can upload')->helperText('From My HR; HR verifies it.'),
                Toggle::make('mandatory_for_employee')->label('Mandatory for every employee')->helperText('Missing ones are a reminder and appear in the Missing documents report.'),
                Toggle::make('mandatory_for_onboarding')->label('Mandatory for onboarding')->helperText('Enforced before joining when Setup → Core HR asks for it.'),
            ]),
            Section::make('Sample and profile field')->columns(2)->compact()->columnSpanFull()->schema([
                FileUpload::make('sample_path')->label('Sample file')->disk(fn () => config('peopleos.documents.disk'))->directory(fn () => DocumentType::sampleDirectory())->visibility('private')
                    ->maxSize(5120)->acceptedFileTypes(['application/pdf', 'image/jpeg', 'image/png'])->helperText('Shown in every upload dialog of this type.'),
                Textarea::make('sample_instructions')->label('Instructions')->rows(3)->maxLength(1000),
                Select::make('linked_field')->label('Profile field')->options(config('peopleos.documents.linked_fields'))->placeholder('None')->live(),
                Toggle::make('required_with_field')->label('Required when the field is entered or changed')->visible(fn (Get $get) => filled($get('linked_field'))),
            ]),
            AuditReasonField::make()->visibleOn('edit'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('code'),
                TextColumn::make('category')->badge()->color('gray')->formatStateUsing(fn (string $state) => app(OptionLists::class)->label('document_categories', $state)),
                IconColumn::make('requires_expiry')->boolean()->label('Expiry'),
                IconColumn::make('mandatory_for_onboarding')->boolean()->label('Onboarding'),
                IconColumn::make('mandatory_for_employee')->boolean()->label('Every employee')->toggleable(),
                IconColumn::make('employee_can_upload')->boolean()->label('Employee uploads')->toggleable(),
                TextColumn::make('linked_field')->label('Profile field')->placeholder('—')->toggleable(isToggledHiddenByDefault: true)
                    ->formatStateUsing(fn (?string $state) => config('peopleos.documents.linked_fields')[$state] ?? $state),
                TextColumn::make('status')->badge(),
            ])
            ->defaultSort('name')
            ->recordActions([
                EditAction::make()->using(function (DocumentType $record, array $data) {
                    $record->withAuditReason(AuditReasonField::extract($data))->update($data);

                    return $record;
                }),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageDocumentTypes::route('/')];
    }
}
