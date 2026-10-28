<?php

namespace App\Filament\Pages;

use App\Domain\ComplianceReports\Models\ComplianceReportPack;
use App\Domain\ComplianceReports\Services\ComplianceReports;
use App\Domain\ComplianceReports\Support\ReportCatalogue;
use App\Domain\ComplianceReports\Support\ReportData;
use App\Domain\ComplianceReports\Support\ReportDefinition;
use App\Domain\ComplianceReports\Support\ReportPeriod;
use App\Domain\ComplianceReports\Support\XlsxWorkbook;
use App\Domain\Organisation\Models\Company;
use App\Domain\Organisation\Models\Establishment;
use App\Domain\Payroll\Models\PayrollRun;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Livewire\Attributes\Computed;
use RuntimeException;
use UnitEnum;

/**
 * Compliance reports: pick a company and a period (month, quarter, half-year or year, financial or calendar basis) and
 * see every register, challan working, statement and return working sheet that falls due — central and state-wise —
 * for each establishment, state and legal entity. Download any one, or generate them all at once as a ZIP (built in
 * the background with your access, downloadable by you only). Obligations PeopleOS does not produce are listed as a
 * checklist, and the full India catalogue can be downloaded for review.
 */
class ComplianceReportsPage extends Page implements HasSchemas
{
    use InteractsWithSchemas;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentChartBar;

    protected static string|UnitEnum|null $navigationGroup = 'Compliance';

    protected static ?string $navigationLabel = 'Compliance reports';

    protected static ?string $title = 'Compliance reports';

    protected static ?string $slug = 'compliance-reports';

    protected static ?int $navigationSort = 2;

    protected string $view = 'filament.pages.compliance-reports';

    /** @var array<string, mixed> */
    public array $filters = [];

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user !== null && ! $user->isPlatformAdmin() && ($user->hasPermission('compliance.reports.view') || $user->hasPermission('compliance.reports.generate'));
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        $previousMonth = now()->subMonthNoOverflow();
        $this->form->fill([
            'company_id' => Company::query()->orderBy('id')->value('id'),
            'frequency' => 'monthly',
            'basis' => 'financial',
            'period' => ReportPeriod::month($previousMonth->year, $previousMonth->month)->key(),
            'establishment_ids' => [],
            'states' => [],
            'include_shorter' => true,
        ]);
    }

    public function getSubheading(): ?string
    {
        return 'Registers, challan workings and statements for any month, quarter, half-year or year — every establishment and state at once. Built from finalized payroll, processed attendance and approved leave. Not a filing: returns are generated, approved and filed under Compliance.';
    }

    public function form(Schema $schema): Schema
    {
        $periodOptions = fn (Get $get) => ReportPeriod::options((string) ($get('frequency') ?: 'monthly'), (string) ($get('basis') ?: 'financial'), now()->toImmutable(), $get('frequency') === 'yearly' ? 6 : 24);

        return $schema->statePath('filters')->columns(4)->components([
            Select::make('company_id')->label('Company')->options(fn () => Company::query()->orderBy('name')->pluck('name', 'id')->all())->required()->live()->selectablePlaceholder(false)
                ->afterStateUpdated(function (Set $set) {
                    $set('establishment_ids', []);
                    $set('states', []);
                }),
            Select::make('frequency')->label('Reports due')->options(ReportPeriod::FREQUENCIES)->required()->live()->selectablePlaceholder(false)
                ->afterStateUpdated(fn (Set $set, Get $get) => $set('period', array_key_first($periodOptions($get)))),
            Select::make('basis')->label('Year')->options(ReportPeriod::BASES)->required()->live()->selectablePlaceholder(false)
                ->visible(fn (Get $get) => $get('frequency') !== 'monthly')
                ->afterStateUpdated(fn (Set $set, Get $get) => $set('period', array_key_first($periodOptions($get)))),
            Select::make('period')->label('Period')->options($periodOptions)->required()->live()->selectablePlaceholder(false),
            Select::make('establishment_ids')->label('Establishments')->multiple()->live()->placeholder('All establishments')
                ->options(fn (Get $get) => Establishment::query()->where('company_id', $get('company_id'))->where('status', 'active')->orderBy('name')->pluck('name', 'id')->all()),
            Select::make('states')->label('States')->multiple()->live()->placeholder('All states the company operates in')
                ->options(fn () => $this->stateOptions())
                ->disableOptionWhen(fn (string $value) => ! in_array($value, $this->operatingStates, true))->extraAttributes(['class' => 'pos-state-filter'])
                ->helperText('Greyed-out states have no active establishment and no PT or LWF in payroll for the period. Add an establishment there under Organisation → Establishments to report for it.'),
            Toggle::make('include_shorter')->label('Include monthly reports within the period')->live()->default(true)
                ->visible(fn (Get $get) => $get('frequency') !== 'monthly')->inline(false),
        ]);
    }

    /** @return list<string> the states the company has obligations in for the period (establishments, PT and LWF lines) */
    #[Computed]
    public function operatingStates(): array
    {
        $company = $this->company();

        return $company === null ? [] : app(ComplianceReports::class)->operatingStates($company, $this->period(), $this->reportData);
    }

    /** @return array<string, array<string, string>> every state and UT, code => name, those the company operates in first */
    public function stateOptions(): array
    {
        $all = collect(app(ReportCatalogue::class)->states())->sort();

        return array_filter([
            'Where the company operates' => $all->only($this->operatingStates)->all(),
            'No establishment' => $all->except($this->operatingStates)->all(),
        ]);
    }

    public function company(): ?Company
    {
        return Company::query()->find($this->filters['company_id'] ?? null);
    }

    public function period(): ReportPeriod
    {
        try {
            return ReportPeriod::fromKey((string) ($this->filters['period'] ?? ''));
        } catch (InvalidArgumentException) {
            $previousMonth = now()->subMonthNoOverflow();

            return ReportPeriod::month($previousMonth->year, $previousMonth->month);
        }
    }

    /** @return array<string, mixed> */
    public function options(): array
    {
        return [
            'establishment_ids' => array_map('intval', $this->filters['establishment_ids'] ?? []) ?: null,
            'states' => $this->filters['states'] ?? [] ?: null,
            'include_shorter' => ($this->filters['frequency'] ?? 'monthly') === 'monthly' || (bool) ($this->filters['include_shorter'] ?? true),
        ];
    }

    /** One set of reads per request, shared by the plan and the configuration check. */
    #[Computed]
    public function reportData(): ReportData
    {
        return app(ReportData::class);
    }

    /** @return list<array<string, mixed>> */
    #[Computed]
    public function plan(): array
    {
        $company = $this->company();

        return $company === null ? [] : app(ComplianceReports::class)->plan($company, $this->period(), $this->options(), $this->reportData);
    }

    /** @return list<array{target: string, missing: string, affects: string, action: string}> */
    #[Computed]
    public function configurationGaps(): array
    {
        $company = $this->company();

        return $company === null ? [] : app(ComplianceReports::class)->configurationGaps($company, $this->period(), $this->options(), $this->reportData);
    }

    public function readinessLabel(string $status): string
    {
        return ComplianceReports::READINESS[$status] ?? str_replace('_', ' ', ucfirst(strtolower($status)));
    }

    /** @return Collection<string, list<array<string, mixed>>> plan items grouped by category label */
    public function groupedPlan(): Collection
    {
        return collect($this->plan)->groupBy(fn ($item) => $item['definition']->categoryLabel());
    }

    /** @return list<ReportDefinition> */
    #[Computed]
    public function checklist(): array
    {
        $company = $this->company();

        return $company === null ? [] : app(ComplianceReports::class)->checklist($company, $this->period(), $this->options());
    }

    /** @return list<array{month: string, status: string, ok: bool}> payroll state of each month of the period */
    public function payrollStatus(): array
    {
        $company = $this->company();
        if ($company === null) {
            return [];
        }

        return array_map(function (ReportPeriod $month) use ($company) {
            $run = PayrollRun::query()->where('company_id', $company->getKey())
                ->whereHas('period', fn ($q) => $q->where('year', $month->start->year)->where('month', $month->start->month))->orderByDesc('id')->first();

            return ['month' => $month->start->format('M Y'), 'status' => $run === null ? 'No payroll run' : (string) config("peopleos.payroll.run_statuses.{$run->status}", $run->status), 'ok' => in_array($run?->status, ['finalized', 'paid'], true)];
        }, $this->period()->months());
    }

    /** @return Collection<int, ComplianceReportPack> */
    public function packs(): Collection
    {
        return ComplianceReportPack::query()->with(['company', 'requester', 'reviewer'])->orderByDesc('id')->limit(15)->get();
    }

    public function hasPendingPacks(): bool
    {
        return ComplianceReportPack::query()->whereIn('status', ['queued', 'running'])->exists();
    }

    public function canGenerate(): bool
    {
        return (bool) auth()->user()?->hasPermission('compliance.reports.generate');
    }

    public function canDownloadPack(ComplianceReportPack $pack): bool
    {
        return app(ComplianceReports::class)->canDownload($pack, auth()->user());
    }

    public function canReviewPack(ComplianceReportPack $pack): bool
    {
        $user = auth()->user();

        return $pack->status === 'completed' && $pack->reviewed_at === null && (int) $pack->requested_by !== (int) $user?->getKey() && (bool) $user?->hasPermission('compliance.reports.review');
    }

    public function canRetryPack(ComplianceReportPack $pack): bool
    {
        return $pack->status === 'failed' && (int) $pack->requested_by === (int) auth()->id() && $this->canGenerate();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('generateAll')->label('Generate all (ZIP)')->icon(Heroicon::OutlinedArchiveBoxArrowDown)
                ->visible(fn () => $this->canGenerate())
                ->modalHeading(fn () => 'Generate every report for '.$this->period()->label())
                ->modalDescription(fn () => count(array_filter($this->plan, fn ($i) => $i['applicable'])).' report(s) fall due for the establishments, states and legal entities selected. PeopleOS builds them in the background as you (your access applies), with an index and a checklist of what is kept outside PeopleOS. Only you can download the ZIP.')
                ->schema([
                    Toggle::make('unmasked')->label('Include UAN, ESI IP numbers, PAN, Aadhaar and bank accounts in full')
                        ->helperText('Recorded in the audit trail. Without it, identifiers show their last four characters.')
                        ->visible(fn () => (bool) auth()->user()?->hasPermission('compliance.sensitive.view')),
                ])
                ->action(function (array $data) {
                    $this->attempt(fn () => app(ComplianceReports::class)->request($this->company() ?? throw new RuntimeException('Choose a company.'), $this->period(), auth()->user(), $this->options() + ['unmasked' => (bool) ($data['unmasked'] ?? false)]),
                        'Compliance pack requested: it appears under "Packs" when ready.');
                }),
            Action::make('catalogue')->label('India compliance catalogue')->icon(Heroicon::OutlinedBookOpen)->color('gray')
                ->action(fn () => response()->streamDownload(fn () => print (XlsxWorkbook::contents(app(ComplianceReports::class)->catalogueSheets())), 'india-hr-compliance-catalogue.xlsx', ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'])),
        ];
    }

    public function downloadReportAction(): Action
    {
        return Action::make('downloadReport')->label('Download')->icon(Heroicon::OutlinedArrowDownTray)->size('sm')->link()
            ->visible(fn () => $this->canGenerate())
            ->action(function (array $arguments) {
                try {
                    $file = app(ComplianceReports::class)->single($this->company() ?? throw new RuntimeException('Choose a company.'), $this->period(), (string) ($arguments['item'] ?? ''), auth()->user(), false, $this->options());

                    return response()->streamDownload(fn () => print ($file['content']), $file['name'], ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
                } catch (RuntimeException $e) {
                    Notification::make()->danger()->title($e->getMessage())->send();
                }
            });
    }

    public function downloadPackAction(): Action
    {
        return Action::make('downloadPack')->label('Download ZIP')->icon(Heroicon::OutlinedArrowDownTray)->size('sm')->link()
            ->action(function (array $arguments) {
                try {
                    $file = app(ComplianceReports::class)->download($this->pack($arguments), auth()->user());

                    return response()->streamDownload(fn () => print ($file['content']), $file['name'], ['Content-Type' => 'application/zip']);
                } catch (RuntimeException $e) {
                    Notification::make()->danger()->title($e->getMessage())->send();
                }
            });
    }

    public function retryPackAction(): Action
    {
        return Action::make('retryPack')->label('Retry')->icon(Heroicon::OutlinedArrowPath)->size('sm')->link()
            ->action(fn (array $arguments) => $this->attempt(fn () => app(ComplianceReports::class)->retry($this->pack($arguments), auth()->user()), 'Pack queued again.'));
    }

    public function reviewPackAction(): Action
    {
        return Action::make('reviewPack')->label('Record review')->icon(Heroicon::OutlinedCheckBadge)->size('sm')->link()
            ->modalHeading('Record the review of this pack')
            ->modalDescription('Confirms that an authorised person checked the reports. It files nothing, and it cannot be done by the person who generated the pack.')
            ->schema([Textarea::make('note')->label('Review note')->required()->minLength(5)->maxLength(2000)])
            ->action(fn (array $arguments, array $data) => $this->attempt(fn () => app(ComplianceReports::class)->review($this->pack($arguments), auth()->user(), (string) $data['note']), 'Review recorded.'));
    }

    /** A pack of this tenant within the viewer's company scope; anything else reads as not found. */
    private function pack(array $arguments): ComplianceReportPack
    {
        return ComplianceReportPack::query()->find((int) ($arguments['pack'] ?? 0)) ?? throw new RuntimeException('That pack was not found.');
    }

    public function stateName(?string $code): string
    {
        return $code === null ? 'All India' : (app(ReportCatalogue::class)->states()[$code] ?? $code);
    }

    public function reviewed(): array
    {
        return app(ReportCatalogue::class)->reviewed();
    }

    private function attempt(callable $callback, string $success): void
    {
        try {
            $callback();
            Notification::make()->success()->title($success)->send();
        } catch (RuntimeException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();
        }
    }
}
