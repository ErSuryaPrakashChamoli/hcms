<?php

namespace App\Filament\Resources\LetterTemplates;

use App\Domain\Employment\Models\Employee;
use App\Domain\Letters\Models\LetterSignatory;
use App\Domain\Letters\Models\LetterTemplate;
use App\Domain\Letters\Services\Letters;
use App\Domain\Letters\Support\DocxLetter;
use App\Domain\Letters\Support\LetterVariables;
use App\Domain\Organisation\Enums\ActiveStatus;
use App\Filament\Resources\LetterTemplates\Pages\ManageLetterTemplates;
use App\Filament\Support\AuditReasonField;
use App\Filament\Support\LetterVariablesUi;
use App\Filament\Support\ServiceDeskActions;
use BackedEnum;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ToggleButtons;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use RuntimeException;
use Throwable;
use UnitEnum;

/**
 * Letter factory templates (§40): written in the rich-text editor with a variable picker, or the client's own Word
 * file uploaded with {{ variables }} typed in it. The variables guide sits beside "New letter template".
 */
class LetterTemplateResource extends Resource
{
    protected static ?string $model = LetterTemplate::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentDuplicate;

    protected static string|UnitEnum|null $navigationGroup = 'Letters';

    protected static ?string $navigationLabel = 'Templates';

    protected static ?int $navigationSort = 20;

    public static function form(Schema $schema): Schema
    {
        $docx = fn (Get $get): bool => $get('format') === LetterTemplate::DOCX;

        return $schema->columns(3)->components([
            Section::make('Template')->columns(2)->schema([
                TextInput::make('name')->required()->maxLength(255),
                TextInput::make('code')->required()->maxLength(32)->alphaDash()->disabled(fn (string $operation) => $operation === 'edit')->dehydrated(),
                Select::make('type')->options(config('peopleos.letters.types'))->default('custom')->required(),
                Select::make('status')->options(ActiveStatus::class)->default(ActiveStatus::Active)->required(),
                ToggleButtons::make('format')->label('How is the letter made?')->inline()->live()->required()->default(LetterTemplate::HTML)->columnSpanFull()
                    ->options([LetterTemplate::HTML => 'Write it here', LetterTemplate::DOCX => 'Upload our own Word file'])
                    ->icons([LetterTemplate::HTML => 'heroicon-m-pencil-square', LetterTemplate::DOCX => 'heroicon-m-document-arrow-up'])
                    ->helperText('Either way, put variables such as {{ employee.name }} where the employee’s details should go.'),
            ]),
            Section::make('Letter content')->columns(2)->schema([
                TextInput::make('subject')->required()->maxLength(255)->columnSpan(2)->helperText('Variables work here too, e.g. Experience certificate – {{ employee.name }}.'),
                RichEditor::make('body')->label('Letter')->columnSpanFull()
                    ->hidden($docx)->required(fn (Get $get): bool => ! $docx($get))
                    ->mergeTags(fn () => LetterVariables::mergeTags())
                    ->toolbarButtons([
                        ['bold', 'italic', 'underline', 'strike', 'link'],
                        ['h2', 'h3', 'paragraph'],
                        ['alignStart', 'alignCenter', 'alignEnd', 'alignJustify'],
                        ['bulletList', 'orderedList', 'horizontalRule'],
                        ['table', 'mergeTags'],
                        ['undo', 'redo', 'clearFormatting'],
                    ])
                    ->hintAction(LetterVariablesUi::guideAction('variablesFromEditor'))
                    ->helperText('Click { } to insert a variable. {{ salary.annexure }} on a line of its own prints the salary table (monthly and yearly); {{ signatory.signature }} prints the signature.'),
                FileUpload::make('file_path')->label('Word file (.docx)')->columnSpan(2)
                    ->visible($docx)->required($docx)
                    ->disk(fn () => config('peopleos.documents.disk'))->directory(fn () => Letters::directory('templates'))->visibility('private')
                    ->acceptedFileTypes([Letters::DOCX_MIME])->maxSize(5120)->storeFileNamesIn('file_name')
                    ->rule(fn () => function (string $attribute, mixed $value, Closure $fail): void {
                        if ($value instanceof TemporaryUploadedFile && ($problem = DocxLetter::problem((string) $value->getRealPath()))) {
                            $fail($problem);
                        }
                    })
                    ->hintAction(LetterVariablesUi::sampleAction('sampleFromUpload'))
                    ->helperText('Make the letter in Word with your letterhead and layout, type variables such as {{ employee.name }} where values go, and save it as .docx. Each letter is a filled copy of this file.'),
            ]),
            Section::make('Signing and approval')->columns(2)->schema([
                Select::make('letter_signatory_id')->label('Signing authority')->placeholder('The default signatory')
                    ->options(fn () => self::signatories())
                    ->helperText('Printed by {{ signatory.signature }}, {{ signatory.name }} and {{ signatory.designation }}.'),
                Toggle::make('requires_approval')->label('Needs approval before issue')->inline(false),
            ]),
            AuditReasonField::make()->visibleOn('edit'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable(),
                TextColumn::make('code'),
                TextColumn::make('type')->badge()->color('gray')->formatStateUsing(fn (string $state) => config("peopleos.letters.types.{$state}", $state)),
                TextColumn::make('format')->label('Made as')->badge()->color(fn (string $state) => $state === LetterTemplate::DOCX ? 'info' : 'gray')
                    ->formatStateUsing(fn (string $state) => $state === LetterTemplate::DOCX ? 'Word file' : 'Written here'),
                IconColumn::make('requires_approval')->label('Approval')->boolean(),
                TextColumn::make('version')->formatStateUsing(fn ($state) => "v{$state}"),
                TextColumn::make('status')->badge(),
            ])
            ->recordActions([
                self::previewAction(),
                Action::make('downloadWordFile')->label('Word file')->icon(Heroicon::OutlinedArrowDownTray)->color('gray')
                    ->visible(fn (LetterTemplate $record) => $record->isDocx() && auth()->user()->can('letter.manage'))
                    ->action(fn (LetterTemplate $record) => response()->streamDownload(fn () => print (app(Letters::class)->templateFile($record)), $record->file_name ?: Str::slug($record->code).'.docx', ['Content-Type' => Letters::DOCX_MIME])),
                EditAction::make()->using(fn (LetterTemplate $record, array $data) => self::save($record, $data)),
            ]);
    }

    /** Saves a template from the form: a Word template keeps its text in body (search, checks); a written one drops the file. */
    public static function save(LetterTemplate $record, array $data): LetterTemplate
    {
        $reason = AuditReasonField::extract($data);
        $disk = (string) config('peopleos.documents.disk');
        [$oldDisk, $oldPath] = [$record->file_disk, $record->file_path];

        if (($data['format'] ?? $record->format) === LetterTemplate::DOCX) {
            $data['file_disk'] = $disk;
            $data['body'] = app(Letters::class)->templateText(new LetterTemplate(['format' => LetterTemplate::DOCX, 'file_disk' => $disk, 'file_path' => $data['file_path'] ?? $record->file_path]));
        } else {
            $data = array_merge($data, ['file_disk' => null, 'file_path' => null, 'file_name' => null]);
        }
        $record->withAuditReason($reason)->fill($data)->save();

        if (filled($oldPath) && $oldPath !== $record->file_path) {
            Storage::disk($oldDisk ?: $disk)->delete($oldPath);
        }
        $extras = app(Letters::class)->check($record)['extras'];
        if ($extras !== []) {
            Notification::make()->warning()->persistent()->title('Check these variables')
                ->body('Not in the variables list, so they print only when typed in when the letter is generated: '.collect($extras)->map(fn (string $v) => '{{ '.$v.' }}')->implode(', ').'. Check the spelling if they should come from the employee’s details.')
                ->send();
        }

        return $record;
    }

    private static function previewAction(): Action
    {
        return Action::make('preview')->label('Preview')->icon(Heroicon::OutlinedEye)->color('gray')
            ->visible(fn () => auth()->user()->can('letter.issue'))
            ->modalHeading(fn (LetterTemplate $record) => "Preview: {$record->name}")
            ->modalDescription('The template filled in with one employee’s details. Nothing is generated or sent.')
            ->modalWidth('5xl')->slideOver()
            ->schema([
                Grid::make(['default' => 1, 'md' => 2])->schema([
                    Select::make('employee_id')->label('Employee')->required()->searchable()->live()
                        ->options(fn () => Employee::query()->with('person')->get()->mapWithKeys(fn ($e) => [$e->id => "{$e->employee_code} · {$e->person?->full_name}"])->all()),
                    Select::make('letter_signatory_id')->label('Signing authority')->placeholder('As on the template')->live()->options(fn () => self::signatories()),
                ]),
                KeyValue::make('extra')->label('Extra variables')->keyLabel('Variable')->valueLabel('Value')->live(onBlur: true),
                View::make('filament.letters.template-preview')->columnSpanFull()
                    ->viewData(fn (Get $get, LetterTemplate $record) => self::previewData($record, $get('employee_id'), $get('letter_signatory_id'), (array) $get('extra'))),
            ])
            ->modalSubmitActionLabel(fn (LetterTemplate $record) => $record->isDocx() ? 'Download the filled Word file' : 'Download')
            ->action(function (LetterTemplate $record, array $data) {
                try {
                    $file = self::renderPreview($record, $data['employee_id'], $data['letter_signatory_id'] ?? null, (array) ($data['extra'] ?? []));
                } catch (RuntimeException $e) {
                    ServiceDeskActions::refuse($e->getMessage());

                    return null;
                }

                return response()->streamDownload(fn () => print ($file['content']), $file['name'], ['Content-Type' => $file['mime']]);
            });
    }

    /** @return array{page: ?string, text: ?string, message: ?string} */
    private static function previewData(LetterTemplate $template, mixed $employeeId, mixed $signatoryId, array $extra): array
    {
        if (blank($employeeId)) {
            return ['page' => null, 'text' => null, 'message' => 'Choose an employee to see the letter filled in with their details.'];
        }
        try {
            $file = self::renderPreview($template, $employeeId, $signatoryId, $extra);
        } catch (RuntimeException $e) {
            return ['page' => null, 'text' => null, 'message' => $e->getMessage()];
        } catch (Throwable $e) {
            report($e);

            return ['page' => null, 'text' => null, 'message' => 'The preview could not be made from this template.'];
        }

        return $template->isDocx()
            ? ['page' => null, 'text' => $file['text'], 'message' => null]
            : ['page' => $file['content'], 'text' => null, 'message' => null];
    }

    /** @return array{content: string, name: string, mime: string, text: string} */
    private static function renderPreview(LetterTemplate $template, mixed $employeeId, mixed $signatoryId, array $extra): array
    {
        $extra = collect($extra)->filter(fn ($value, $key) => filled($key))->all();

        return app(Letters::class)->preview($template, Employee::query()->findOrFail((int) $employeeId), auth()->user(), $extra,
            filled($signatoryId) ? LetterSignatory::query()->find((int) $signatoryId) : null);
    }

    /** @return array<int, string> */
    public static function signatories(): array
    {
        return LetterSignatory::query()->where('status', ActiveStatus::Active)->orderBy('name')->get()
            ->mapWithKeys(fn (LetterSignatory $s) => [$s->id => $s->designation ? "{$s->name} · {$s->designation}" : $s->name])->all();
    }

    public static function getPages(): array
    {
        return ['index' => ManageLetterTemplates::route('/')];
    }
}
