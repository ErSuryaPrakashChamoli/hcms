<?php

namespace App\Filament\Resources\Employees\RelationManagers;

use App\Domain\Documents\Models\DocumentType;
use App\Domain\Documents\Models\EmployeeDocument;
use App\Domain\Documents\Services\Documents;
use App\Filament\Support\AuditReasonField;
use App\Support\Storage\StagedUpload;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

/** Document collection (§39): private storage, verification, signed downloads. */
class DocumentsRelationManager extends RelationManager
{
    protected static string $relationship = 'documents';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return auth()->user()?->can('document.view') ?? false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['type', 'uploader', 'verifier']))
            ->columns([
                TextColumn::make('title')->weight('medium')->description(fn (EmployeeDocument $record) => $record->original_name)->searchable(),
                TextColumn::make('type.name')->label('Type')->placeholder('—'),
                TextColumn::make('type.category')->label('Category')->badge()->color('gray')->formatStateUsing(fn (?string $state) => config("peopleos.documents.categories.{$state}", $state))->placeholder('—'),
                TextColumn::make('version')->formatStateUsing(fn ($state) => "v{$state}"),
                TextColumn::make('status')->badge()->color(fn (string $state) => match ($state) {
                    'verified' => 'success', 'rejected' => 'danger', 'archived' => 'gray', default => 'warning',
                })->formatStateUsing(fn (string $state) => EmployeeDocument::STATUSES[$state] ?? $state),
                TextColumn::make('expires_on')->date()->placeholder('—')->color(fn (EmployeeDocument $record) => $record->isExpired() ? 'danger' : null),
                TextColumn::make('uploader.name')->label('Uploaded by')->placeholder('—')->toggleable(),
                TextColumn::make('created_at')->label('Uploaded')->dateTime()->sortable()->toggleable(),
            ])
            ->filters([SelectFilter::make('status')->options(EmployeeDocument::STATUSES)])
            ->defaultSort('id', 'desc')
            ->headerActions([
                Action::make('upload')
                    ->label('Upload')
                    ->icon('heroicon-m-arrow-up-tray')
                    ->authorize(fn () => auth()->user()->can('document.upload'))
                    ->schema([
                        Select::make('document_type_id')->label('Document type')->options(fn () => DocumentType::query()->where('status', 'active')->orderBy('name')->pluck('name', 'id')->all())->searchable()->live(),
                        TextInput::make('title')->maxLength(255)->helperText('Defaults to the document type name.'),
                        FileUpload::make('file')->required()->disk(StagedUpload::disk())->directory('tmp-uploads')->maxSize(config('peopleos.documents.max_kb'))
                            ->acceptedFileTypes(['application/pdf', 'image/jpeg', 'image/png', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document']),
                        DatePicker::make('issued_on')->native(false),
                        DatePicker::make('expires_on')->native(false)->required(fn (Get $get) => (bool) DocumentType::query()->find($get('document_type_id'))?->requires_expiry),
                        AuditReasonField::make(),
                    ])
                    ->action(function (array $data) {
                        // Production readiness closure: works whatever the staging disk (no local ->path()).
                        $file = StagedUpload::toUploadedFile($data['file']);
                        $type = isset($data['document_type_id']) ? DocumentType::query()->find($data['document_type_id']) : null;

                        try {
                            app(Documents::class)->store($this->getOwnerRecord(), $file, $type, $data['title'] ?? null, $data['expires_on'] ?? null, $data['issued_on'] ?? null, $data[AuditReasonField::NAME] ?? null);
                        } finally {
                            StagedUpload::discard($data['file'], $file);
                        }

                        Notification::make()->success()->title('Document uploaded')->send();
                    }),
            ])
            ->recordActions([
                Action::make('download')->label('Download')->icon('heroicon-m-arrow-down-tray')->color('gray')
                    ->url(fn (EmployeeDocument $record) => app(Documents::class)->downloadUrl($record))
                    ->openUrlInNewTab(),
                Action::make('verify')->label('Verify')->icon('heroicon-m-check-badge')->color('success')
                    ->visible(fn (EmployeeDocument $record) => $record->status === 'pending' && auth()->user()->can('verify', $record))
                    ->schema([Textarea::make('note')->maxLength(255)])
                    ->action(fn (EmployeeDocument $record, array $data) => app(Documents::class)->review($record, true, $data['note'] ?? null)),
                Action::make('reject')->label('Reject')->icon('heroicon-m-x-mark')->color('danger')
                    ->visible(fn (EmployeeDocument $record) => $record->status === 'pending' && auth()->user()->can('verify', $record))
                    ->schema([Textarea::make('note')->required()->maxLength(255)])
                    ->action(fn (EmployeeDocument $record, array $data) => app(Documents::class)->review($record, false, $data['note'])),
                DeleteAction::make()->using(fn (EmployeeDocument $record) => app(Documents::class)->delete($record, 'Deleted from Employee 360')),
            ]);
    }
}
