<?php

namespace App\Filament\Resources\Employees\RelationManagers;

use App\Domain\Documents\Models\DocumentType;
use App\Domain\Employment\Models\Employee;
use App\Domain\Employment\Services\SensitiveAccessAuditor;
use App\Domain\Experience\Services\ProfileSectionVisibility;
use App\Domain\People\Actions\ChangeIdentityDocumentAction;
use App\Domain\People\Models\PersonIdentityDocument;
use App\Filament\Support\AuditReasonField;
use App\Filament\Support\DocumentCopies;
use App\Filament\Support\ProfileChangeActions;
use App\Support\Geography\Countries;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * HROne parity MS-4 (matrix 22 §22.2.2 #3, #4), widened 2026-11-27: the person's government IDs on the Employee 360
 * (passport, driving licence, voter ID, national IDs, permits, visas, each country's own ID; PersonIdentityDocument::
 * catalogue()), each with an optional copy filed as the document type linked to that ID. Sensitive like the Bank
 * tab: shown only with `viewSensitive` and when the CA-6 matrix shows the personal section; opening the tab is audited,
 * numbers are masked (last four), revealing one needs a stated purpose and is audited again. Every write goes through
 * ChangeIdentityDocumentAction (employee.sensitive.update, scope, CA-4 linked document).
 */
class IdentityDocumentsRelationManager extends RelationManager
{
    protected static string $relationship = 'identityDocuments';

    protected static ?string $title = 'Government IDs';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return (auth()->user()?->can('viewSensitive', $ownerRecord) ?? false)
            && app(ProfileSectionVisibility::class)->allows(auth()->user(), $ownerRecord, 'personal');
    }

    public function mount(): void
    {
        app(SensitiveAccessAuditor::class)->recordView($this->getOwnerRecord(), 'identity_documents');
    }

    public function getRelationship(): Relation|Builder
    {
        /** @var Employee $employee */
        $employee = $this->getOwnerRecord();

        return $employee->person->identityDocuments();
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Document')->columns(2)->schema([
                Select::make('kind')->label('Government ID')->options(PersonIdentityDocument::groupedKinds())->searchable()->required()->disabledOn('edit')->live()
                    // An ID that belongs to one country is issued there.
                    ->afterStateUpdated(fn (?string $state, Set $set) => filled($country = PersonIdentityDocument::countryOf($state)) ? $set('issuing_country', $country) : null),
                TextInput::make('number')->label('Number')->maxLength(32)->password()->revealable()
                    ->required(fn (string $operation) => $operation === 'create')
                    ->rule(fn (Get $get) => function (string $attribute, mixed $value, \Closure $fail) use ($get): void {
                        if (($problem = PersonIdentityDocument::numberProblem($get('kind'), is_string($value) ? $value : null, $get('issuing_country'))) !== null) {
                            $fail($problem);
                        }
                    })
                    ->helperText(fn (string $operation, Get $get) => implode(' ', array_filter([
                        PersonIdentityDocument::hint($get('kind')),
                        $operation === 'edit' ? 'Leave blank to keep the number on file.' : 'Stored encrypted; lists show the last four characters.',
                    ]))),
            ]),
            Section::make('Validity')->columns(2)->schema([
                DatePicker::make('issued_on')->label('Issued on')->native(false)->maxDate(now()),
                DatePicker::make('expires_on')->label('Expires on')->native(false)->afterOrEqual('issued_on')
                    ->helperText(fn (Get $get) => PersonIdentityDocument::expires($get('kind')) ? 'Set it to be reminded before it expires.' : null),
                Select::make('issuing_country')->label('Issuing country')->options(fn () => Countries::all())->searchable(),
                TextInput::make('place_of_issue')->label(fn (Get $get) => $get('kind') === 'other_id' ? 'Name of the ID and place of issue' : 'Place of issue')->maxLength(128),
            ]),
            // A copy of the ID, kept with the employee's documents as the type linked to it (pending until verified).
            Section::make('Copy of the document')->schema([
                DocumentCopies::upload('proof', 'Upload a copy', fn (Get $get) => 'Optional. Filed under Documents as "'
                    .(self::proofType($get('kind'))?->name ?? 'Other government ID').'", waiting for verification.'),
            ])->visible(fn () => auth()->user()?->can('document.upload') ?? false),
            AuditReasonField::make(),
        ]);
    }

    /** The document type a government ID's copy is filed as: the type linked to the ID, else the "Other government ID" type. */
    private static function proofType(?string $kind): ?DocumentType
    {
        return blank($kind) ? null : (DocumentType::forField((string) $kind) ?? DocumentType::forField('other_id', 'OTHER_GOVT_ID'));
    }

    /** Saves the ID with its copy, if one was given (DocumentCopies: the copy first, one transaction). */
    private function withCopy(array &$data, string $kind, ?string $reason, \Closure $save): mixed
    {
        $type = self::proofType($kind);

        return DocumentCopies::saveWith($this->getOwnerRecord(), $data, ['proof' => [
            'field' => $type?->linked_field ?? $kind, 'code' => $type?->code, 'title' => PersonIdentityDocument::kinds()[$kind] ?? 'government ID',
            'expires_on' => $data['expires_on'] ?? null, 'issued_on' => $data['issued_on'] ?? null,
        ]], $reason, $save);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('kind')->label('Document')->badge()->formatStateUsing(fn (?string $state) => PersonIdentityDocument::kinds()[$state] ?? $state),
                TextColumn::make('number_last4')->label('Number')->formatStateUsing(fn ($state) => '••••'.$state),
                TextColumn::make('issued_on')->date()->placeholder('—'),
                TextColumn::make('expires_on')->date()->placeholder('—')
                    ->color(fn (PersonIdentityDocument $record) => $record->expires_on === null ? null : ($record->expires_on->lt(today()) ? 'danger' : ($record->expires_on->lte(today()->addDays(30)) ? 'warning' : null))),
                TextColumn::make('issuing_country')->label('Country')->placeholder('—')->formatStateUsing(fn (?string $state) => filled($state) ? Countries::name($state) : null),
                TextColumn::make('place_of_issue')->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->description(fn () => $this->statutoryLine())
            ->emptyStateHeading('No government ID on file')
            ->emptyStateDescription('Add a passport, driving licence, voter ID, national ID, permit or visa, with a copy of it.')
            ->headerActions([
                CreateAction::make()->label('Add a government ID')->modalHeading('Add a government ID')->using(function (array $data, RelationManager $livewire) {
                    $reason = AuditReasonField::extract($data);

                    return $this->withCopy($data, (string) ($data['kind'] ?? ''), $reason, fn () => app(ChangeIdentityDocumentAction::class)->add($livewire->getOwnerRecord(), $data, auth()->user(), $reason));
                }),
            ])
            ->recordActions([
                Action::make('reveal')
                    ->label('Reveal')
                    ->icon('heroicon-m-eye')
                    ->schema([Textarea::make('purpose')->required()->maxLength(500)->helperText('Recorded in the audit trail with your name and time.')])
                    ->action(function (PersonIdentityDocument $record, array $data, RelationManager $livewire) {
                        app(SensitiveAccessAuditor::class)->recordView($livewire->getOwnerRecord(), 'identity_document:'.$record->id, $data['purpose']);

                        Notification::make()->title($record->kindLabel())->body("Number: {$record->number}")->persistent()->send();
                    }),
                EditAction::make()->modalHeading(fn (PersonIdentityDocument $record) => 'Change the '.mb_strtolower($record->kindLabel()))->using(function (PersonIdentityDocument $record, array $data, RelationManager $livewire) {
                    $reason = AuditReasonField::extract($data);

                    return $this->withCopy($data, (string) $record->kind, $reason, fn () => app(ChangeIdentityDocumentAction::class)->update($livewire->getOwnerRecord(), $record, $data, auth()->user(), $reason));
                }),
                DeleteAction::make()->using(fn (PersonIdentityDocument $record, RelationManager $livewire) => ProfileChangeActions::run(fn () => app(ChangeIdentityDocumentAction::class)->remove($livewire->getOwnerRecord(), $record, auth()->user()) ?? true)),
            ]);
    }

    /**
     * The statutory identifiers kept with the statutory details (PAN, Aadhaar reference, UAN), masked, with where to change
     * them, so every government ID of the employee is found in one place.
     */
    private function statutoryLine(): string
    {
        /** @var Employee $employee */
        $employee = $this->getOwnerRecord();
        $detail = $employee->statutoryDetail;
        $masked = fn (?string $value) => filled($value) ? '••••'.mb_substr((string) $value, -4) : 'not on file';

        return 'PAN '.$masked($detail?->pan).' · Aadhaar '.$masked($detail?->aadhaar_reference).' · UAN '.$masked($detail?->uan)
            .' — kept with the statutory details payroll reads: change them under More → Edit statutory details.';
    }
}
