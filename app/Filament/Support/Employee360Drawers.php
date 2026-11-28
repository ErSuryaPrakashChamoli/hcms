<?php

namespace App\Filament\Support;

use App\Domain\Documents\Models\DocumentType;
use App\Domain\Documents\Models\EmployeeDocument;
use App\Domain\Documents\Services\DocumentRules;
use App\Domain\Documents\Services\Documents;
use App\Domain\Employment\Models\Employee;
use App\Domain\Experience\Services\Employee360Folders;
use App\Domain\Letters\Models\Letter;
use App\Domain\Letters\Models\LetterTemplate;
use App\Domain\Letters\Services\Letters;
use App\Filament\Resources\Letters\LetterResource;
use App\Support\Storage\StagedUpload;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;

/**
 * The Employee 360 Documents and Letters cards open as drawers (Employee360Folders): documents by category and document
 * type, letters by letter type, with "Upload" (and for letters "Generate") on every category and type. The nested upload
 * and generate dialogs open over the drawer; the drawer shows the new file as soon as they close.
 */
final class Employee360Drawers
{
    public const FILE_TYPES = ['application/pdf', 'image/jpeg', 'image/png', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'];

    /**
     * @param  Closure(): Employee  $employee
     * @param  Closure(): ?string  $records  the Documents list under the Documents view (ViewEmployee::listUrl)
     */
    public static function documents(Closure $employee, Closure $records): Action
    {
        $folders = fn (): Employee360Folders => app(Employee360Folders::class);

        return Action::make('documents360')->label('Documents')->icon(Heroicon::OutlinedFolderOpen)
            ->modalHeading(fn () => 'Documents · '.$employee()->person?->display_name)
            ->modalDescription('Every document type, by category. Upload against a category or a type right here; verify, reject and delete in the full list.')
            ->slideOver()->modalWidth(Width::TwoExtraLarge)
            ->modalSubmitAction(false)->modalCancelActionLabel('Close')
            ->visible(fn () => $folders()->maySeeDocuments(auth()->user(), $employee()))
            ->modalContent(fn () => view('filament.employees.documents-folder', [
                'folder' => $folders()->documents(auth()->user(), $employee()),
                'records' => $records(),
            ]))
            ->registerModalActions([self::uploadDocument($employee)]);
    }

    /** Upload a document: pick the category, then the document type (both pre-filled from the row it was opened on). */
    public static function uploadDocument(Closure $employee): Action
    {
        $folders = fn (): Employee360Folders => app(Employee360Folders::class);
        /** @var Closure(): Collection<int, DocumentType> $uploadable */
        $uploadable = fn (): Collection => $folders()->uploadableTypes(auth()->user(), $employee());
        $type = fn ($id): ?DocumentType => filled($id) ? $uploadable()->firstWhere('id', (int) $id) : null;
        $hr = fn (): bool => $folders()->uploadsAsHr(auth()->user());
        $categories = fn (): array => array_intersect_key($folders()->categories(), $uploadable()->mapWithKeys(fn (DocumentType $t) => [$folders()->categoryOf($t) => true])->all());

        return Action::make('upload360Document')->label('Upload a document')->icon(Heroicon::OutlinedArrowUpTray)
            ->modalHeading(function (array $arguments) use ($type, $folders) {
                if ($chosen = $type($arguments['type'] ?? null)) {
                    return "Upload {$chosen->name}";
                }
                $category = $folders()->categories()[$arguments['category'] ?? ''] ?? null;

                return $category !== null ? "Upload a document · {$category}" : 'Upload a document';
            })
            ->modalDescription(fn () => $hr() ? 'Filed as pending verification; someone other than the uploader verifies it. A new file for a type that has one becomes its next version.'
                : 'HR verifies what you upload.')
            ->modalSubmitActionLabel('Upload')->modalWidth(Width::TwoExtraLarge)
            ->visible(fn () => $uploadable()->isNotEmpty())
            ->fillForm(function (array $arguments) use ($type, $folders) {
                $chosen = $type($arguments['type'] ?? null);

                return ['category' => $chosen !== null ? $folders()->categoryOf($chosen) : ($arguments['category'] ?? null), 'document_type_id' => $chosen?->id];
            })
            // Loose fields: the wizard (FormWizard) lays them out two to a row; category and type pair up, then the dates.
            ->schema(fn () => [
                Select::make('category')->label('Document category')->placeholder('Every category')
                    ->options($categories)->live()
                    ->afterStateUpdated(function (Set $set, Get $get, ?string $state) use ($type, $folders) {
                        $chosen = $type($get('document_type_id'));
                        if ($chosen !== null && filled($state) && $folders()->categoryOf($chosen) !== $state) {
                            $set('document_type_id', null);
                        }
                    }),
                Select::make('document_type_id')->label('Document type')->required()->searchable()->live()
                    ->options(function (Get $get) use ($uploadable, $folders) {
                        $category = $get('category');
                        if (filled($category)) {
                            return $uploadable()->filter(fn (DocumentType $t) => $folders()->categoryOf($t) === $category)->pluck('name', 'id')->all();
                        }
                        $grouped = [];
                        foreach ($uploadable() as $t) {
                            $grouped[$folders()->categories()[$folders()->categoryOf($t)]][$t->id] = $t->name;
                        }

                        return $grouped;
                    })
                    ->afterStateUpdated(fn (Set $set, ?string $state) => $set('category', ($chosen = $type($state)) ? $folders()->categoryOf($chosen) : null)),
                DocumentUploadHint::make()->columnSpanFull(),
                FileUpload::make('file')->required()->disk(StagedUpload::disk())->directory('tmp-uploads')->columnSpanFull()
                    ->maxSize(config('peopleos.documents.max_kb'))->acceptedFileTypes(self::FILE_TYPES)->storeFileNamesIn('file_name'),
                Hidden::make('file_name'),
                TextInput::make('title')->maxLength(255)->placeholder('The document type name')->columnSpanFull()->visible($hr),
                DatePicker::make('issued_on')->label('Issued on')->native(false),
                DatePicker::make('expires_on')->label('Expires on')->native(false)
                    ->required(fn (Get $get) => (bool) $type($get('document_type_id'))?->requires_expiry),
                Textarea::make('comment')->label('Comment for HR')->rows(2)->maxLength(500)->columnSpanFull()->visible(fn () => ! $hr()),
                AuditReasonField::make()->visible($hr),
            ])
            ->action(function (array $data) use ($employee, $type, $hr) {
                $chosen = $type($data['document_type_id'] ?? null);
                if ($chosen === null) {
                    Notification::make()->danger()->title('Choose a document type you may upload.')->send();

                    return;
                }
                $file = StagedUpload::toUploadedFile($data['file'], is_string($data['file_name'] ?? null) ? $data['file_name'] : null);
                try {
                    ServiceDeskActions::run(fn () => $hr()
                        ? app(Documents::class)->store($employee(), $file, $chosen, filled($data['title'] ?? null) ? $data['title'] : null, $data['expires_on'] ?? null, $data['issued_on'] ?? null,
                            $data[AuditReasonField::NAME] ?? 'Uploaded from the Employee 360')
                        : app(DocumentRules::class)->employeeUpload(auth()->user(), $employee(), $chosen, $file, $data['expires_on'] ?? null, $data['issued_on'] ?? null, $data['comment'] ?? null),
                        fn (EmployeeDocument $d) => "{$d->title} uploaded");
                } finally {
                    StagedUpload::discard($data['file'], $file);
                }
            });
    }

    /**
     * @param  Closure(): Employee  $employee
     * @param  Closure(): ?string  $records  the Letters list under the Documents view (ViewEmployee::listUrl)
     */
    public static function letters(Closure $employee, Closure $records): Action
    {
        $folders = fn (): Employee360Folders => app(Employee360Folders::class);

        return Action::make('letters360')->label('Letters')->icon(Heroicon::OutlinedEnvelopeOpen)
            ->modalHeading(fn () => 'Letters · '.$employee()->person?->display_name)
            ->modalDescription('Every letter type. Generate one from a template, or upload a letter made outside PeopleOS against its type.')
            ->slideOver()->modalWidth(Width::TwoExtraLarge)
            ->modalSubmitAction(false)->modalCancelActionLabel('Close')
            ->visible(fn () => $folders()->maySeeLetters(auth()->user(), $employee()))
            ->modalContent(function () use ($folders, $employee, $records) {
                $folder = $folders()->letters(auth()->user(), $employee());
                // The Letters screen opens a generated letter (approve, issue, e-mail) for those who work with letters.
                $open = $folder['staff'] ? Letter::query()->whereKey(collect($folder['types'])->pluck('letters')->flatten(1)->where('kind', 'letter')->pluck('id')->all())->get()
                    ->filter(fn (Letter $l) => LetterResource::canView($l))->mapWithKeys(fn (Letter $l) => [$l->id => LetterResource::getUrl('view', ['record' => $l])])->all() : [];

                return view('filament.employees.letters-folder', ['folder' => $folder, 'open' => $open, 'records' => $records()]);
            })
            ->registerModalActions([self::generateLetter($employee), self::uploadLetter($employee)]);
    }

    /** Generate a letter of a type from one of its templates (the Letters service; approval as the template says). */
    public static function generateLetter(Closure $employee): Action
    {
        $folders = fn (): Employee360Folders => app(Employee360Folders::class);
        /** @var Closure(): Collection<int, LetterTemplate> $templates */
        $templates = fn (): Collection => $folders()->templates(auth()->user(), $employee());
        $typeOf = fn (LetterTemplate $t) => filled($t->type) ? (string) $t->type : 'custom';
        $name = fn (string $type) => $folders()->letterTypes()[$type] ?? Employee360Folders::letterName(ucfirst(str_replace('_', ' ', $type)));

        return Action::make('generate360Letter')->label('Generate a letter')->icon(Heroicon::OutlinedDocumentPlus)
            ->modalHeading(fn (array $arguments) => filled($arguments['type'] ?? null) ? 'Generate · '.$name((string) $arguments['type']) : 'Generate a letter')
            ->modalDescription('Made from the template for this person. A template that needs approval goes for approval first; the letter is then issued from Letters.')
            ->modalSubmitActionLabel('Generate')->modalWidth(Width::Large)
            ->visible(fn () => $templates()->isNotEmpty())
            ->fillForm(function (array $arguments) use ($templates, $typeOf) {
                $type = filled($arguments['type'] ?? null) ? (string) $arguments['type'] : null;
                $of = $type !== null ? $templates()->filter(fn (LetterTemplate $t) => $typeOf($t) === $type) : collect();

                return ['type' => $type, 'template_id' => $of->count() === 1 ? $of->first()->id : null];
            })
            ->schema([
                Select::make('type')->label('Letter type')->placeholder('Every letter type')
                    ->options(fn () => $templates()->map($typeOf)->uniqueStrict()->mapWithKeys(fn (string $type) => [$type => $name($type)])->sort()->all())
                    ->live()->afterStateUpdated(fn (Set $set) => $set('template_id', null)),
                Select::make('template_id')->label('Template')->required()->searchable()
                    ->options(function (Get $get) use ($templates, $typeOf, $name) {
                        $type = $get('type');
                        if (filled($type)) {
                            return $templates()->filter(fn (LetterTemplate $t) => $typeOf($t) === $type)->pluck('name', 'id')->all();
                        }
                        $grouped = [];
                        foreach ($templates() as $t) {
                            $grouped[$name($typeOf($t))][$t->id] = $t->name;
                        }
                        ksort($grouped);

                        return $grouped;
                    }),
            ])
            ->action(function (array $data) use ($templates, $employee) {
                $template = $templates()->firstWhere('id', (int) ($data['template_id'] ?? 0));
                if ($template === null) {
                    Notification::make()->danger()->title('Choose a template of this person\'s company.')->send();

                    return;
                }
                ServiceDeskActions::run(fn () => app(Letters::class)->generate($template, $employee(), [], auth()->user()),
                    fn (Letter $l) => "Letter {$l->number} ".($l->status === 'approved' ? 'ready to issue' : 'sent for approval'));
            });
    }

    /** Upload a letter made outside PeopleOS against its letter type (kept with the type's issued letters, Letters::documentType). */
    public static function uploadLetter(Closure $employee): Action
    {
        $folders = fn (): Employee360Folders => app(Employee360Folders::class);

        return Action::make('upload360Letter')->label('Upload a letter')->icon(Heroicon::OutlinedArrowUpTray)
            ->modalHeading(fn (array $arguments) => filled($arguments['type'] ?? null) && ($label = $folders()->letterTypes()[$arguments['type']] ?? null) ? "Upload {$label}" : 'Upload a letter')
            ->modalDescription('For a letter made outside PeopleOS, such as a signed copy. It is filed with the person\'s documents under the letter type, pending verification.')
            ->modalSubmitActionLabel('Upload')->modalWidth(Width::TwoExtraLarge)
            ->visible(fn () => $folders()->uploadsLetters(auth()->user(), $employee()))
            ->fillForm(fn (array $arguments) => ['type' => array_key_exists($arguments['type'] ?? '', $folders()->letterTypes()) ? $arguments['type'] : null])
            ->schema([
                Select::make('type')->label('Letter type')->required()->searchable()->options(fn () => $folders()->letterTypes()),
                TextInput::make('title')->maxLength(255)->placeholder('The letter type, e.g. Offer letter'),
                FileUpload::make('file')->required()->disk(StagedUpload::disk())->directory('tmp-uploads')->columnSpanFull()
                    ->maxSize(config('peopleos.documents.max_kb'))->acceptedFileTypes(self::FILE_TYPES)->storeFileNamesIn('file_name'),
                Hidden::make('file_name'),
                DatePicker::make('issued_on')->label('Letter date')->native(false)->maxDate(now()),
                AuditReasonField::make(),
            ])
            ->action(function (array $data) use ($employee, $folders) {
                $type = (string) ($data['type'] ?? '');
                if (! array_key_exists($type, $folders()->letterTypes())) {
                    Notification::make()->danger()->title('Choose a letter type.')->send();

                    return;
                }
                $file = StagedUpload::toUploadedFile($data['file'], is_string($data['file_name'] ?? null) ? $data['file_name'] : null);
                try {
                    ServiceDeskActions::run(fn () => app(Documents::class)->store($employee(), $file, app(Letters::class)->documentType($type),
                        filled($data['title'] ?? null) ? $data['title'] : $folders()->letterTypes()[$type], null, $data['issued_on'] ?? null,
                        $data[AuditReasonField::NAME] ?? 'Letter uploaded from the Employee 360'),
                        fn (EmployeeDocument $d) => "{$d->title} uploaded");
                } finally {
                    StagedUpload::discard($data['file'], $file);
                }
            });
    }
}
