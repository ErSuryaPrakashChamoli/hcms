<?php

namespace App\Filament\Pages;

use App\Domain\Compensation\Models\SalaryStructure;
use App\Domain\Payroll\Models\Payslip;
use App\Domain\Payroll\Services\PayslipDesigns;
use App\Domain\Payroll\Support\PayslipWordTemplate;
use App\Filament\Support\AuditReasonField;
use App\Filament\Support\FormWizard;
use App\Filament\Support\Pages\Concerns\StartsOverAfterSave;
use App\Http\Controllers\PayslipPrintController;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use UnitEnum;

/**
 * Payslip designs (owner, 2026-11-27: "4–5 payslip templates so the client chooses its type, or uploads its own"): pick
 * one of five designs from live previews, its accent colour, title, footer and what it shows, optionally another design
 * for some salary structures, and review. The organisation's own Word layout is uploaded from the page's actions (a
 * starter file and the list of variables come with it). Every payslip — old ones too — prints in the chosen design;
 * figures never change (they are the payslip's snapshot).
 */
class PayslipDesignsPage extends Page implements HasSchemas
{
    use InteractsWithSchemas;
    use StartsOverAfterSave;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static string|UnitEnum|null $navigationGroup = 'Payroll';

    protected static ?string $navigationLabel = 'Payslip designs';

    protected static ?string $title = 'Payslip designs';

    protected static ?string $slug = 'payslip-designs';

    protected static ?int $navigationSort = 46;

    protected string $view = 'filament.pages.payslip-designs';

    /** @var array<string, mixed> */
    public array $data = [];

    public static function canAccess(): bool
    {
        return PayslipDesigns::mayManage(auth()->user());
    }

    public function getSubheading(): ?string
    {
        return 'How every employee\'s payslip looks — on screen, printed and in the password-protected PDF. Only payroll chooses it; employees get this design. The figures are always the payslip\'s own; a design only changes how they are laid out.';
    }

    public function mount(): void
    {
        $s = app(PayslipDesigns::class)->settings();
        $this->form->fill([
            'layout' => $s['layout'], 'accent' => $s['accent'], 'title' => $s['title'], 'footer' => $s['footer'],
            'show' => array_keys(array_filter(array_intersect_key($s, PayslipDesigns::OPTIONS))),
            'by_structure' => collect($s['by_structure'])->map(fn (string $design, string $code) => ['structure' => $code, 'design' => $design])->values()->all(),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        $schema->statePath('data')->components([
            Section::make('Design')
                ->description('Pick the layout every payslip prints in. The previews use your latest payslip (or a sample when there is none yet).')
                ->schema([
                    Radio::make('layout')->label('Design')->required()->live()->options(fn () => $this->designOptions())
                        ->extraAttributes(['class' => 'sr-only']),
                    View::make('filament.pages.payslip-designs.gallery')->columnSpanFull()
                        ->viewData(fn () => ['cards' => $this->cards(), 'selected' => (string) ($this->data['layout'] ?? 'classic')]),
                ]),
            Section::make('Look and content')
                ->description('The accent colour, the title and footer, and which details the payslip shows. The preview below follows every change.')
                ->schema([
                    Grid::make(['default' => 1, 'lg' => 2])->schema([
                        Grid::make(1)->schema([
                            Radio::make('accent')->label('Accent colour')->inline()->required()->live()
                                ->options(collect(PayslipDesigns::ACCENTS)->map(fn (array $a) => $a[0])->all()),
                            TextInput::make('title')->label('Title')->maxLength(60)->live(onBlur: true)->placeholder('Payslip')
                                ->helperText('Printed at the top, e.g. "Payslip" or "Salary slip".'),
                            Textarea::make('footer')->label('Footer')->rows(2)->maxLength(300)->live(onBlur: true)
                                ->helperText('Printed at the bottom of every payslip. Blank: no footer.'),
                        ]),
                        CheckboxList::make('show')->label('Show on the payslip')->live()
                            ->options(collect(PayslipDesigns::OPTIONS)->map(fn (array $o) => $o[0])->all())
                            ->helperText('Detailed always shows the year to date, employer contributions and the tax summary.'),
                    ]),
                    View::make('filament.pages.payslip-designs.preview')->columnSpanFull()
                        ->viewData(fn () => ['html' => $this->previewHtml(), 'design' => (string) ($this->data['layout'] ?? 'classic'),
                            'name' => $this->designOptions()[$this->data['layout'] ?? 'classic'] ?? 'Classic']),
                ]),
            Section::make('Salary structures')
                ->description('Optional: another design for the people on some salary structures — for example Compact for workers and Detailed for managers. Everyone else gets the design above.')
                ->schema([
                    Repeater::make('by_structure')->hiddenLabel()->defaultItems(0)->addActionLabel('Add a salary structure')->columns(2)
                        ->schema([
                            Select::make('structure')->label('Salary structure')->required()->searchable()->distinct()
                                ->options(fn () => SalaryStructure::query()->orderBy('name')->pluck('name', 'code')->all()),
                            Select::make('design')->label('Design')->required()->options(fn () => $this->designOptions()),
                        ]),
                ]),
            AuditReasonField::make(),
        ]);

        return $schema->components([
            FormWizard::wizard(FormWizard::steps($schema, FormWizard::EDIT, 'Payslip design'), FormWizard::EDIT, 'page')
                ->submitAction(Action::make('save')->label('Save payslip design')->action('save'))
                ->alpineSubmitHandler('$wire.save()'),
        ]);
    }

    /**
     * The design gallery: each design with its description and a live preview in the form's colours and options.
     *
     * @return list<array{key: string, name: string, about: string, html: ?string}>
     */
    public function cards(): array
    {
        $cards = collect(PayslipDesigns::DESIGNS)->map(fn (array $d, string $key) => ['key' => $key, 'name' => $d[0], 'about' => $d[1], 'html' => $this->previewHtml($key)])->values()->all();
        if (($custom = app(PayslipDesigns::class)->settings()['custom']) !== null) {
            $cards[] = ['key' => PayslipDesigns::CUSTOM, 'name' => 'Your Word layout', 'about' => $custom['name'].': payslips download as filled Word files (no PDF password).', 'html' => null];
        }

        return $cards;
    }

    /** @return array<string, string> design => name (the Word layout once uploaded) */
    public function designOptions(): array
    {
        $options = collect(PayslipDesigns::DESIGNS)->map(fn (array $d) => $d[0])->all();
        if (app(PayslipDesigns::class)->settings()['custom'] !== null) {
            $options[PayslipDesigns::CUSTOM] = 'Your Word layout';
        }

        return $options;
    }

    /**
     * The settings on the form (not saved yet), for the previews.
     *
     * @return array<string, mixed>
     */
    public function draft(): array
    {
        $saved = app(PayslipDesigns::class)->settings();
        $show = (array) ($this->data['show'] ?? []);

        return [
            'layout' => (string) ($this->data['layout'] ?? $saved['layout']),
            'accent' => isset(PayslipDesigns::ACCENTS[$this->data['accent'] ?? '']) ? $this->data['accent'] : $saved['accent'],
            'title' => trim((string) ($this->data['title'] ?? '')) ?: 'Payslip',
            'footer' => trim((string) ($this->data['footer'] ?? '')),
        ] + collect(PayslipDesigns::OPTIONS)->mapWithKeys(fn (array $o, string $key) => [$key => in_array($key, $show, true)])->all() + $saved;
    }

    /** A design as a printable page with the settings on the form, for the preview frames. */
    public function previewHtml(?string $design = null): string
    {
        $designs = app(PayslipDesigns::class);
        $draft = $this->draft();
        $design ??= $draft['layout'];
        if ($design === PayslipDesigns::CUSTOM) {
            return '';
        }

        return $designs->html($designs->sample(), $design, $draft, embedded: true);
    }

    public function save(): void
    {
        abort_unless(self::canAccess(), 403);
        $state = $this->form->getState();
        $reason = AuditReasonField::extract($state);
        $show = (array) ($state['show'] ?? []);
        try {
            app(PayslipDesigns::class)->save([
                'layout' => $state['layout'], 'accent' => $state['accent'], 'title' => $state['title'] ?? '', 'footer' => $state['footer'] ?? '',
                'by_structure' => $state['by_structure'] ?? [],
            ] + collect(PayslipDesigns::OPTIONS)->mapWithKeys(fn (array $o, string $key) => [$key => in_array($key, $show, true)])->all(), auth()->user(), $reason);
        } catch (RuntimeException $e) {
            Notification::make()->danger()->title('Payslip design not saved')->body($e->getMessage())->persistent()->send();

            return;
        }
        Notification::make()->success()->title('Payslip design saved')->body('Payslips now open, print and save as a PDF in this design.')->send();
        $this->startOver();
    }

    protected function getHeaderActions(): array
    {
        $designs = app(PayslipDesigns::class);

        return [
            Action::make('latest')->label('Open the latest payslip')->icon(Heroicon::OutlinedPrinter)->color('gray')
                ->visible(fn () => Payslip::query()->exists())
                ->url(fn () => PayslipPrintController::url(Payslip::query()->latest('generated_at')->first()), shouldOpenInNewTab: true),
            Action::make('word')->label(fn () => $designs->settings()['custom'] === null ? 'Upload your own Word layout' : 'Your Word layout')
                ->icon(Heroicon::OutlinedDocumentArrowUp)->color('gray')
                ->modalHeading('Your own payslip layout in Word')
                ->modalDescription('Make the payslip in Word with your letterhead, type variables such as {{ employee.name }} where values go, put {{ payslip.earnings }} and {{ payslip.deductions }} on lines of their own for the tables, and save it as .docx. Each payslip downloads as a filled Word copy — not password-protected: for protected PDFs choose one of the five designs.')
                ->modalWidth('3xl')
                ->schema([
                    FileUpload::make('file')->label('Word file (.docx)')->required()->storeFiles(false)
                        ->acceptedFileTypes(['application/vnd.openxmlformats-officedocument.wordprocessingml.document'])->maxSize(5120)
                        ->helperText(fn () => ($c = $designs->settings()['custom']) !== null ? 'In use: '.$c['name'].'. Uploading another replaces it.' : null),
                    View::make('filament.pages.payslip-designs.variables')->viewData(fn () => ['variables' => PayslipWordTemplate::VARIABLES]),
                ])
                ->modalSubmitActionLabel('Upload')
                ->extraModalFooterActions([
                    Action::make('starter')->label('Download a starter Word file')->color('gray')->action(fn () => $this->starter()),
                    Action::make('sampleWord')->label('Download a filled sample')->color('gray')->visible(fn () => $designs->settings()['custom'] !== null)->action(fn () => $this->sampleWord()),
                    Action::make('removeWord')->label('Stop using the Word layout')->color('danger')->requiresConfirmation()
                        ->visible(fn () => $designs->settings()['custom'] !== null)->action(fn () => $this->removeWord()),
                ])
                ->action(function (array $data) use ($designs) {
                    $file = $data['file'] ?? null;
                    $file = is_array($file) ? reset($file) : $file;
                    if (! $file instanceof TemporaryUploadedFile) {
                        Notification::make()->danger()->title('Choose a Word file')->send();

                        return;
                    }
                    try {
                        $custom = $designs->storeWord((string) $file->getRealPath(), $file->getClientOriginalName(), auth()->user());
                        $designs->save(['custom' => $custom] + $designs->settings(), auth()->user(), 'Word payslip layout uploaded');
                    } catch (RuntimeException $e) {
                        Notification::make()->danger()->title('Word layout not used')->body($e->getMessage())->persistent()->send();

                        return;
                    }
                    Notification::make()->success()->title('Word layout uploaded')->body('Choose "Your Word layout" as the design (or for some salary structures) and save, to use it.')->send();
                    $this->startOver();
                }),
        ];
    }

    public function starter(): StreamedResponse
    {
        abort_unless(self::canAccess(), 403);
        $bytes = PayslipWordTemplate::sample();

        return response()->streamDownload(fn () => print ($bytes), 'payslip-layout.docx', ['Content-Type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document']);
    }

    public function sampleWord(): ?StreamedResponse
    {
        abort_unless(self::canAccess(), 403);
        $designs = app(PayslipDesigns::class);
        try {
            $file = $designs->word($designs->sample());
        } catch (RuntimeException $e) {
            Notification::make()->danger()->title('Sample not made')->body($e->getMessage())->persistent()->send();

            return null;
        }

        return response()->streamDownload(fn () => print ($file['content']), 'payslip-sample.docx', ['Content-Type' => $file['mime']]);
    }

    public function removeWord(): void
    {
        abort_unless(self::canAccess(), 403);
        $designs = app(PayslipDesigns::class);
        $s = $designs->settings();
        $layout = $s['layout'] === PayslipDesigns::CUSTOM ? 'classic' : $s['layout'];
        $designs->save(['layout' => $layout, 'custom' => null, 'by_structure' => array_filter($s['by_structure'], fn ($d) => $d !== PayslipDesigns::CUSTOM)] + $s,
            auth()->user(), 'Word payslip layout removed');
        Notification::make()->success()->title('Word layout no longer used')->body($s['layout'] === PayslipDesigns::CUSTOM ? 'Payslips print in Classic until you choose another design.' : null)->send();
        $this->startOver();
    }
}
