<?php

namespace App\Filament\Pages;

use App\Domain\Configuration\Services\SettingFiles;
use App\Domain\Configuration\Services\SetupCatalogue;
use App\Domain\Performance\Support\PerformanceLabels;
use App\Filament\Support\AuditReasonField;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Url;
use RuntimeException;
use UnitEnum;

/**
 * Setup (HROne parity): every module's configuration in one place, the way a client's administrator expects
 * it. For the chosen module: its settings as a typed form, its policy types (with how many policies each has)
 * and its configuration screens. Settings come from config peopleos_setup; saving needs settings.update and a
 * reason, and each changed value is audited.
 */
class SetupCentre extends Page implements HasSchemas
{
    use InteractsWithSchemas;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog8Tooth;

    protected static string|UnitEnum|null $navigationGroup = 'Configuration';

    protected static ?string $navigationLabel = 'Setup';

    protected static ?string $title = 'Setup';

    protected static ?string $slug = 'setup';

    protected static ?int $navigationSort = -20;

    protected string $view = 'filament.pages.setup-centre';

    #[Url]
    public string $module = 'core';

    /** @var array<string, mixed> */
    public array $data = [];

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user !== null && ($user->can('settings.view') || $user->can('policy.view'));
    }

    public function getSubheading(): ?string
    {
        return 'Configure each module for this organisation: its settings, the policies that apply to groups of employees, and its masters.';
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        $modules = $this->modules();
        if (! array_key_exists($this->module, $modules)) {
            $this->module = (string) (array_key_first($modules) ?? 'core');
        }
        $this->fillForm();
    }

    public function selectModule(string $module): void
    {
        if (array_key_exists($module, $this->modules())) {
            $this->module = $module;
            $this->fillForm();
        }
    }

    /** @return array<string, array<string, mixed>> */
    public function modules(): array
    {
        return app(SetupCatalogue::class)->visibleModules(auth()->user());
    }

    /** @return array<string, mixed> */
    public function current(): array
    {
        return $this->modules()[$this->module] ?? [];
    }

    /** @return list<array<string, mixed>> */
    public function policyTypes(): array
    {
        return auth()->user()->can('policy.view') ? app(SetupCatalogue::class)->policyTypes($this->module) : [];
    }

    /** @return list<array{label: string, url: string}> */
    public function links(): array
    {
        return app(SetupCatalogue::class)->links($this->module, auth()->user());
    }

    public function hasSettings(): bool
    {
        return auth()->user()->can('settings.view') && app(SetupCatalogue::class)->settingsFor($this->module) !== [];
    }

    public function form(Schema $schema): Schema
    {
        $catalogue = app(SetupCatalogue::class);
        $editable = auth()->user()?->can('settings.update') ?? false;
        $fields = [];
        $groups = [];

        foreach ($this->hasSettings() ? $catalogue->settingsFor($this->module) : [] as $setting) {
            $field = match ($setting['type']) {
                'boolean' => Toggle::make($setting['field']),
                'number' => TextInput::make($setting['field'])->numeric()->minValue($setting['min'] ?? null)->maxValue($setting['max'] ?? null)->required(! ($setting['nullable'] ?? false)),
                'textarea' => Textarea::make($setting['field'])->rows(3)->maxLength($setting['max'] ?? null),
                'select', 'roles' => Select::make($setting['field'])->options($catalogue->optionsFor($setting))->native(false)->required(! ($setting['nullable'] ?? false))
                    ->when($setting['nullable'] ?? false, fn (Select $select) => $select->placeholder('Not set')),
                'multiselect' => Select::make($setting['field'])->multiple()->options($catalogue->optionsFor($setting))->native(false)->searchable((bool) ($setting['searchable'] ?? false)),
                'date' => DatePicker::make($setting['field'])->native(false),
                'matrix' => self::matrixField($setting, $editable),
                'file' => self::fileField($setting),
                default => TextInput::make($setting['field'])->maxLength($setting['max'] ?? null)->regex($setting['pattern'] ?? null),
            };
            // HROne setup parity HP-9d: a one-time setting that is locked is shown read-only (and save() refuses a change).
            $help = $setting['locked'] ? trim(($setting['help'] ?? '').' Locked: it can no longer be changed.') : ($setting['help'] ?? null);
            $fields[] = $setting['type'] === 'matrix' ? $field : $field->label($setting['label'])->helperText($help)->disabled(! $editable || $setting['locked']);
            $groups[(string) ($setting['group'] ?? '')][] = array_key_last($fields);
        }

        if ($fields === []) {
            return $schema->statePath('data')->components([]);
        }
        $reason = $editable ? [AuditReasonField::make()] : [];
        // A module whose settings carry a `group` (HROne's headings, e.g. Expense / Travel / Exceptional expense) shows one
        // headed block per group, in the catalogue's order; otherwise one "Settings" block, as before.
        if (array_keys($groups) === ['']) {
            return $schema->statePath('data')->components([
                Section::make('Settings')->description('Organisation-wide settings of this module.')->schema([...$fields, ...$reason])->columns(1),
            ]);
        }
        $sections = [];
        foreach ($groups as $label => $indexes) {
            $sections[] = Section::make($label === '' ? 'General' : $label)->compact()->columns(1)->schema(array_map(fn (int $i) => $fields[$i], $indexes));
        }
        if ($reason !== []) {
            $sections[] = Section::make('Save')->compact()->schema($reason);
        }

        return $schema->statePath('data')->components($sections);
    }

    /** HROne setup parity CA-1: a matrix setting is one row of tick boxes per row (each row's ticked columns). */
    private static function matrixField(array $setting, bool $editable): Section
    {
        $columns = (array) ($setting['columns'] ?? []);
        $rows = array_map(fn (string $row, string $label) => CheckboxList::make("{$setting['field']}.{$row}")->label($label)->options($columns)->columns(max(1, count($columns)))->disabled(! $editable),
            array_map('strval', array_keys(SetupCatalogue::matrixRows($setting))), array_values(SetupCatalogue::matrixRows($setting)));

        return Section::make($setting['label'])->description($setting['help'] ?? null)->compact()->columns(1)->schema($rows);
    }

    /** HROne setup parity CA-1: a file setting is stored on the private documents disk (SettingFiles checks the bytes). */
    private static function fileField(array $setting): FileUpload
    {
        $field = FileUpload::make($setting['field'])->disk(SettingFiles::disk())->directory(fn () => app(SettingFiles::class)->directory())->visibility('private')
            ->maxSize((int) ($setting['max_kb'] ?? config('peopleos.documents.max_kb', 10240)))->downloadable(false)->previewable(false)
            ->hintAction(Action::make('open_'.$setting['field'])->label('Open the current file')->openUrlInNewTab()
                ->url(fn () => app(SettingFiles::class)->url($setting['key']))->visible(fn () => app(SettingFiles::class)->url($setting['key']) !== null));

        return ($setting['accept'] ?? 'document') === 'image' ? $field->image()->acceptedFileTypes(SettingFiles::IMAGE_MIMES) : $field;
    }

    /** HROne setup parity HP-8a ("COPY OKR LABELS"): on the Performance module, name the terms the OKR way. */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('useOkrTerms')->label('Use OKR terms')->icon(Heroicon::OutlinedLanguage)->color('gray')
                ->visible(fn () => $this->module === 'performance' && (auth()->user()?->can('settings.update') ?? false))
                ->modalDescription('KRA is called "Objective", KPI "Key result" and achievement "Progress". You can change each term afterwards.')
                ->schema([AuditReasonField::make()->required()])
                ->action(function (array $data) {
                    try {
                        $changed = PerformanceLabels::useOkrTerms(auth()->user(), AuditReasonField::extract($data) ?? '');
                        Notification::make()->success()->title($changed === [] ? 'The OKR terms are already in use' : 'OKR terms in use')->send();
                        $this->fillForm();
                    } catch (RuntimeException $e) {
                        Notification::make()->danger()->title('Not saved')->body($e->getMessage())->send();
                    }
                }),
        ];
    }

    public function save(): void
    {
        $state = $this->form->getState();
        try {
            $changed = app(SetupCatalogue::class)->save($this->module, $state, AuditReasonField::extract($state) ?? '', auth()->user());
            Notification::make()->success()->title($changed === [] ? 'Nothing changed' : count($changed).' setting(s) saved')->send();
            $this->fillForm();
        } catch (RuntimeException $e) {
            Notification::make()->danger()->title('Not saved')->body($e->getMessage())->send();
        }
    }

    public function canSave(): bool
    {
        return $this->hasSettings() && (auth()->user()?->can('settings.update') ?? false);
    }

    private function fillForm(): void
    {
        $values = [];
        if ($this->hasSettings()) {
            foreach (app(SetupCatalogue::class)->settingsFor($this->module) as $setting) {
                $values[$setting['field']] = $setting['type'] === 'matrix' ? SetupCatalogue::matrixToForm($setting, $setting['value']) : $setting['value'];
            }
        }
        $this->form->fill($values);
    }
}
