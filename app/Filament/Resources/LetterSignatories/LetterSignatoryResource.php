<?php

namespace App\Filament\Resources\LetterSignatories;

use App\Domain\Letters\Models\LetterSignatory;
use App\Domain\Letters\Services\Letters;
use App\Domain\Organisation\Enums\ActiveStatus;
use App\Domain\Organisation\Models\Company;
use App\Filament\Resources\LetterSignatories\Pages\ManageLetterSignatories;
use App\Filament\Support\AuditReasonField;
use BackedEnum;
use Closure;
use Filament\Actions\EditAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use UnitEnum;

/**
 * Signing authorities for letters: name, designation and signature image. The image stays on the private disk and is
 * shown here inline from the server (no URL), and printed only into letters through {{ signatory.signature }}.
 */
class LetterSignatoryResource extends Resource
{
    protected static ?string $model = LetterSignatory::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPencilSquare;

    protected static string|UnitEnum|null $navigationGroup = 'Letters';

    protected static ?string $navigationLabel = 'Signing authorities';

    protected static ?string $modelLabel = 'signing authority';

    protected static ?string $pluralModelLabel = 'signing authorities';

    protected static ?int $navigationSort = 30;

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            Section::make('Signing authority')->columns(2)->schema([
                TextInput::make('name')->required()->maxLength(255),
                TextInput::make('designation')->maxLength(255)->placeholder('Head of HR'),
                Select::make('company_id')->label('Company')->placeholder('Any company')
                    ->options(fn () => Company::query()->orderBy('name')->pluck('name', 'id')->all()),
                Toggle::make('is_default')->label('Default signatory')->inline(false)
                    ->default(fn () => ! LetterSignatory::query()->where('is_default', true)->exists())
                    ->helperText('Signs every template that does not name its own signatory.'),
                Select::make('status')->options(ActiveStatus::class)->default(ActiveStatus::Active)->required(),
            ]),
            Section::make('Signature')->columns(2)->schema([
                View::make('filament.letters.signature-current')->columnSpanFull()
                    ->visible(fn (?LetterSignatory $record) => filled($record?->signature_path))
                    ->viewData(fn (?LetterSignatory $record) => ['image' => $record?->signatureImage()]),
                FileUpload::make('signature_upload')->label(fn (?LetterSignatory $record) => filled($record?->signature_path) ? 'Replace the signature' : 'Signature')
                    ->image()->acceptedFileTypes(['image/png', 'image/jpeg'])->maxSize(1024)
                    ->disk(fn () => config('peopleos.documents.disk'))->directory(fn () => Letters::directory('signatures'))->visibility('private')
                    ->rule(fn () => function (string $attribute, mixed $value, Closure $fail): void {
                        $size = $value instanceof TemporaryUploadedFile ? @getimagesize((string) $value->getRealPath()) : false;
                        if ($value instanceof TemporaryUploadedFile && ($size === false || ! in_array($size['mime'], ['image/png', 'image/jpeg'], true))) {
                            $fail('The signature must be a PNG or JPEG image.');
                        }
                    })
                    ->helperText('A scan or photo of the signature, cropped close. A PNG with a transparent background prints best. It is kept privately and printed only into letters.')
                    ->columnSpanFull(),
            ]),
            AuditReasonField::make()->visibleOn('edit'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable(),
                TextColumn::make('designation')->placeholder('—'),
                TextColumn::make('company.name')->label('Company')->placeholder('Any'),
                IconColumn::make('signature_path')->label('Signature')->boolean()->state(fn (LetterSignatory $record) => filled($record->signature_path)),
                IconColumn::make('is_default')->label('Default')->boolean(),
                TextColumn::make('status')->badge(),
            ])
            ->recordActions([
                EditAction::make()->using(fn (LetterSignatory $record, array $data) => self::save($record, $data)),
            ]);
    }

    /** Saves a signatory from the form; a new signature replaces (and deletes) the previous image. */
    public static function save(LetterSignatory $record, array $data): LetterSignatory
    {
        $reason = AuditReasonField::extract($data);
        $upload = $data['signature_upload'] ?? null;
        unset($data['signature_upload']);
        [$oldDisk, $oldPath] = [$record->signature_disk, $record->signature_path];
        if (filled($upload)) {
            $data['signature_disk'] = config('peopleos.documents.disk');
            $data['signature_path'] = $upload;
        }
        $record->withAuditReason($reason)->fill($data)->save();
        if (filled($upload) && filled($oldPath) && $oldPath !== $upload) {
            Storage::disk($oldDisk ?: config('peopleos.documents.disk'))->delete($oldPath);
        }

        return $record;
    }

    public static function getPages(): array
    {
        return ['index' => ManageLetterSignatories::route('/')];
    }
}
