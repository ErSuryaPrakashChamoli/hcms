<?php

namespace App\Filament\Resources\Employees\RelationManagers;

use App\Domain\Employment\Models\Employee;
use App\Domain\Employment\Services\SensitiveAccessAuditor;
use App\Domain\Experience\Services\ProfileSectionVisibility;
use App\Domain\People\Actions\ChangeIdentityDocumentAction;
use App\Domain\People\Models\PersonIdentityDocument;
use App\Filament\Support\AuditReasonField;
use App\Filament\Support\ProfileChangeActions;
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
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * HROne parity MS-4 (matrix 22 §22.2.2 #3, #4): passport and driving licence on the Employee 360. Sensitive like the Bank
 * tab: shown only with `viewSensitive` and when the CA-6 matrix shows the personal section; opening the tab is audited,
 * numbers are masked (last four), revealing one needs a stated purpose and is audited again. Every write goes through
 * ChangeIdentityDocumentAction (employee.sensitive.update, scope, CA-4 linked document).
 */
class IdentityDocumentsRelationManager extends RelationManager
{
    protected static string $relationship = 'identityDocuments';

    protected static ?string $title = 'Passport & licence';

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
                Select::make('kind')->label('Document')->options(PersonIdentityDocument::kinds())->required()->disabledOn('edit'),
                TextInput::make('number')->label('Number')->maxLength(32)->password()->revealable()
                    ->required(fn (string $operation) => $operation === 'create')
                    ->helperText(fn (string $operation) => $operation === 'edit' ? 'Leave blank to keep the number on file.' : 'Stored encrypted; lists show the last four characters.'),
            ]),
            Section::make('Validity')->columns(2)->schema([
                DatePicker::make('issued_on')->label('Issued on')->native(false)->maxDate(now()),
                DatePicker::make('expires_on')->label('Expires on')->native(false)->afterOrEqual('issued_on'),
                TextInput::make('issuing_country')->label('Issuing country (2 letters)')->maxLength(2)->placeholder('IN'),
                TextInput::make('place_of_issue')->label('Place of issue')->maxLength(128),
            ]),
            AuditReasonField::make(),
        ]);
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
                TextColumn::make('issuing_country')->label('Country')->placeholder('—'),
                TextColumn::make('place_of_issue')->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->headerActions([
                CreateAction::make()->label('Add passport or licence')->using(function (array $data, RelationManager $livewire) {
                    $reason = AuditReasonField::extract($data);

                    return ProfileChangeActions::run(fn () => app(ChangeIdentityDocumentAction::class)->add($livewire->getOwnerRecord(), $data, auth()->user(), $reason));
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
                EditAction::make()->using(function (PersonIdentityDocument $record, array $data, RelationManager $livewire) {
                    $reason = AuditReasonField::extract($data);

                    return ProfileChangeActions::run(fn () => app(ChangeIdentityDocumentAction::class)->update($livewire->getOwnerRecord(), $record, $data, auth()->user(), $reason));
                }),
                DeleteAction::make()->using(fn (PersonIdentityDocument $record, RelationManager $livewire) => ProfileChangeActions::run(fn () => app(ChangeIdentityDocumentAction::class)->remove($livewire->getOwnerRecord(), $record, auth()->user()) ?? true)),
            ]);
    }
}
