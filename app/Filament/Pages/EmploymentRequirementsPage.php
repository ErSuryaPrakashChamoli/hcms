<?php

namespace App\Filament\Pages;

use App\Domain\Configuration\Applicability\ApplicabilityRefusedException;
use App\Domain\Configuration\Applicability\EntityApplicability;
use App\Domain\Configuration\Applicability\SharedConfigurationGuard;
use App\Domain\Configuration\Exceptions\ConfigurationException;
use App\Domain\Configuration\Models\ConfigurationOverride;
use App\Domain\Configuration\Models\Policy;
use App\Domain\Configuration\Overrides\ConfigurationOverrides;
use App\Domain\Employment\Models\Employee;
use App\Domain\Employment\Requirements\EmploymentRequirementPreflight;
use App\Domain\Employment\Requirements\EmploymentRequirements;
use App\Domain\Employment\Requirements\EmploymentRequirementsPreflight;
use App\Domain\Identity\Models\User;
use App\Domain\Organisation\Models\Company;
use App\Filament\Support\AuditReasonField;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use UnitEnum;

/**
 * Master relationships R2 (OD-3): the employment data requirements and their pre-flight. For each policy: what it
 * requires and checks, and how today's data stands against it (people who would fail each rule on their next change,
 * companies that could not meet a required field). "Switch to Block" publishes the chosen rules on Block with the
 * pre-flight record the policy needs; Warn and Off are set in the policy wizard as usual.
 */
class EmploymentRequirementsPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    protected static string|UnitEnum|null $navigationGroup = 'Configuration';

    protected static ?string $navigationLabel = 'Employment data requirements';

    protected static ?string $title = 'Employment data requirements';

    protected static ?string $slug = 'employment-data-requirements';

    protected static ?int $navigationSort = 40;

    protected string $view = 'filament.pages.employment-requirements';

    #[Url(as: 'policy')]
    public ?int $policyId = null;

    /**
     * The pre-flight on show, kept between requests: it reads every employee the policy reaches, so it runs when the
     * page opens, when another policy is chosen, on "Run the pre-flight again" and after a switch (which runs its own).
     *
     * @var array<string, mixed>|null
     */
    public ?array $preflight = null;

    /** Remediation I.13: the queued run on show (above the queue threshold the pre-flight runs in the background). */
    #[Locked]
    public ?int $runId = null;

    public static function canAccess(): bool
    {
        return auth()->user()?->can('policy.view') ?? false;
    }

    public function mount(): void
    {
        $this->policyId ??= $this->policies()->keys()->first();
        $this->load();
    }

    /** Below the threshold: the pre-flight runs now, as before. Above it: the latest run of the policy is shown. */
    public function load(): void
    {
        $policy = $this->policy();
        $service = app(EmploymentRequirementsPreflight::class);
        if ($policy === null || ! $service->queues()) {
            $this->runId = null;
            $this->runPreflight();

            return;
        }
        $run = $service->latestRun($policy);
        $this->runId = $run?->id;
        $this->preflight = $run !== null && $run->status === EmploymentRequirementPreflight::COMPLETED && $service->usableRun($policy, (array) $run->settings)?->is($run) ? $this->forViewer($run) : null;
    }

    /**
     * A queued run's report for the person looking: its examples were picked as the person who asked for it, so each
     * viewer sees only the examples of people they may see themselves (counts are of everyone, as always).
     *
     * @return array<string, mixed>
     */
    private function forViewer(EmploymentRequirementPreflight $run): array
    {
        $report = (array) $run->report;
        $ids = collect($report['rules'] ?? [])->flatMap(fn (array $r) => $r['sample_ids'] ?? [])->unique()->values()->all();
        $visible = Employee::query()->whereKey($ids)->pluck('id')->map(fn ($id) => (int) $id)->flip();
        foreach ($report['rules'] ?? [] as $rule => $row) {
            $keep = [];
            foreach ($row['samples'] ?? [] as $i => $sample) {
                if (isset($row['sample_ids'][$i]) && isset($visible[(int) $row['sample_ids'][$i]])) {
                    $keep[] = $sample;
                }
            }
            $report['rules'][$rule]['samples'] = $keep;
            unset($report['rules'][$rule]['sample_ids']);
        }

        return $report;
    }

    public function runPreflight(): void
    {
        $policy = $this->policy();
        $service = app(EmploymentRequirementsPreflight::class);
        if ($policy !== null && $service->queues()) {
            try {
                $run = $service->record($policy, auth()->user(), queue: true);
            } catch (ConfigurationException $e) {
                Notification::make()->danger()->title('Not started')->body($e->getMessage())->send();

                return;
            }
            $this->runId = $run->id;
            $this->preflight = $run->status === EmploymentRequirementPreflight::COMPLETED ? $this->forViewer($run) : null;
            Notification::make()->info()->title('Pre-flight queued')->body('It reads every employee the policy reaches in the background. This page follows its progress.')->send();

            return;
        }
        $report = $policy === null ? null : $service->run($policy);
        foreach ($report['rules'] ?? [] as $rule => $row) {
            unset($report['rules'][$rule]['sample_ids']);   // examples here are already the viewer's own
        }
        $this->preflight = $report;
    }

    /** Polled while a queued run is open. */
    public function refreshRun(): void
    {
        $run = $this->run();
        if ($run !== null && ! $run->isOpen()) {
            $this->preflight = $run->status === EmploymentRequirementPreflight::COMPLETED ? $this->forViewer($run) : null;
        }
    }

    public function run(): ?EmploymentRequirementPreflight
    {
        $policy = $this->policy();   // one of the requirements policies listed here, or nothing

        return $this->runId === null || $policy === null ? null : EmploymentRequirementPreflight::query()->where('policy_id', $policy->id)->find($this->runId);
    }

    public function cancelRun(): void
    {
        $run = $this->run();
        if ($this->restriction() !== null) {
            Notification::make()->danger()->title('Not cancelled')->body((string) $this->restriction())->send();

            return;
        }
        if ($run !== null && $run->isOpen() && (auth()->user()?->can('policy.update') ?? false)) {
            $run->update(['status' => EmploymentRequirementPreflight::CANCELLED, 'finished_at' => now(), 'error' => 'Cancelled by '.auth()->user()->name.'.']);
            Notification::make()->success()->title('Pre-flight cancelled')->send();
        }
    }

    /**
     * Remediation I.7: for each company the version in force reaches while something is on Block, whether a pre-flight
     * covers its settings as they are now (its own values applied).
     *
     * @return list<array{company: string, state: string, own: bool}>
     */
    public function coverage(): array
    {
        $policy = $this->policy();
        $version = $policy?->versionEffectiveOn(today());
        $settings = (array) ($version?->settings ?? []);
        if ($version === null || EmploymentRequirements::blockRules($settings) === []) {
            return [];
        }
        $overrides = app(ConfigurationOverrides::class);
        $preflight = (array) ($settings['preflight'] ?? []);
        $prints = (array) ($preflight['company_fingerprints'] ?? []);
        $names = Company::query()->whereKey(array_map('intval', (array) ($preflight['companies'] ?? [])))->orderBy('name')->pluck('name', 'id');
        $rows = [];
        foreach ($names as $id => $name) {
            $own = $overrides->valuesFor('policy', (int) $policy->id, (int) $id) !== [];
            $effective = EmploymentRequirements::fingerprint($own ? $overrides->applyTo($settings, 'policy', (int) $policy->id, (int) $id) : $settings);
            $state = match (true) {
                ($prints[$id] ?? $preflight['fingerprint'] ?? null) === $effective => 'Covered by the pre-flight of this version',
                EmploymentRequirementsPreflight::companyCoverage((int) $policy->id, (int) $id, $effective) !== null => 'Covered by its own pre-flight',
                default => 'Warns until a pre-flight covers its settings as they are now',
            };
            $rows[] = ['company' => $name, 'state' => $state, 'own' => $own];
        }

        return $rows;
    }

    /** @return Collection<int, EmploymentRequirementPreflight> the latest company pre-flights of the policy */
    public function companyRuns()
    {
        return EmploymentRequirementPreflight::query()->where('policy_id', $this->policyId)->where('purpose', EmploymentRequirementPreflight::COMPANY)
            ->with('company')->latest('id')->limit(5)->get();
    }

    /** @return Collection<int, Policy> */
    public function policies()
    {
        return Policy::query()->where('type', EmploymentRequirements::TYPE)->orderBy('name')->get()->keyBy('id');
    }

    public function policy(): ?Policy
    {
        return $this->policyId === null ? null : $this->policies()->get($this->policyId);
    }

    public function choose(int $id): void
    {
        $this->policyId = $id;
        $this->load();
    }

    /** @return array<string, mixed>|null */
    public function report(): ?array
    {
        return $this->preflight;
    }

    /** @return array{required_on_hire: list<string>, required_on_change: list<string>, required_mode: string, checks: array<string, string>}|null */
    public function settings(): ?array
    {
        $policy = $this->policy();
        $version = $policy?->versionEffectiveOn(today()) ?? $policy?->versions()->first();

        return $version === null ? null : EmploymentRequirements::normalise((array) $version->settings);
    }

    /** Remediation I.9: why this person may view but not change the policy, or null when they may change it. */
    public function restriction(): ?string
    {
        $policy = $this->policy();
        $user = auth()->user();
        if ($policy === null || ! $user instanceof User || app(EntityApplicability::class)->editableBy($policy, $user)) {
            return null;
        }

        return app(SharedConfigurationGuard::class)->explain($policy, $user, 'change it or switch its rules to Block');
    }

    /** @return array<int, string> companies with own values of the policy now */
    public function ownValueCompanies(): array
    {
        $policy = $this->policy();
        if ($policy === null) {
            return [];
        }
        $ids = ConfigurationOverride::query()->where('configuration_type', 'policy')->where('configuration_id', $policy->id)
            ->where('status', ConfigurationOverride::ACTIVE)->distinct()->pluck('company_id')->all();

        return Company::query()->whereKey($ids)->orderBy('name')->pluck('name', 'id')->all();
    }

    /** @return array<string, string> the company's own values of the policy: path => label */
    public function ownValues(int $companyId): array
    {
        $policy = $this->policy();
        $overrides = app(ConfigurationOverrides::class);
        $fields = $policy === null ? [] : $overrides->fields($policy);

        return collect($policy === null ? [] : $overrides->valuesFor('policy', (int) $policy->id, $companyId))
            ->mapWithKeys(fn ($value, string $path) => [$path => $fields[$path]->label ?? $path])->all();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('rerun')->label('Run the pre-flight again')->color('gray')->icon(Heroicon::OutlinedArrowPath)
                ->visible(fn () => $this->policy() !== null)
                ->action(fn () => $this->runPreflight()),
            Action::make('cancelRun')->label('Cancel the pre-flight')->color('gray')->icon(Heroicon::OutlinedXMark)
                ->visible(fn () => ($this->run()?->isOpen() ?? false) && (auth()->user()?->can('policy.update') ?? false))
                ->requiresConfirmation()->action(fn () => $this->cancelRun()),
            Action::make('endCompanyValues')->label('End a company\'s own values')->color('gray')->icon(Heroicon::OutlinedBuildingOffice2)
                ->visible(fn () => $this->policy() !== null && $this->ownValueCompanies() !== [] && (auth()->user()?->can(ConfigurationOverrides::PERMISSION) ?? false))
                ->modalDescription('The company goes back to the shared values. When those block anything, the company is checked with them first: its values end only once that is safe, so it never takes on a Block nobody checked for it.')
                ->schema([
                    Select::make('company_id')->label('Company')->required()->live()->options(fn () => $this->ownValueCompanies()),
                    CheckboxList::make('paths')->label('Own values to end')->required()
                        ->options(fn (Get $get) => filled($get('company_id')) ? $this->ownValues((int) $get('company_id')) : []),
                    Toggle::make('accept_gaps')->label('End them although some current people would fail a rule on Block (their next change then needs the data)'),
                    DatePicker::make('effective_from')->label('From')->default(today())->minDate(today())->required(),
                    AuditReasonField::make()->required(),
                ])
                ->action(function (array $data) {
                    try {
                        $outcome = app(EmploymentRequirementsPreflight::class)->endCompanyValues($this->policy(), (int) $data['company_id'], array_values((array) $data['paths']),
                            auth()->user(), (string) AuditReasonField::extract($data), $data['effective_from'], (bool) ($data['accept_gaps'] ?? false));
                    } catch (ConfigurationException|ApplicabilityRefusedException $e) {
                        Notification::make()->danger()->title('Not ended')->body($e->getMessage())->persistent()->send();

                        return;
                    }
                    $notice = Notification::make()->title(match ($outcome['outcome']) {
                        'pending' => 'Checking first', 'refused' => 'Not ended', default => 'Back to the shared values'
                    })->body($outcome['message']);
                    match ($outcome['outcome']) {
                        'refused' => $notice->danger()->persistent()->send(), 'pending' => $notice->info()->persistent()->send(), default => $notice->success()->send()
                    };
                    $this->load();
                }),
            Action::make('editPolicy')->label('Edit in the policy wizard')->color('gray')
                ->visible(fn () => $this->policy() !== null && (auth()->user()?->can('policy.update') ?? false))
                ->url(fn () => PolicyWizard::getUrl(['policy' => $this->policyId])),
            Action::make('newPolicy')->label('New requirements policy')->color('gray')
                ->visible(fn () => auth()->user()?->can('policy.create') ?? false)
                ->url(fn () => PolicyWizard::getUrl(['type' => EmploymentRequirements::TYPE])),
            Action::make('switchToBlock')->label('Switch to Block')->color('danger')->icon(Heroicon::OutlinedNoSymbol)
                ->visible(fn () => $this->policy() !== null && (auth()->user()?->can('policy.publish') ?? false))
                ->disabled(fn () => $this->restriction() !== null)->tooltip(fn () => $this->restriction())
                ->modalDescription('Publishes a new version with these rules on Block. The pre-flight runs again first; a rule a company cannot meet is refused.')
                ->schema([
                    CheckboxList::make('rules')->label('Rules to block')->required()
                        ->options(fn () => collect($this->report()['rules'] ?? [])->reject(fn (array $r) => $r['mode'] === EmploymentRequirements::BLOCK)->map(fn (array $r) => $r['label'])->all()),
                    Toggle::make('accept_gaps')->label('Block although some current people would fail (their next change then needs the data)'),
                    DatePicker::make('effective_from')->label('From')->default(today())->minDate(today())->required(),
                    AuditReasonField::make()->required(),
                ])
                ->action(function (array $data) {
                    try {
                        app(EmploymentRequirementsPreflight::class)->switchToBlock($this->policy(), (array) $data['rules'], auth()->user(), (string) AuditReasonField::extract($data),
                            $data['effective_from'], (bool) ($data['accept_gaps'] ?? false));
                        $this->load();
                        Notification::make()->success()->title('Published with Block')->send();
                    } catch (ConfigurationException $e) {
                        Notification::make()->danger()->title('Not switched to Block')->body($e->getMessage())->persistent()->send();
                    }
                }),
        ];
    }
}
