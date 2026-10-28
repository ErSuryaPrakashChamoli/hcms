<?php

namespace App\Domain\ComplianceReports\Services;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Compliance\Services\GratuityEntitlements;
use App\Domain\Compliance\Services\StatutoryContexts;
use App\Domain\Compliance\Services\StatutoryProfileCorrections;
use App\Domain\ComplianceReports\Jobs\GenerateComplianceReportPack;
use App\Domain\ComplianceReports\Models\ComplianceReportPack;
use App\Domain\ComplianceReports\Support\ReportCatalogue;
use App\Domain\ComplianceReports\Support\ReportContext;
use App\Domain\ComplianceReports\Support\ReportData;
use App\Domain\ComplianceReports\Support\ReportDefinition;
use App\Domain\ComplianceReports\Support\ReportPeriod;
use App\Domain\ComplianceReports\Support\ReportSheet;
use App\Domain\ComplianceReports\Support\ResolvedForm;
use App\Domain\ComplianceReports\Support\XlsxWorkbook;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\AccessScopes;
use App\Domain\Organisation\Models\Company;
use App\Domain\Organisation\Models\Establishment;
use App\Domain\Organisation\Models\LegalEntity;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * Compliance reports (registers, challan workings, statements and return working sheets) for a company and a period,
 * one at a time or all at once as a pack.
 *
 * The plan decides what falls due and where: every catalogue obligation in force in the period whose frequency is the
 * period's (and, unless excluded, the shorter ones — each month of a quarter), for every establishment, state or legal
 * entity it applies to. Applicability is decided per establishment — its state, type, appropriate government,
 * statutory profile and headcount measured as the law measures it — and per period (dated forms and thresholds). A
 * missing fact is never filled in: the item is marked configuration required and says what is missing.
 *
 * Every item carries separate statuses: whether it applies, whether its data is complete, whether it was generated,
 * whether the statutory calculation behind it is verified, whether the legal source is verified, whether an authorised
 * person reviewed the pack, and which external filing remains. Reports are built as the person asking (their access
 * scope applies), identifiers are masked unless they hold compliance.sensitive.view and ask for them in full, and every
 * generation, download and review is audited. Nothing here files a return or changes payroll.
 */
final class ComplianceReports
{
    /** Overall readiness of one report, most severe first. */
    public const READINESS = [
        'BLOCKED' => 'Blocked — generation failed',
        'CONFIGURATION_REQUIRED' => 'Configuration required',
        'INCOMPLETE' => 'Incomplete — data gaps',
        'UNVERIFIED' => 'Legal basis unverified',
        'LEGAL_REVIEW_REQUIRED' => 'Legal review required',
        'LEGAL_SOURCE_VERIFIED' => 'Legal source and calculation verified',
        'INTERNAL_CONTROL' => 'Internal control — not a statutory report',
        'NOT_APPLICABLE' => 'Not applicable',
    ];

    /** Whether an obligation applies, in one word (see determination()). */
    public const DETERMINATIONS = [
        'APPLICABLE' => 'Applicable',
        'NOT_APPLICABLE' => 'Not applicable',
        'PENDING_VERIFICATION' => 'Pending verification',
        'MISSING_CONFIGURATION' => 'Missing configuration',
        'LEGALLY_UNVERIFIED' => 'Legally unverified',
    ];

    /** Requests per user and hour (packs) and per user and minute (single reports): both are heavy reads. */
    private const PACKS_PER_HOUR = 20;

    private const REPORTS_PER_MINUTE = 30;

    /** @var array<string, int> */
    private array $headcounts = [];

    public function __construct(
        private readonly ReportCatalogue $catalogue,
        private readonly StatutoryContexts $contexts,
        private readonly StatutoryProfileCorrections $corrections,
        private readonly AuditRecorder $audit,
        private readonly AccessScopes $scopes,
    ) {}

    /**
     * What falls due for the company in the period. Each item is one report for one target (establishment, state,
     * legal entity or the company) over one or more periods, with its applicability and the reason.
     *
     * @param  array{establishment_ids?: list<int>|null, states?: list<string>|null, report_keys?: list<string>|null, include_shorter?: bool}  $options
     * @return list<array<string, mixed>>
     */
    public function plan(Company $company, ReportPeriod $period, array $options = [], ?ReportData $data = null): array
    {
        $data ??= app(ReportData::class);
        $establishments = $this->establishments($company, $options['establishment_ids'] ?? null, $options['states'] ?? null);
        $states = $this->states($company, $period, $establishments, $data, $options);
        $entities = $establishments->pluck('legalEntity')->filter()->unique('id')->values();
        $includeShorter = (bool) ($options['include_shorter'] ?? true);

        $items = [];
        foreach ($this->catalogue->all() as $definition) {
            if (! $definition->isGenerated() || ! $definition->inForceDuring($period) || (! empty($options['report_keys']) && ! in_array($definition->key, $options['report_keys'], true))) {
                continue;
            }
            $periods = array_values(array_filter($this->periodsFor($definition, $period, $includeShorter), fn (ReportPeriod $p) => $definition->inForceDuring($p)));
            if ($periods === []) {
                continue;
            }

            foreach ($this->targets($definition, $company, $establishments, $states, $entities) as $target) {
                $items[] = $this->item($definition, $company, $target, $periods, $data);
            }
        }

        usort($items, fn ($a, $b) => [array_search($a['definition']->category, array_keys(ReportDefinition::CATEGORIES), true), $a['target']['sort'], $a['definition']->title]
            <=> [array_search($b['definition']->category, array_keys(ReportDefinition::CATEGORIES), true), $b['target']['sort'], $b['definition']->title]);

        return $items;
    }

    /**
     * One report for one target. Applicability and the form are decided for EACH period the report covers (a monthly
     * register in a year pack is decided month by month), so a threshold, form or rule that changes during the year
     * applies from its own date. Periods where the report does not apply are left out and named in the reason.
     *
     * @param  list<ReportPeriod>  $periods
     * @return array<string, mixed>
     */
    private function item(ReportDefinition $definition, Company $company, array $target, array $periods, ReportData $data): array
    {
        $establishment = $target['type'] === 'establishment' ? $target['model'] : null;
        $evaluations = [];
        foreach ($periods as $p) {
            [$applicability, $reason, $threshold] = $this->applicability($definition, $company, $target, $p, $data);
            $resolved = $definition->resolveFor($establishment, $p);
            if ($applicability === 'APPLIES' && $resolved->configurationRequired !== null) {
                [$applicability, $reason] = ['CONFIGURATION_REQUIRED', $resolved->configurationRequired];
            }
            $evaluations[] = compact('p', 'applicability', 'reason', 'threshold', 'resolved');
        }

        $applying = array_values(array_filter($evaluations, fn ($e) => $e['applicability'] !== 'NOT_APPLICABLE'));
        $excluded = array_values(array_filter($evaluations, fn ($e) => $e['applicability'] === 'NOT_APPLICABLE'));
        $basis = $applying !== [] ? $applying[count($applying) - 1] : $evaluations[count($evaluations) - 1];
        $applicability = match (true) {
            $applying === [] => 'NOT_APPLICABLE',
            collect($applying)->contains('applicability', 'CONFIGURATION_REQUIRED') => 'CONFIGURATION_REQUIRED',
            collect($applying)->contains('applicability', 'PENDING_VERIFICATION') => 'PENDING_VERIFICATION',
            default => 'APPLIES',
        };
        $reasons = collect($applying)->pluck('reason')->filter()->unique()->values();
        if ($applying !== [] && $excluded !== []) {
            $reasons->push('Not applicable for '.collect($excluded)->map(fn ($e) => $this->shortPeriod($e['p']))->implode(', ').': '.$excluded[count($excluded) - 1]['reason']);
        }
        $reason = $applying === [] ? $basis['reason'] : ($reasons->implode(' ') ?: null);
        // C12-W: a correction applied after payroll was finalised is disclosed wherever it changed what this report says.
        if ($definition->requires !== null && $establishment !== null
            && ($corrections = $this->corrections->disclosures((int) $establishment->getKey(), $definition->requires, array_map(fn (ReportPeriod $p) => $p->end->toDateString(), $periods))) !== []) {
            $reason = trim(($reason ?? '').' '.implode(' ', $corrections));
        }
        $resolved = $basis['resolved'];

        return [
            'id' => $definition->key.'|'.$target['type'].'|'.$target['id'],
            'definition' => $definition,
            'target' => $target,
            'periods' => $applying !== [] ? array_column($applying, 'p') : $periods,
            'forms' => collect($evaluations)->map(fn ($e) => ['period' => $e['p']->key(), 'form' => $e['resolved']->form, 'confidence' => $e['resolved']->confidence, 'applicability' => $e['applicability']])->all(),
            'resolved' => $resolved,
            'due' => implode('; ', array_unique(array_map(fn ($e) => $definition->dueLabel($e['p']), $applying !== [] ? $applying : $evaluations))),
            'applicability' => $applicability,
            'applicable' => $applicability !== 'NOT_APPLICABLE',
            'reason' => $reason,
            'threshold' => $basis['threshold'],
            'legal' => $resolved->legalStatus(),
            'determination' => $this->determination($applicability, $resolved),
            'effective' => $resolved->effectiveLabel(),
            'sources' => $resolved->sources,
        ];
    }

    /**
     * The one-word answer for an obligation: APPLICABLE (applies, official primary source verified), NOT_APPLICABLE,
     * PENDING_VERIFICATION (applies or may apply, but the source is only partly verified, conflicting or under legal
     * review, or the threshold that would exclude it is not verified), MISSING_CONFIGURATION, LEGALLY_UNVERIFIED (no
     * official source behind it).
     */
    private function determination(string $applicability, ResolvedForm $resolved): string
    {
        return match (true) {
            $applicability === 'CONFIGURATION_REQUIRED' => 'MISSING_CONFIGURATION',
            $applicability === 'NOT_APPLICABLE' => 'NOT_APPLICABLE',
            $resolved->legalStatus() === 'UNVERIFIED' => 'LEGALLY_UNVERIFIED',
            $applicability === 'PENDING_VERIFICATION', $resolved->legalStatus() === 'LEGAL_REVIEW_REQUIRED' => 'PENDING_VERIFICATION',
            default => 'APPLICABLE',
        };
    }

    /**
     * Facts the company has not configured that keep obligations from being determined: an establishment without a
     * state, type or appropriate government, and employees with no establishment assignment. Unaffected reports still
     * run; these are listed in the page, the pack index and the audit record.
     *
     * @return list<array{target: string, missing: string, affects: string, action: string}>
     */
    public function configurationGaps(Company $company, ReportPeriod $period, array $options = [], ?ReportData $data = null): array
    {
        $data ??= app(ReportData::class);
        $gaps = [];
        foreach ($this->establishments($company, $options['establishment_ids'] ?? null, null) as $establishment) {
            $label = $establishment->name.($establishment->code ? " ({$establishment->code})" : '');
            if (ReportCatalogue::canonicalState($establishment->state) === null) {
                $gaps[] = ['target' => $label, 'missing' => 'State', 'affects' => 'Professional tax, labour welfare fund and the state\'s shops and establishments registers and returns cannot be determined; no state is assumed.', 'action' => 'Set the state under Organisation → Establishments.'];
            }
            if (blank($establishment->establishment_type)) {
                $gaps[] = ['target' => $label, 'missing' => 'Establishment type', 'affects' => 'Shops and establishments and factories obligations cannot be told apart.', 'action' => 'Set the establishment type.'];
            }
            if (blank($establishment->appropriate_government)) {
                $gaps[] = ['target' => $label, 'missing' => 'Appropriate government (Code on Wages, OSH Code)', 'affects' => 'Which register forms apply (central rules under the Labour Codes, or the state\'s rules) cannot be decided.', 'action' => 'Set Central or State Government on the establishment.'];
            }
            if (blank($establishment->ss_appropriate_government)) {
                $gaps[] = ['target' => $label, 'missing' => 'Appropriate government (Code on Social Security)', 'affects' => 'Which rules govern gratuity, maternity benefit and employment information cannot be decided (multi-state establishments are Central-sphere under s.2(3)).', 'action' => 'Set Central or State Government for the Code on Social Security on the establishment.'];
            }
        }

        $employees = $data->employees($company, $period->start, $period->end);
        $fallback = $employees->filter(fn ($e) => in_array($e->getAttribute('report_establishment_source'), ['company_primary', null], true))->count();
        if ($fallback > 0) {
            $gaps[] = ['target' => $company->name, 'missing' => "Establishment assignment for {$fallback} employee(s)", 'affects' => 'They are attributed to the principal establishment by fallback, which may be in another state than where they work.', 'action' => 'Assign them under Organisation → Establishment assignments.'];
        }

        return $gaps;
    }

    /**
     * The states the company has state obligations in for the period, whatever the filters: its active establishments'
     * states and the states on PT and LWF lines. Reports can only be produced for these.
     *
     * @return list<string> state codes
     */
    public function operatingStates(Company $company, ReportPeriod $period, ?ReportData $data = null): array
    {
        return $this->states($company, $period, $this->establishments($company, null), $data ?? app(ReportData::class), [])->all();
    }

    /**
     * Obligations in the catalogue PeopleOS does not produce (kept on the portal, in physical registers or other
     * systems) that fall in the period for the company's states.
     *
     * @return list<ReportDefinition>
     */
    public function checklist(Company $company, ReportPeriod $period, array $options = []): array
    {
        $states = $this->establishments($company, $options['establishment_ids'] ?? null, $options['states'] ?? null)
            ->map(fn (Establishment $e) => ReportCatalogue::canonicalState($e->state))->filter()->unique()->all();

        return $this->catalogue->all()
            ->filter(fn (ReportDefinition $d) => ! $d->isGenerated() && $d->inForceDuring($period) && ($d->state === null || in_array($d->state, $states, true)))
            ->filter(fn (ReportDefinition $d) => ! $d->isPeriodic() || ReportPeriod::rank($d->frequency) <= ReportPeriod::rank($period->frequency))
            ->sortBy(fn (ReportDefinition $d) => [$d->state ?? '', $d->title])
            ->values()->all();
    }

    /** One report of the plan as a workbook (a sheet per period, then per part), with its statuses. */
    public function single(Company $company, ReportPeriod $period, string $itemId, User $user, bool $unmasked = false, array $options = []): array
    {
        $this->assertCanGenerate($user, $unmasked);
        $this->throttle("compliance-report:{$user->tenant_id}:{$user->getKey()}", self::REPORTS_PER_MINUTE, 60);
        $data = app(ReportData::class);
        $item = $this->asUser($user, fn () => collect($this->plan($company, $period, $options, $data))->firstWhere('id', $itemId))
            ?? throw new RuntimeException('That report is not due for this period.');
        if ($item['applicability'] === 'NOT_APPLICABLE') {
            throw new RuntimeException('That report does not apply: '.$item['reason']);
        }

        $sheets = $this->asUser($user, fn () => $this->sheets($item, $company, $data, $user, $unmasked));
        $status = $this->status($item, $sheets);
        $content = XlsxWorkbook::contents($sheets);

        $this->audit->record(AuditAction::Export, 'compliance', $company, [], null, actor: $user, metadata: [
            'report' => $item['definition']->key, 'target' => $item['target']['label'], 'period' => $period->key(),
            'rows' => $status['rows'], 'readiness' => $status['readiness'], 'data_gaps' => count($status['warnings']),
            'rules' => array_keys($status['rules']), 'unmasked' => $unmasked,
        ], entityLabel: 'Compliance report '.$item['definition']->title);

        return ['name' => $this->safeName($item['definition']->title.' '.$item['target']['label'].' '.$period->label(), 150).'.xlsx', 'content' => $content, 'status' => $status];
    }

    /**
     * Ask for every report of the period at once; the pack is built in the background. The same request while an
     * earlier one is still queued or running returns that one.
     */
    public function request(Company $company, ReportPeriod $period, User $user, array $options = []): ComplianceReportPack
    {
        $unmasked = (bool) ($options['unmasked'] ?? false);
        $this->assertCanGenerate($user, $unmasked);
        $normalised = [
            'establishment_ids' => array_values(array_map('intval', $options['establishment_ids'] ?? [])) ?: null,
            'states' => array_values($options['states'] ?? []) ?: null,
            'report_keys' => array_values($options['report_keys'] ?? []) ?: null,
            'include_shorter' => (bool) ($options['include_shorter'] ?? true),
            'unmasked' => $unmasked,
        ];

        // One request at a time per person (the user row is the lock): a double click or a second tab returns the
        // pack already queued instead of queueing the same pack twice.
        [$pack, $created] = DB::transaction(function () use ($company, $period, $user, $normalised) {
            User::query()->whereKey($user->getKey())->lockForUpdate()->first();
            $pending = ComplianceReportPack::query()->where('requested_by', $user->getKey())->where('company_id', $company->getKey())
                ->where('period_key', $period->key())->whereIn('status', ['queued', 'running'])->get()
                ->first(fn (ComplianceReportPack $p) => $p->options == $normalised);
            if ($pending !== null) {
                return [$pending, false];
            }
            $this->throttle("compliance-pack:{$user->tenant_id}:{$user->getKey()}", self::PACKS_PER_HOUR, 3600);

            return [ComplianceReportPack::query()->create([
                'company_id' => $company->getKey(),
                'frequency' => $period->frequency,
                'basis' => $period->basis,
                'period_key' => $period->key(),
                'period_start' => $period->start->toDateString(),
                'period_end' => $period->end->toDateString(),
                'options' => $normalised,
                'status' => 'queued',
                'requested_by' => $user->getKey(),
                'scope_fingerprint' => $this->scopeFingerprint($user),
            ]), true];
        });

        if ($created) {
            GenerateComplianceReportPack::dispatch($pack->getKey());
        }

        return $pack->refresh();
    }

    /** Build again a pack whose generation failed (the requester only). */
    public function retry(ComplianceReportPack $pack, User $user): ComplianceReportPack
    {
        if ((int) $pack->requested_by !== (int) $user->getKey() || $pack->status !== 'failed') {
            throw new RuntimeException('Only the person who asked for a failed pack can retry it.');
        }
        $this->assertCanGenerate($user, (bool) ($pack->options['unmasked'] ?? false));
        $pack->update(['status' => 'queued', 'error' => null, 'started_at' => null, 'completed_at' => null, 'scope_fingerprint' => $this->scopeFingerprint($user)]);
        GenerateComplianceReportPack::dispatch($pack->getKey());

        return $pack->refresh();
    }

    /** Build a requested pack (the job's work): every report, an index and a checklist, in one ZIP. */
    public function build(ComplianceReportPack $pack): ComplianceReportPack
    {
        $user = $pack->requester;
        $refusal = match (true) {
            $user === null || ! $user->isActive() => 'The person who asked for this pack is no longer an active user; packs are built with their access.',
            ! $user->hasPermission('compliance.reports.generate') => 'The person who asked for this pack no longer holds permission to generate compliance reports.',
            (bool) ($pack->options['unmasked'] ?? false) && ! $user->hasPermission('compliance.sensitive.view') => 'The pack asked for identifiers in full, which its requester may no longer see.',
            default => null,
        };
        if ($refusal !== null) {
            $pack->update(['status' => 'failed', 'error' => $refusal, 'completed_at' => now()]);

            return $pack;
        }

        $pack->update(['status' => 'running', 'started_at' => now(), 'error' => null]);
        $dir = sys_get_temp_dir().'/peopleos-compliance-pack-'.Str::ulid();
        File::ensureDirectoryExists($dir, 0700);

        try {
            $result = $this->asUser($user, fn () => $this->write($pack, $user, $dir));
            $disk = (string) config('peopleos.storage.compliance_disk', 'local');
            // Deterministic per pack: a retried build replaces its own file, never adds another.
            $path = "tenants/{$pack->tenant_id}/compliance-reports/{$pack->getKey()}-".Str::slug($pack->period()->label()).'.zip';
            $zip = $dir.'/pack.zip';
            $stream = fopen($zip, 'rb');
            try {
                Storage::disk($disk)->writeStream($path, $stream);
            } finally {
                is_resource($stream) && fclose($stream);
            }

            $pack->update([
                'status' => 'completed', 'completed_at' => now(), 'disk' => $disk, 'path' => $path,
                'size_bytes' => filesize($zip), 'sha256' => hash_file('sha256', $zip),
                'report_count' => $result['generated'], 'empty_count' => $result['empty'], 'manifest' => $result['manifest'], 'metadata' => $result['metadata'],
            ]);
            $this->audit->record(AuditAction::Export, 'compliance', $pack, [], null, actor: $user, metadata: [
                'period' => $pack->period_key, 'reports' => $result['generated'], 'empty' => $result['empty'], 'failed' => $result['failed'],
                'readiness' => $result['metadata']['readiness'], 'configuration_gaps' => count($result['metadata']['configuration_gaps']),
                'unmasked' => (bool) ($pack->options['unmasked'] ?? false),
            ]);
        } catch (Throwable $e) {
            $reference = (string) Str::ulid();
            $this->logFailure('Compliance report pack failed', $e, ['pack' => $pack->getKey(), 'reference' => $reference]);
            $pack->update(['status' => 'failed', 'completed_at' => now(), 'error' => "The pack could not be built (reference {$reference}). Retry it, or ask an administrator to look the reference up in the application log."]);
        } finally {
            File::deleteDirectory($dir);
        }

        return $pack->refresh();
    }

    /**
     * Only the requester, still holding the permissions and the access the pack was built with: a pack holds what they
     * could see then, so it is not handed over after their access narrowed or their sensitive-data permission ended.
     */
    public function canDownload(ComplianceReportPack $pack, User $user): bool
    {
        return $this->downloadRefusal($pack, $user) === null;
    }

    /** @return array{name: string, content: string} */
    public function download(ComplianceReportPack $pack, User $user): array
    {
        if (($refusal = $this->downloadRefusal($pack, $user)) !== null) {
            throw new RuntimeException($refusal);
        }
        // A built pack is never rebuilt: the file handed over is the one fingerprinted when it was built, or nothing.
        $content = (string) Storage::disk((string) $pack->disk)->get((string) $pack->path);
        if ($pack->sha256 === null || ! hash_equals((string) $pack->sha256, hash('sha256', $content))) {
            throw new RuntimeException('This pack no longer matches the fingerprint recorded when it was built, so it is not handed over. Generate the reports again.');
        }
        $this->audit->record(AuditAction::Download, 'compliance', $pack, [], null, actor: $user, metadata: ['period' => $pack->period_key, 'size_bytes' => $pack->size_bytes, 'sha256' => $pack->sha256]);

        return ['name' => basename((string) $pack->path), 'content' => $content];
    }

    /**
     * An authorised review of a completed pack, by someone other than its requester. It records that the reports were
     * checked; it files nothing and changes no file.
     */
    public function review(ComplianceReportPack $pack, User $reviewer, string $note): ComplianceReportPack
    {
        if (! $reviewer->hasPermission('compliance.reports.review')) {
            throw new RuntimeException('You need permission to review compliance reports.');
        }
        if ($pack->status !== 'completed') {
            throw new RuntimeException('Only a completed pack can be reviewed.');
        }
        if ((int) $pack->requested_by === (int) $reviewer->getKey()) {
            throw new RuntimeException('The person who generated a pack cannot review it.');
        }
        if ($pack->reviewed_at !== null) {
            throw new RuntimeException('This pack has already been reviewed.');
        }
        if (mb_strlen(trim($note)) < 5) {
            throw new RuntimeException('Write a review note.');
        }
        $pack->withAuditReason($note)->update(['reviewed_by' => $reviewer->getKey(), 'reviewed_at' => now(), 'review_note' => trim($note)]);
        $this->audit->record(AuditAction::Reviewed, 'compliance', $pack, [], $note, actor: $reviewer, metadata: ['period' => $pack->period_key]);

        return $pack->refresh();
    }

    /**
     * The whole India catalogue for review: every central and state obligation with its law, form, applicability,
     * frequency, due date, sources and confidence, and per state the professional tax, labour welfare fund and shops
     * and establishments position.
     *
     * @return list<ReportSheet>
     */
    public function catalogueSheets(): array
    {
        $reviewed = $this->catalogue->reviewed();
        $header = [['Reviewed', (string) ($reviewed['on'] ?? '')], ['Position', (string) ($reviewed['position'] ?? '')], ['Catalogue checksum', $this->catalogue->checksum()],
            ['Read this first', 'Generating a register or working sheet in PeopleOS is not a filing. Returns and challans are filed by the employer or its authorised representative on the authority\'s portal.']];
        $states = $this->catalogue->states();
        $row = function (ReportDefinition $d) use ($states) {
            $threshold = collect($d->thresholds)->map(fn ($t) => ($t['min'] ?? '?').'+ ('.str_replace('_', ' ', $t['measure'] ?? 'employed_any_day').')'.(isset($t['from']) ? ' from '.$t['from'] : '').(isset($t['to']) ? ' to '.$t['to'] : ''))->implode('; ');

            return [
                'state' => $d->state ? ($states[$d->state] ?? $d->state) : 'All India', 'title' => $d->title, 'obligation' => $d->obligation === 'statutory' ? 'Legal obligation' : 'Internal control',
                'output' => $d->outputLabel(), 'law' => $d->legalBasis, 'form' => $d->form,
                'variants' => collect($d->variants)->map(fn ($v) => ($v['sphere'] ?? 'any').(isset($v['from']) ? ' from '.$v['from'] : '').(isset($v['to']) ? ' to '.$v['to'] : '').': '.($v['form'] ?? ''))->implode(' | '),
                'types' => $d->establishmentTypes ? implode(', ', array_map(fn ($t) => (string) config("peopleos.organisation.establishment_types.{$t}", $t), $d->establishmentTypes)) : 'All',
                'threshold' => $threshold, 'registration' => $d->registration ? (string) config("peopleos.compliance.registration_types.{$d->registration}.label", $d->registration) : ($d->requires ?? ''),
                'frequency' => $d->frequencyLabel(), 'due' => $d->dueText, 'effective' => trim(($d->effectiveFrom ? 'from '.$d->effectiveFrom : '').($d->effectiveTo ? ' to '.$d->effectiveTo : '')),
                'sources' => collect($d->sources)->map(fn ($s) => is_array($s) ? implode(' — ', array_filter($s)) : (string) $s)->implode(' | '),
                'confidence' => ReportDefinition::CONFIDENCE[$d->confidence] ?? $d->confidence, 'verified_on' => (string) $d->verifiedOn,
                'configured' => $d->isGenerated() ? 'Yes — generated' : 'No — checklist only', 'filing' => (string) $d->filing,
                'review' => (string) ($d->legalReview ?? ($d->confidence === 'OFFICIAL_PRIMARY_SOURCE_VERIFIED' ? '' : 'Confirm with an Indian labour-law or tax professional')),
                'notes' => implode(' ', $d->notes),
            ];
        };
        $columns = ['state' => 'Where', 'title' => 'Obligation', 'obligation' => 'Kind', 'output' => 'What PeopleOS produces', 'law' => 'Applicable law', 'form' => 'Form / register / return',
            'variants' => 'Form by appropriate government and period', 'types' => 'Establishment types', 'threshold' => 'Threshold', 'registration' => 'Registration / statute required',
            'frequency' => 'Frequency', 'due' => 'Due', 'effective' => 'In force', 'sources' => 'Sources', 'confidence' => 'Source confidence', 'verified_on' => 'Last verified',
            'configured' => 'Configured in PeopleOS', 'filing' => 'External filing (by the employer)', 'review' => 'Outstanding legal review', 'notes' => 'Notes'];
        $all = $this->catalogue->all();
        $summary = fn (string $part, array $fields) => collect($states)->map(fn ($name, $code) => ['state' => "{$name} ({$code})"] + collect($fields)->mapWithKeys(fn ($label, $key) => [$key => (string) (is_bool($v = $this->catalogue->state($code)[$part][$key] ?? '') ? ($v ? 'Yes' : 'No') : (is_array($v) ? implode(', ', $v) : $v))])->all())->values()->all();
        $ptFields = ['levied' => 'Levied', 'act' => 'Act', 'frequency' => 'Return / payment', 'due' => 'Due', 'form' => 'Form', 'confidence' => 'Confidence', 'note' => 'Note'];
        $lwfFields = ['applies' => 'Applies', 'act' => 'Act', 'employee' => 'Employee', 'employer' => 'Employer', 'frequency' => 'Frequency', 'due' => 'Due', 'form' => 'Form', 'confidence' => 'Confidence', 'note' => 'Note'];
        $shopFields = ['act' => 'Act', 'registers' => 'Registers', 'returns' => 'Returns', 'note' => 'Note'];

        return [
            new ReportSheet('India HR compliance — all-India obligations', $columns, $all->whereNull('state')->map($row)->values()->all(), $header, sheetName: 'All India'),
            new ReportSheet('India HR compliance — state obligations', $columns, $all->whereNotNull('state')->sortBy(fn ($d) => [$d->state, $d->title])->map($row)->values()->all(), $header, sheetName: 'States'),
            new ReportSheet('Professional tax by state', ['state' => 'State'] + $ptFields, $summary('pt', $ptFields), $header, sheetName: 'Professional tax'),
            new ReportSheet('Labour welfare fund by state', ['state' => 'State'] + $lwfFields, $summary('lwf', $lwfFields), $header, sheetName: 'Labour welfare fund'),
            new ReportSheet('Shops and establishments by state', ['state' => 'State'] + $shopFields, $summary('shops', $shopFields), $header, sheetName: 'Shops & establishments'),
        ];
    }

    /**
     * The statuses of a generated report: applicability, data, generation, calculation, legal source, readiness and
     * the external filing that remains.
     *
     * @param  list<ReportSheet>  $sheets
     * @return array<string, mixed>
     */
    public function status(array $item, array $sheets): array
    {
        $rows = array_sum(array_map(fn (ReportSheet $s) => $s->rowCount(), $sheets));
        $warnings = array_values(array_unique(array_merge(...array_map(fn (ReportSheet $s) => $s->warnings, $sheets ?: [new ReportSheet('', [])]))));
        $rules = array_replace(...array_map(fn (ReportSheet $s) => $s->rules, $sheets ?: [new ReportSheet('', [])]));
        $calculation = $rules === [] ? 'NOT_APPLICABLE' : (collect($rules)->every(fn ($r) => ($r['verification_status'] ?? null) === 'verified') ? 'VERIFIED' : 'UNVERIFIED');

        return [
            'applicability' => $item['applicability'],
            'determination' => $item['determination'],
            'data' => $warnings === [] ? 'COMPLETE' : 'INCOMPLETE',
            'generation' => $rows > 0 ? 'GENERATED' : 'EMPTY',
            'calculation' => $calculation,
            'legal' => $item['legal'],
            'readiness' => match (true) {
                $item['applicability'] === 'NOT_APPLICABLE' => 'NOT_APPLICABLE',
                $item['applicability'] === 'CONFIGURATION_REQUIRED' => 'CONFIGURATION_REQUIRED',
                $warnings !== [] => 'INCOMPLETE',
                $item['applicability'] === 'PENDING_VERIFICATION' => 'LEGAL_REVIEW_REQUIRED',
                $item['legal'] === 'NOT_STATUTORY' => $calculation === 'UNVERIFIED' ? 'LEGAL_REVIEW_REQUIRED' : 'INTERNAL_CONTROL',
                $item['legal'] === 'UNVERIFIED' => 'UNVERIFIED',
                $item['legal'] !== 'LEGAL_SOURCE_VERIFIED' || $calculation === 'UNVERIFIED' => 'LEGAL_REVIEW_REQUIRED',
                default => 'LEGAL_SOURCE_VERIFIED',
            },
            'filing' => $item['definition']->filing,
            'rows' => $rows,
            'warnings' => $warnings,
            'rules' => $rules,
        ];
    }

    /** @return array{generated: int, empty: int, failed: int, manifest: list<array<string, mixed>>, metadata: array<string, mixed>} */
    private function write(ComplianceReportPack $pack, User $user, string $dir): array
    {
        $company = $pack->company;
        $period = $pack->period();
        $options = (array) $pack->options;
        $unmasked = (bool) ($options['unmasked'] ?? false) && $user->hasPermission('compliance.sensitive.view');
        $data = app(ReportData::class);
        $zip = new ZipArchive;
        if ($zip->open($dir.'/pack.zip', ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('The ZIP file could not be created.');
        }

        $manifest = [];
        $counts = ['generated' => 0, 'empty' => 0, 'failed' => 0];
        $names = [];
        $allRules = [];
        foreach ($this->plan($company, $period, $options, $data) as $item) {
            $definition = $item['definition'];
            $entry = ['report' => $definition->title, 'key' => $definition->key, 'category' => $definition->categoryLabel(), 'output' => $definition->outputLabel(),
                'form' => $item['resolved']->form, 'legal_basis' => $item['resolved']->legalBasis, 'confidence' => $item['resolved']->confidence,
                'target' => $item['target']['label'], 'periods' => implode(', ', array_map(fn (ReportPeriod $p) => $p->label(), $item['periods'])), 'due' => $item['due'],
                'applicability' => $item['applicability'], 'determination' => $item['determination'], 'legal' => $item['legal'], 'effective' => $item['effective'],
                'sources' => collect($item['sources'])->map(fn ($s) => is_array($s) ? implode(' — ', array_filter($s)) : (string) $s)->implode(' | '),
                'reason' => $item['reason'], 'filing' => $definition->filing, 'rows' => 0, 'file' => '', 'warnings' => [], 'rules' => []];

            if ($item['applicability'] === 'NOT_APPLICABLE') {
                $manifest[] = $entry + ['status' => 'not_applicable', 'readiness' => 'NOT_APPLICABLE', 'note' => (string) $item['reason']];

                continue;
            }

            try {
                $sheets = $this->sheets($item, $company, $data, $user, $unmasked);
                $status = $this->status($item, $sheets);
                $file = $this->fileName($item, $names);
                $local = $dir.'/'.md5($file).'.xlsx';
                XlsxWorkbook::write($sheets, $local);
                if (! $zip->addFile($local, $file)) {
                    throw new RuntimeException('The workbook could not be added to the ZIP.');
                }
                $generated = $status['rows'] > 0 ? 'generated' : 'empty';
                $counts[$generated]++;
                $allRules += $status['rules'];
                $manifest[] = array_merge($entry, [
                    'status' => $generated, 'readiness' => $status['readiness'], 'data' => $status['data'], 'calculation' => $status['calculation'],
                    'rows' => $status['rows'], 'file' => $file, 'warnings' => $status['warnings'], 'rules' => array_keys($status['rules']),
                    'note' => trim(($item['reason'] ? $item['reason'].' ' : '').($status['rows'] > 0 ? '' : (string) collect($sheets)->pluck('message')->filter()->first())),
                ]);
            } catch (Throwable $e) {
                $reference = (string) Str::ulid();
                $this->logFailure('Compliance report failed', $e, ['pack' => $pack->getKey(), 'report' => $definition->key, 'reference' => $reference]);
                $counts['failed']++;
                $manifest[] = $entry + ['status' => 'failed', 'readiness' => 'BLOCKED', 'note' => "Generation failed (reference {$reference}); no workbook is included. Retry the pack."];
            }
        }

        $gaps = $this->configurationGaps($company, $period, $options, $data);
        $metadata = [
            'catalogue_checksum' => $this->catalogue->checksum(),
            'catalogue_reviewed' => $this->catalogue->reviewed()['on'] ?? null,
            'rules' => array_values($allRules),
            'payroll_runs' => $data->payrollRunIds(),
            'configuration_gaps' => $gaps,
            'readiness' => collect($manifest)->countBy('readiness')->all(),
            'unmasked' => $unmasked,
            'generated_at' => now()->toIso8601String(),
        ];

        $index = $dir.'/index.xlsx';
        XlsxWorkbook::write($this->indexSheets($pack, $manifest, $this->checklist($company, $period, $options), $gaps, $allRules, $user, $unmasked), $index);
        $zip->addFile($index, '00 Index.xlsx');
        if (! $zip->close()) {
            throw new RuntimeException('The ZIP file could not be written.');
        }

        // The index must describe exactly what the ZIP holds.
        $check = new ZipArchive;
        $check->open($dir.'/pack.zip');
        $inZip = collect(range(0, $check->numFiles - 1))->map(fn ($i) => $check->getNameIndex($i))->all();
        $check->close();
        $listed = collect($manifest)->pluck('file')->filter()->values()->all();
        if (array_diff($listed, $inZip) !== [] || array_diff($inZip, [...$listed, '00 Index.xlsx']) !== []) {
            throw new RuntimeException('The ZIP does not match its index.');
        }

        return $counts + ['manifest' => $manifest, 'metadata' => $metadata];
    }

    /** @return list<ReportSheet> */
    private function sheets(array $item, Company $company, ReportData $data, User $user, bool $unmasked): array
    {
        $definition = $item['definition'];
        $target = $item['target'];
        $builder = $this->catalogue->builder($definition);
        $sheets = [];
        $multiple = count($item['periods']) > 1;

        foreach ($item['periods'] as $period) {
            $context = new ReportContext(
                company: $company, period: $period, definition: $definition, data: $data, user: $user,
                establishment: $target['type'] === 'establishment' ? $target['model'] : null,
                legalEntity: $target['type'] === 'legal_entity' ? $target['model'] : null,
                state: $target['type'] === 'state' ? $target['id'] : null,
                unmasked: $unmasked,
            );
            foreach ($builder->build($context) as $sheet) {
                $sheets[] = $multiple ? $sheet->withSheetName($this->shortPeriod($period).' '.($sheet->sheetName ?: $definition->title)) : $sheet;
            }
        }

        return $sheets;
    }

    /** @return list<ReportPeriod> the periods of a report that fall in this one */
    private function periodsFor(ReportDefinition $definition, ReportPeriod $period, bool $includeShorter): array
    {
        if ($definition->frequency === 'register') {
            return [$period];
        }
        if (! $definition->isPeriodic()) {
            return [];
        }
        $rank = ReportPeriod::rank($definition->frequency);
        if ($rank > ReportPeriod::rank($period->frequency) || (! $includeShorter && $rank < ReportPeriod::rank($period->frequency))) {
            return [];
        }

        return $period->periodsEndingWithin($definition->frequency, $definition->basis ?? $period->basis);
    }

    /** The states the company's state obligations are due in: its establishments' states and the states on PT and LWF lines. */
    private function states(Company $company, ReportPeriod $period, Collection $establishments, ReportData $data, array $options): Collection
    {
        $states = $establishments->map(fn (Establishment $e) => ReportCatalogue::canonicalState($e->state))->filter();
        if (empty($options['establishment_ids'])) {
            $states = $states->merge($data->payrollStates($company, $period->start, $period->end, 'PT'))->merge($data->payrollStates($company, $period->start, $period->end, 'LWF'));
        }

        return $states->unique()->when(! empty($options['states']), fn ($s) => $s->intersect($options['states']))->sort()->values();
    }

    /** @return list<array{type: string, id: int|string, label: string, model: mixed, sort: string}> */
    private function targets(ReportDefinition $definition, Company $company, Collection $establishments, Collection $states, Collection $entities): array
    {
        return match ($definition->scope) {
            'establishment' => $establishments
                ->filter(fn (Establishment $e) => $definition->state === null || ReportCatalogue::canonicalState($e->state) === $definition->state)
                ->map(fn (Establishment $e) => ['type' => 'establishment', 'id' => $e->getKey(), 'label' => $e->name.($e->state ? " ({$e->state})" : ' (no state)'), 'model' => $e, 'sort' => '1'.$e->name])->values()->all(),
            'state' => $states
                ->filter(fn (string $s) => $definition->state === null || $s === $definition->state)
                ->map(fn (string $s) => ['type' => 'state', 'id' => $s, 'label' => $this->catalogue->states()[$s] ?? $s, 'model' => null, 'sort' => '2'.$s])->values()->all(),
            'legal_entity' => $definition->state !== null ? [] : $entities
                ->map(fn (LegalEntity $e) => ['type' => 'legal_entity', 'id' => $e->getKey(), 'label' => $e->legal_name, 'model' => $e, 'sort' => '3'.$e->legal_name])->values()->all(),
            default => [['type' => 'company', 'id' => $company->getKey(), 'label' => $company->name, 'model' => $company, 'sort' => '0']],
        };
    }

    /**
     * Whether an obligation applies to its target in the period: APPLIES, NOT_APPLICABLE (with the exact legal reason,
     * the threshold, its measure and source) or CONFIGURATION_REQUIRED (with what is missing).
     *
     * @return array{0: string, 1: ?string, 2: ?array}
     */
    private function applicability(ReportDefinition $definition, Company $company, array $target, ReportPeriod $period, ReportData $data): array
    {
        if ($target['type'] !== 'establishment') {
            return ['APPLIES', null, null];
        }
        /** @var Establishment $establishment */
        $establishment = $target['model'];

        if ($definition->sphere !== null) {
            $government = $definition->appropriateGovernment($establishment);
            if ($government === null) {
                return ['CONFIGURATION_REQUIRED', 'This obligation depends on the appropriate government'.($definition->code === 'ss' ? ' under the Code on Social Security' : '').', which is not set for the establishment.', null];
            }
            if ($government !== $definition->sphere) {
                return ['NOT_APPLICABLE', 'Applies where the '.($definition->sphere === 'central' ? 'Central' : 'State').' Government is the appropriate government; this establishment is set to '.$government.'.', null];
            }
        }
        if ($definition->establishmentTypes !== null) {
            if (blank($establishment->establishment_type)) {
                return ['CONFIGURATION_REQUIRED', 'The establishment type is not set, so whether this applies cannot be decided.', null];
            }
            if (! in_array($establishment->establishment_type, $definition->establishmentTypes, true)) {
                return ['NOT_APPLICABLE', 'Applies to '.implode(', ', array_map(fn ($t) => config("peopleos.organisation.establishment_types.{$t}", $t), $definition->establishmentTypes)).' establishments; this one is a '.config("peopleos.organisation.establishment_types.{$establishment->establishment_type}", $establishment->establishment_type).'.', null];
            }
        }
        if ($definition->requires !== null && ! $this->applies($establishment, $definition->requires, $period)) {
            return ['NOT_APPLICABLE', "{$definition->requires} does not apply to this establishment under its statutory profile on ".$period->end->format('d M Y').'.', null];
        }
        if (($threshold = $definition->thresholdFor($period)) !== null && ($threshold['measure'] ?? null) === 'gratuity_coverage') {
            // The First Schedule's own test (Chapter V and s.1(8)): factories, mines and plantations whatever their size,
            // others from the threshold on their busiest day, and once covered, always. Decided where entitlements are.
            $coverage = app(GratuityEntitlements::class)->coverage($establishment, $period->end);
            $threshold += ['count' => $coverage['headcount_max'] ?? null];
            $source = ($threshold['basis'] ?? '') !== '' ? ' Source: '.$threshold['basis'].'.' : '';

            return match ($coverage['status']) {
                'covered' => ['APPLIES', null, $threshold],
                'not_covered' => ($threshold['confidence'] ?? null) === 'OFFICIAL_PRIMARY_SOURCE_VERIFIED'
                    ? ['NOT_APPLICABLE', $coverage['reason'].$source, $threshold] : ['PENDING_VERIFICATION', 'May not apply: '.$coverage['reason'].$source, $threshold],
                default => ['CONFIGURATION_REQUIRED', $coverage['reason'], null],
            };
        }
        if ($threshold !== null) {
            $measure = (string) ($threshold['measure'] ?? 'employed_any_day');
            $count = $this->headcounts["{$establishment->getKey()}:{$period->key()}:{$measure}"] ??= $data->headcount($company, $establishment, $period, $measure);
            $threshold += ['count' => $count];
            if ($count < (int) $threshold['min']) {
                $source = trim(($threshold['basis'] ?? '').(isset($threshold['confidence']) ? ' ['.(ReportDefinition::CONFIDENCE[$threshold['confidence']] ?? $threshold['confidence']).']' : ''));
                $window = (isset($threshold['from']) ? ' from '.$threshold['from'] : '').(isset($threshold['to']) ? ' to '.$threshold['to'] : '');
                $message = "Applies from {$threshold['min']} employees ({$this->measureLabel($measure)}){$window}; PeopleOS counts {$count} here.".($source !== '' ? " Source: {$source}." : '').' Contract labour and others PeopleOS does not hold are not counted — check before relying on this.';

                // A threshold not read in the official text never excludes an obligation by itself: the report is
                // still produced and marked as pending verification.
                return ($threshold['confidence'] ?? null) === 'OFFICIAL_PRIMARY_SOURCE_VERIFIED'
                    ? ['NOT_APPLICABLE', $message, $threshold]
                    : ['PENDING_VERIFICATION', 'May not apply: '.$message, $threshold];
            }
        }

        return ['APPLIES', null, $threshold];
    }

    private function measureLabel(string $measure): string
    {
        return ['employed_any_day' => 'largest number employed on any day of the period', 'employed_period_end' => 'employed on the period end', 'employed_during' => 'employed at any time in the period',
            'gratuity_coverage' => 'largest number employed on any day up to the period end; once covered, always'][$measure] ?? $measure;
    }

    /** Decided exactly as payroll decides it on the period end, so a pack never disagrees with the payroll it reports. */
    private function applies(Establishment $establishment, string $statute, ReportPeriod $period): bool
    {
        $on = $period->end->toDateString();

        return $this->contexts->applicability($establishment, [$on])[$on][$statute] ?? false;
    }

    /** @return Collection<int, Establishment> active establishments of the company the user may see, in the states chosen */
    private function establishments(Company $company, ?array $ids, ?array $states = null): Collection
    {
        return Establishment::query()->with('legalEntity')->where('company_id', $company->getKey())->where('status', 'active')
            ->when(! empty($ids), fn ($q) => $q->whereIn('id', $ids))->orderByDesc('is_primary')->orderBy('name')->get()
            ->when(! empty($states), fn ($all) => $all->filter(fn (Establishment $e) => in_array(ReportCatalogue::canonicalState($e->state), $states, true))->values());
    }

    /** A path inside the ZIP: every part cleaned of separators and dot-segments, so no entry escapes its folder. */
    private function fileName(array $item, array &$taken): string
    {
        $target = $item['target'];
        [$folder, $name] = match ($target['type']) {
            'establishment' => ['Establishments', ($target['model']->code ? $target['model']->code.' - ' : '').$target['model']->name],
            'state' => ['States', $target['label']],
            'legal_entity' => ['Legal entities', $target['label']],
            default => ['Company', null],
        };
        $base = $folder.($name !== null ? '/'.$this->safeName($name) : '').'/'.$this->safeName($item['definition']->title);
        $file = $base.'.xlsx';
        for ($i = 2; in_array($file, $taken, true); $i++) {
            $file = "{$base} ({$i}).xlsx";
        }
        $taken[] = $file;

        return $file;
    }

    /** One path segment: no separators, control characters or reserved characters, no leading dots, bounded length. */
    private function safeName(string $value, int $limit = 100): string
    {
        // Separators, control and reserved characters become spaces and runs of dots collapse to one, so a name can
        // never form a path segment such as "..", whatever an establishment is called.
        $clean = (string) preg_replace('/\.{2,}/', '.', (string) preg_replace('/[\x00-\x1F\x7F\/\\\\:*?"<>|]+/u', ' ', $value));
        $clean = ltrim(trim((string) preg_replace('/\s+/', ' ', $clean)), '. ');

        return mb_substr($clean !== '' ? $clean : 'report', 0, $limit);
    }

    private function shortPeriod(ReportPeriod $period): string
    {
        return match ($period->frequency) {
            'monthly' => $period->start->format('M Y'),
            'yearly' => $period->yearLabel(),
            default => explode(' (', $period->label())[0],
        };
    }

    /** @return list<ReportSheet> */
    private function indexSheets(ComplianceReportPack $pack, array $manifest, array $checklist, array $gaps, array $rules, User $user, bool $unmasked): array
    {
        $statuses = ['generated' => 'Generated', 'empty' => 'No records', 'not_applicable' => 'Not applicable', 'failed' => 'Failed — no workbook'];
        $header = [['Employer', $pack->company?->legal_name ?: $pack->company?->name], ['Period', $pack->period()->label()], ['Generated', now()->format('d-m-Y H:i').' for '.$user->name],
            ['Identifiers', $unmasked ? 'In full (audited)' : 'Masked (last four characters)'], ['Catalogue', ($this->catalogue->reviewed()['on'] ?? '').' · checksum '.substr($this->catalogue->checksum(), 0, 12)],
            ['Review', 'Not reviewed when generated — an authorised reviewer records the review in PeopleOS (Compliance → Compliance reports).'],
            ['Filing', 'Nothing in this pack has been filed. Returns and challans are filed by the employer or its authorised representative on the authority\'s portal.']];
        $reviewed = $this->catalogue->reviewed();
        $readiness = fn (?string $r) => self::READINESS[$r] ?? (string) $r;

        return [
            new ReportSheet('Compliance reports — index', [
                'category' => 'Category', 'report' => 'Report', 'output' => 'What it is', 'form' => 'Form', 'legal_basis' => 'Legal basis', 'confidence' => 'Source confidence',
                'target' => 'For', 'periods' => 'Period(s)', 'due' => 'Due', 'status' => 'Generation', 'readiness' => 'Readiness', 'data' => 'Data', 'calculation' => 'Calculation rules',
                'determination' => 'Determination', 'effective' => 'Form in force', 'sources' => 'Sources',
                'filing' => 'External filing', 'rows' => 'Rows', 'file' => 'File', 'note' => 'Reason / note', 'gaps' => 'Data gaps',
            ], array_map(fn ($m) => array_merge($m, [
                'status' => $statuses[$m['status']] ?? $m['status'], 'readiness' => $readiness($m['readiness'] ?? null),
                'confidence' => ReportDefinition::CONFIDENCE[$m['confidence'] ?? ''] ?? ($m['confidence'] ?? ''),
                'data' => $m['data'] ?? '', 'calculation' => $m['calculation'] ?? '', 'filing' => $m['filing'] ?? '', 'gaps' => implode(' ', $m['warnings'] ?? []),
                'determination' => self::DETERMINATIONS[$m['determination'] ?? ''] ?? ($m['determination'] ?? ''), 'effective' => $m['effective'] ?? '', 'sources' => $m['sources'] ?? '',
            ]), $manifest), $header, sheetName: 'Reports',
                notes: ['Readiness: "Legal source and calculation verified" only where the official text was read for the form and every statutory rule version used is verified. Everything else needs review before it is relied on.']),
            new ReportSheet('Configuration required', ['target' => 'Where', 'missing' => 'Missing', 'affects' => 'What it affects', 'action' => 'What to do'], $gaps, $header, sheetName: 'Configuration',
                message: 'No configuration gaps found for this period.'),
            new ReportSheet('Statutory rule versions used', ['label' => 'Rule version', 'verification_status' => 'Verification', 'status_at_calculation' => 'At calculation'], array_values($rules), $header, sheetName: 'Rules',
                message: 'No statutory rule version was used (no statutory amounts in this period).'),
            new ReportSheet('Kept outside PeopleOS — checklist', ['title' => 'Obligation', 'state' => 'State', 'frequency' => 'Frequency', 'form' => 'Form', 'legal_basis' => 'Legal basis', 'due' => 'Due', 'note' => 'Note'],
                array_map(fn (ReportDefinition $d) => ['title' => $d->title, 'state' => $d->state ? ($this->catalogue->states()[$d->state] ?? $d->state) : 'All India', 'frequency' => $d->frequencyLabel(),
                    'form' => $d->form, 'legal_basis' => $d->legalBasis, 'due' => $d->dueLabel($pack->period()), 'note' => implode(' ', $d->notes)], $checklist), $header, sheetName: 'Checklist',
                message: 'Nothing else falls due in this period.'),
            new ReportSheet('About these reports', ['item' => 'Item', 'detail' => 'Detail'], [
                ['item' => 'Catalogue reviewed', 'detail' => (string) ($reviewed['on'] ?? '')],
                ['item' => 'Legal position', 'detail' => (string) ($reviewed['position'] ?? '')],
                ['item' => 'Formats', 'detail' => 'Each workbook carries the particulars of the form named in a PeopleOS layout. None is a filing-ready return: where a portal or a state prescribes its own layout, transfer the particulars into it.'],
                ['item' => 'Data', 'detail' => 'Wages, deductions and contributions come from finalized payroll only (the variance check also reads unfinalized runs and says so); attendance from processed attendance; leave from approved leave.'],
                ['item' => 'Reproducibility', 'detail' => 'The pack records the rule versions and payroll runs it read and the catalogue checksum; regenerating later can differ only if those change.'],
            ], $header, sheetName: 'About'),
        ];
    }

    private function downloadRefusal(ComplianceReportPack $pack, User $user): ?string
    {
        return match (true) {
            ! $pack->hasFile() => 'This pack has no file to download.',
            (int) $pack->requested_by !== (int) $user->getKey() => 'Only the person who generated this pack may download it: it holds what they could see.',
            ! $user->hasPermission('compliance.reports.generate') => 'You no longer hold permission to download compliance reports.',
            (bool) ($pack->options['unmasked'] ?? false) && ! $user->hasPermission('compliance.sensitive.view') => 'This pack holds identifiers in full, which you may no longer see.',
            $pack->scope_fingerprint !== null && ! hash_equals((string) $pack->scope_fingerprint, $this->scopeFingerprint($user)) => 'Your access has changed since this pack was generated. Generate a new pack.',
            default => null,
        };
    }

    /** A digest of the user's organisation access scope (null scope = tenant-wide). */
    private function scopeFingerprint(User $user): string
    {
        $scope = $this->scopes->for($user) ?? [];
        ksort($scope);
        $scope = array_map(function ($ids) {
            sort($ids);

            return $ids;
        }, $scope);

        return hash('sha256', (string) json_encode($scope));
    }

    private function assertCanGenerate(User $user, bool $unmasked): void
    {
        if (! $user->hasPermission('compliance.reports.generate')) {
            throw new RuntimeException('You need permission to generate compliance reports.');
        }
        if ($unmasked && ! $user->hasPermission('compliance.sensitive.view')) {
            throw new RuntimeException('Only people allowed to see unmasked statutory identifiers can ask for them in full.');
        }
    }

    private function throttle(string $key, int $max, int $seconds): void
    {
        if (RateLimiter::tooManyAttempts($key, $max)) {
            throw new RuntimeException('Too many compliance report requests: try again in '.RateLimiter::availableIn($key).' seconds.');
        }
        RateLimiter::hit($key, $seconds);
    }

    /** Logs a failure without the database bindings or messages that could carry personal data. */
    private function logFailure(string $message, Throwable $e, array $context): void
    {
        Log::error($message, $context + [
            'exception' => $e::class,
            'error' => $e instanceof QueryException ? 'Database error (SQLSTATE '.$e->getCode().')' : Str::limit($e->getMessage(), 300),
            'at' => $e->getFile().':'.$e->getLine(),
        ]);
    }

    /** Run as the user: their access scope applies to every query of the reports (queue workers have no user). */
    private function asUser(User $user, callable $callback): mixed
    {
        $previous = auth()->user();
        auth()->setUser($user);
        try {
            return $callback();
        } finally {
            $previous ? auth()->setUser($previous) : auth()->forgetUser();
        }
    }
}
