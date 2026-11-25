<?php

namespace App\Domain\Employment\Requirements;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Configuration\Applicability\ConfigurationTypes;
use App\Domain\Configuration\Applicability\EntityApplicability;
use App\Domain\Configuration\Applicability\SharedConfigurationGuard;
use App\Domain\Configuration\Exceptions\ConfigurationException;
use App\Domain\Configuration\Models\ConfigurationOverride;
use App\Domain\Configuration\Models\Policy;
use App\Domain\Configuration\Models\PolicyVersion;
use App\Domain\Configuration\Overrides\ConfigurationOverrides;
use App\Domain\Configuration\Overrides\SettingsPath;
use App\Domain\Configuration\Services\EmployeeRuleContext;
use App\Domain\Configuration\Services\Policies;
use App\Domain\Configuration\Services\PolicyResolver;
use App\Domain\Employment\Jobs\RunEmploymentRequirementsPreflight;
use App\Domain\Employment\Models\Employee;
use App\Domain\Employment\Models\EmployeePosition;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Organisation\Models\Company;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Master relationships R2, OD-3: before a rule of an employment data requirements policy may Block, a pre-flight
 * checks it against today's data. For the people the policy reaches it counts who would fail each rule on their next
 * change, and for each company it checks the required masters exist (a company with no grades cannot require one).
 * "Switch to Block" publishes a new version with the chosen rules on Block and the pre-flight record the policy
 * validator asks for (EmploymentRequirements::validatePolicySettings). Read-only otherwise.
 *
 * Counts cover everyone the policy reaches whatever the viewer may see (examples name only people they may see), and
 * each company is checked with its own values of the policy (configuration overrides).
 *
 * Remediation (2026-11-25):
 * - I.7: the pre-flight record names, for each company it checked, the fingerprint of that company's settings (its own
 *   values applied). At run time a rule blocks for a company only while that company's settings still have that
 *   fingerprint (PositionRequirements), so ending or changing a company's own values can never bring in a Block nobody
 *   checked: the company warns until a pre-flight covers it. endCompanyValues() is the controlled way: it checks the
 *   company with the settings it will have, then ends its values and records the coverage, or refuses.
 * - I.13: above a threshold of employed people the pre-flight runs on the queue as a run (EmploymentRequirementPreflight)
 *   with a status and progress. Only a completed run whose fingerprints (the evaluated settings, who the policy reaches,
 *   the companies' own values) are still current, and that is recent, counts as evidence for Block.
 */
final class EmploymentRequirementsPreflight
{
    private const SAMPLES = 10;

    /** A running run whose worker has not finished it within the job's time limit (3600 s) may be claimed again. */
    private const LEASE_SECONDS = 3700;

    public function __construct(
        private readonly PolicyResolver $resolver,
        private readonly EmployeeRuleContext $context,
        private readonly PositionRequirements $requirements,
        private readonly EntityApplicability $applicability,
        private readonly Policies $policies,
        private readonly AuditRecorder $audit,
        private readonly ConfigurationOverrides $overrides,
    ) {}

    /**
     * @param  array<string, mixed>|null  $settings  proposed settings (default: the version in force, else the latest)
     * @param  int|null  $onlyCompany  only that company's people and masters (a company's coverage)
     * @param  array<int, array<string, mixed>>  $companySettings  company => the settings to evaluate it with instead
     * @param  (Closure(int, int): void)|null  $progress  after each batch: (people read, people to read); may throw to stop
     * @return array{policy: string, on: string, employees: int, companies: array<int, string>, fingerprint: string,
     *     company_fingerprints: array<int, string>,
     *     rules: array<string, array{label: string, mode: string, violations: int, samples: list<string>, unconfigured: list<string>, safe: bool}>}
     */
    public function run(Policy $policy, ?array $settings = null, CarbonInterface|string|null $on = null, ?int $onlyCompany = null, array $companySettings = [], ?Closure $progress = null): array
    {
        $on = Carbon::parse($on ?? today())->startOfDay();
        $settings ??= $this->currentSettings($policy, $on);
        $normal = EmploymentRequirements::normalise($settings);
        $label = $policy->name.' (pre-flight)';
        $candidates = $this->resolver->candidates(EmploymentRequirements::TYPE, $on);

        $rules = [];
        if ($normal['required_mode'] !== EmploymentRequirements::OFF && ($normal['required_on_hire'] !== [] || $normal['required_on_change'] !== [])) {
            $rules[EmploymentRequirements::REQUIRED] = $normal['required_mode'];
        }
        foreach ($normal['checks'] as $check => $mode) {
            if ($mode !== EmploymentRequirements::OFF) {
                $rules[$check] = $mode;
            }
        }
        $report = array_map(fn (string $mode) => ['mode' => $mode, 'violations' => 0, 'samples' => [], 'sample_ids' => [], 'unconfigured' => []], $rules);
        $reached = 0;
        $companies = [];

        $own = [];
        $settingsFor = function (int $company) use (&$own, $policy, $settings, $on, $companySettings): array {
            return $companySettings[$company] ?? $own[$company] ??= $this->overrides->hasAny('policy', (int) $policy->id)
                ? $this->overrides->applyTo($settings, 'policy', (int) $policy->id, $company, $on) : $settings;
        };

        $total = $progress === null ? 0 : Employee::query()->withoutGlobalScope(AccessScope::class)->employed()->count();
        $read = 0;
        Employee::query()->withoutGlobalScope(AccessScope::class)->employed()->with('person')->orderBy('id')
            ->chunkById(500, function ($chunk) use (&$report, &$reached, &$companies, &$read, $candidates, $policy, $settingsFor, $label, $on, $onlyCompany, $progress, $total) {
                // Examples name only people the viewer may see; everything else reads the whole organisation.
                $visible = Employee::query()->whereKey($chunk->modelKeys())->pluck('id')->flip();
                AccessScope::withoutScoping(function () use ($chunk, $visible, &$report, &$reached, &$companies, $candidates, $policy, $settingsFor, $label, $on, $onlyCompany) {
                    $contexts = $this->context->buildMany($chunk, $on);
                    $seats = EmployeePosition::query()->whereIn('employee_id', $chunk->modelKeys())->effectiveOn($on)
                        ->orderByDesc('effective_from')->get(['employee_id', 'position_id'])->unique('employee_id')->pluck('position_id', 'employee_id');
                    // The salary structure check reads each person's salary assignment: one query per batch, not per person.
                    $this->requirements->primeSalaryAssignments($chunk->modelKeys(), $on);
                    foreach ($chunk as $employee) {
                        $context = $contexts[$employee->id] ?? null;
                        if ($context === null || blank($context['company_id'] ?? null)
                            || ($onlyCompany !== null && (int) $context['company_id'] !== $onlyCompany)
                            || $this->resolver->firstMatching($candidates, $context, $on)?->policy_id !== $policy->id) {
                            continue;
                        }
                        $reached++;
                        $companies[(int) $context['company_id']] = true;
                        $position = array_intersect_key($context, EmployeePosition::DIMENSIONS) + ['position_id' => $seats[$employee->id] ?? null];
                        foreach ($this->requirements->evaluate($position, 'transfer', $settingsFor((int) $context['company_id']), $label, $on, $employee)->problems as $problem) {
                            if (! isset($report[$problem->rule])) {
                                continue;
                            }
                            $report[$problem->rule]['violations']++;
                            if (isset($visible[$employee->id]) && count($report[$problem->rule]['samples']) < self::SAMPLES) {
                                $report[$problem->rule]['samples'][] = trim(($employee->employee_code ?? '#'.$employee->id).' — '.$problem->message);
                                $report[$problem->rule]['sample_ids'][] = (int) $employee->id;   // to show each viewer only their own
                            }
                        }
                    }
                });
                $read += $chunk->count();
                if ($progress !== null) {
                    $progress($read, max($total, $read));
                }
            });
        $this->requirements->forgetSalaryAssignments();

        // The companies the policy can reach even with nobody there yet: those its rules name, else every company it
        // applies to. A company's coverage looks at that company only.
        if ($onlyCompany !== null) {
            $companies = [$onlyCompany => true];
        } else {
            $all = null;
            foreach ($policy->assignmentRules()->where('status', 'active')->get() as $rule) {
                $named = collect((array) $rule->conditions)->first(fn ($c) => ($c['field'] ?? null) === 'company_id' && in_array($c['operator'] ?? 'in', ['in', 'equals'], true));
                $ids = $named !== null ? (array) $named['value']
                    : ($this->applicability->reach($policy, $on) ?? ($all ??= Company::query()->withoutGlobalScope(AccessScope::class)->pluck('id')->all()));
                foreach ($ids as $id) {
                    $companies[(int) $id] = true;
                }
            }
        }

        // A required field a company cannot fill: no master of that kind may be used there (with the company's own values).
        $names = Company::query()->withoutGlobalScope(AccessScope::class)->whereKey(array_keys($companies))->orderBy('name')->pluck('name', 'id')->all();
        if (isset($report[EmploymentRequirements::REQUIRED])) {
            AccessScope::withoutScoping(function () use (&$report, $names, $settingsFor) {
                foreach (array_keys($names) as $companyId) {
                    $own = EmploymentRequirements::normalise($settingsFor((int) $companyId));
                    if ($own['required_mode'] === EmploymentRequirements::OFF) {
                        continue;
                    }
                    foreach (array_values(array_unique([...$own['required_on_hire'], ...$own['required_on_change']])) as $column) {
                        $model = (new EmployeePosition)->{EmployeePosition::DIMENSIONS[$column][0]}()->getRelated()::class;
                        if ($this->applicability->options($model, (int) $companyId) === []) {
                            $report[EmploymentRequirements::REQUIRED]['unconfigured'][] = "{$names[$companyId]} has no ".strtolower(EmployeePosition::DIMENSIONS[$column][1]).' it may use.';
                        }
                    }
                }
            });
        }

        $out = [];
        foreach ($report as $rule => $row) {
            $out[$rule] = ['label' => EmploymentRequirements::ruleLabel($rule)] + $row + ['safe' => $row['unconfigured'] === [] && $row['violations'] === 0];
        }

        return ['policy' => $policy->name, 'on' => $on->toDateString(), 'employees' => $reached, 'companies' => $names,
            'fingerprint' => EmploymentRequirements::fingerprint($settings),
            'company_fingerprints' => array_map(fn (int $id) => EmploymentRequirements::fingerprint($settingsFor($id)), array_combine(array_keys($names), array_keys($names)) ?: []),
            'rules' => $out];
    }

    /**
     * Publish a new version with the chosen rules on Block, after a pre-flight of exactly those settings. Refused when
     * a company cannot meet a required field; when people would fail today, only with $acceptCurrentGaps (their next
     * change then needs the data first). Above the queue threshold the pre-flight is a completed queued run of these
     * rules (run the pre-flight first); below it, it runs here.
     *
     * @param  list<string>  $rules  'required' and/or check keys
     */
    public function switchToBlock(Policy $policy, array $rules, User $actor, string $reason, CarbonInterface|string|null $from = null, bool $acceptCurrentGaps = false): PolicyVersion
    {
        if ($policy->type !== EmploymentRequirements::TYPE) {
            throw new ConfigurationException('Only an employment data requirements policy has a pre-flight.');
        }
        if (! $actor->can('policy.update') || ! $actor->can('policy.publish')) {
            throw new ConfigurationException('Switching rules to Block publishes the policy: you need to be allowed to change and publish policies.');
        }
        if (! $this->applicability->editableBy($policy, $actor)) {
            throw new ConfigurationException(app(SharedConfigurationGuard::class)->explain($policy, $actor, 'switch its rules to Block'));
        }
        if (trim($reason) === '') {
            throw new ConfigurationException('Give the reason for blocking.');
        }
        $known = [EmploymentRequirements::REQUIRED, ...array_keys(EmploymentRequirements::CHECKS)];
        $rules = array_values(array_unique(array_intersect($known, $rules)));
        if ($rules === []) {
            throw new ConfigurationException('Choose the rules to switch to Block.');
        }
        if ($policy->draft()->exists()) {
            throw new ConfigurationException('A draft of this policy is waiting: publish or discard it first, then run the pre-flight again.');
        }
        $base = $policy->versionEffectiveOn(today())?->settings ?? throw new ConfigurationException('Publish the policy before switching rules to Block.');
        unset($base['preflight']);

        $settings = $base;
        foreach ($rules as $rule) {
            $settings[$rule === EmploymentRequirements::REQUIRED ? 'required_mode' : "check_{$rule}"] = EmploymentRequirements::BLOCK;
        }
        $run = $this->queues()
            ? ($this->usableRun($policy, $settings) ?? throw new ConfigurationException('This organisation has more than '.number_format($this->threshold())
                .' people, so its pre-flight runs in the background: run the pre-flight, wait until it has completed, then switch. A pre-flight older than '
                .$this->evidenceHours().' hours, or from before the policy, the companies it reaches or their own values changed, does not count.'))
            : $this->record($policy, $actor, EmploymentRequirementPreflight::SWITCH, $settings, reason: $reason, queue: false);
        // Only a completed run that still matches who the policy reaches and the companies' own values vouches for Block
        // (a run superseded or stopped while it read people keeps a report, but it no longer describes the policy).
        if ($run->status !== EmploymentRequirementPreflight::COMPLETED) {
            throw new ConfigurationException('The pre-flight did not complete ('.$run->status.($run->error ? ': '.$run->error : '').'). Run it again, then switch.');
        }
        if ($run->applicability_fingerprint !== $this->applicabilityFingerprint($policy) || $run->overrides_fingerprint !== $this->overridesFingerprint($policy)) {
            throw new ConfigurationException('The companies the policy reaches or their own values changed since the pre-flight. Run it again, then switch.');
        }
        $report = (array) $run->report;
        // Every rule on Block after the switch is checked, the ones already on Block too: the record vouches for all.
        $rules = array_values(array_unique([...$rules, ...EmploymentRequirements::blockRules($settings)]));
        foreach ($rules as $rule) {
            $row = $report['rules'][$rule] ?? null;
            if ($row === null) {
                throw new ConfigurationException('"'.EmploymentRequirements::ruleLabel($rule).'" has nothing to block: give it fields or switch it on first.');
            }
            if ($row['unconfigured'] !== []) {
                throw new ConfigurationException('Not safe to block "'.$row['label'].'": '.implode(' ', $row['unconfigured']));
            }
            if ($row['violations'] > 0 && ! $acceptCurrentGaps) {
                throw new ConfigurationException("{$row['violations']} current employees would fail \"{$row['label']}\" on their next change. Complete their data first, or accept that their next change needs it.");
            }
        }

        $companies = array_map('intval', array_keys((array) ($report['companies'] ?? [])));
        $settings['preflight'] = [
            'run_at' => ($run->finished_at ?? now())->toIso8601String(),
            'by' => $actor->getKey(),
            'run' => $run->ulid,
            'fingerprint' => EmploymentRequirements::fingerprint($settings),
            'safe_rules' => $rules,
            'companies' => $companies,
            // I.7: what each company's settings are, its own values applied: Block holds for it only while they stay so.
            'company_fingerprints' => $this->companyFingerprints($policy, $settings, $companies, $run->effective_on),
            'employees' => $report['employees'],
            'violations' => array_map(fn (array $r) => $r['violations'], $report['rules']),
            'accepted_gaps' => $acceptCurrentGaps,
        ];

        return DB::transaction(function () use ($policy, $settings, $reason, $from, $rules, $report, $acceptCurrentGaps, $run) {
            $this->policies->draft($policy, $settings, $reason);
            $version = $this->policies->publish($policy, $from ?? today(), $reason);
            $this->audit->record(AuditAction::Update, 'configuration', $policy, reason: $reason, effectiveDate: $version->effective_from,
                metadata: ['employment_requirements_block' => $rules, 'preflight_employees' => $report['employees'], 'accepted_gaps' => $acceptCurrentGaps,
                    'preflight_run' => $run->ulid, 'violations' => array_map(fn (array $r) => $r['violations'], $report['rules'])]);

            return $version;
        });
    }

    /**
     * I.7: end a company's own values of the policy the controlled way. When the settings the company will have block
     * anything, the company is checked with them first (here, or on the queue above the threshold: outcome pending):
     * - safe (or the gaps accepted): its values end and the check is recorded as the company's coverage, so its rules
     *   on Block keep blocking (outcome covered);
     * - not safe: nothing ends (outcome refused), and the run says why.
     * Without Block in what the company will have, the values simply end (outcome ended).
     *
     * @param  list<string>  $paths  the company's own values to end
     * @return array{outcome: string, message: string, run: ?EmploymentRequirementPreflight}
     */
    public function endCompanyValues(Policy $policy, int $companyId, array $paths, User $actor, string $reason, CarbonInterface|string|null $from = null, bool $acceptCurrentGaps = false): array
    {
        if ($policy->type !== EmploymentRequirements::TYPE) {
            throw new ConfigurationException('Only an employment data requirements policy has a pre-flight.');
        }
        if (trim($reason) === '' || $paths === []) {
            throw new ConfigurationException($paths === [] ? 'Choose the values to end.' : 'Give the reason.');
        }
        $from = Carbon::parse($from ?? today())->toDateString();
        $this->overrides->authorise(app(ConfigurationTypes::class)->for('policy'), $policy, $companyId, $from, $actor, requireApplies: false);
        $company = (string) (Company::query()->withoutGlobalScope(AccessScope::class)->whereKey($companyId)->value('name') ?? 'the company');
        $will = $this->settingsAfterEnding($policy, $companyId, $paths, $from);

        if (EmploymentRequirements::blockRules($will) === []) {
            $this->endPaths($policy, $companyId, $paths, $from, $actor, $reason);

            return ['outcome' => 'ended', 'run' => null, 'message' => "{$company} follows the shared values from ".Carbon::parse($from)->format('j M Y').'. Nothing it will have blocks, so no pre-flight was needed.'];
        }

        $run = $this->record($policy, $actor, EmploymentRequirementPreflight::COMPANY, $will, $companyId, ['paths' => array_values($paths), 'from' => $from], $acceptCurrentGaps, $reason);

        return match (true) {
            $run->isOpen() => ['outcome' => 'pending', 'run' => $run, 'message' => "The pre-flight of {$company} with the shared values runs in the background. Its own values end once it finds them safe; until then nothing changes."],
            $run->outcome === 'covered' => ['outcome' => 'covered', 'run' => $run, 'message' => "{$company} follows the shared values from ".Carbon::parse($from)->format('j M Y').'. The pre-flight covers it, so its rules on Block keep blocking.'],
            default => ['outcome' => $run->outcome ?? 'refused', 'run' => $run, 'message' => "Not ended: {$run->error}"],
        };
    }

    /**
     * Start a recorded pre-flight: in this request below the threshold, else on the queue. The same request while one is
     * open returns it (idempotent); an open one of the same policy and purpose (and company) for other settings is
     * superseded.
     *
     * @param  array<string, mixed>|null  $settings
     * @param  array{paths?: list<string>, from?: string}|null  $end  purpose company: what is ended once safe
     */
    public function record(Policy $policy, User $actor, string $purpose = EmploymentRequirementPreflight::REVIEW, ?array $settings = null, ?int $companyId = null,
        ?array $end = null, bool $acceptGaps = false, ?string $reason = null, ?bool $queue = null): EmploymentRequirementPreflight
    {
        if (! $actor->can('policy.view')) {
            throw new ConfigurationException('You need to be allowed to view policies to run their pre-flight.');
        }
        $settings ??= $this->currentSettings($policy, today());
        unset($settings['preflight']);
        $queue ??= $this->queues();
        $prints = [
            'fingerprint' => EmploymentRequirements::fingerprint($settings),
            'evidence_fingerprint' => self::evidenceFingerprint($settings),
            'applicability_fingerprint' => $this->applicabilityFingerprint($policy),
            'overrides_fingerprint' => $this->overridesFingerprint($policy),
        ];

        $run = DB::transaction(function () use ($policy, $actor, $purpose, $settings, $companyId, $end, $acceptGaps, $reason, $queue, $prints) {
            $open = EmploymentRequirementPreflight::query()->where('policy_id', $policy->id)->where('purpose', $purpose)
                ->where('company_id', $companyId)->whereIn('status', EmploymentRequirementPreflight::OPEN)->lockForUpdate()->get();
            // Only an open queued run is reused: an open in-request run belongs to a request that ended (or another one).
            $same = $open->first(fn (EmploymentRequirementPreflight $r) => $queue && $r->mode === 'queued'
                && $r->only(array_keys($prints)) === $prints && $r->effective_on->isToday() && $r->end_paths === $end && $r->accept_gaps === $acceptGaps);
            if ($same !== null) {
                return $same;   // already asked for: the same run, not a second one
            }
            foreach ($open as $older) {
                $older->update(['status' => EmploymentRequirementPreflight::SUPERSEDED, 'finished_at' => now(), 'error' => 'A newer pre-flight of this policy took its place.']);
            }
            $run = EmploymentRequirementPreflight::query()->create([
                'ulid' => (string) Str::ulid(), 'policy_id' => $policy->id, 'company_id' => $companyId, 'purpose' => $purpose,
                'mode' => $queue ? 'queued' : 'sync', 'status' => EmploymentRequirementPreflight::PENDING, 'settings' => $settings, 'end_paths' => $end,
                'accept_gaps' => $acceptGaps, 'effective_on' => today()->toDateString(), 'reason' => $reason !== null ? mb_substr($reason, 0, 1000) : null,
                'requested_by' => $actor->getKey(),
            ] + $prints);
            if ($queue) {
                $this->audit->record(AuditAction::Update, 'configuration', $policy, reason: $reason,
                    metadata: ['employment_requirements_preflight' => 'queued', 'run' => $run->ulid, 'purpose' => $purpose, 'company_id' => $companyId], actor: $actor);
                dispatch(new RunEmploymentRequirementsPreflight($run->id))->onQueue((string) config('peopleos.employment_requirements.preflight_queue', 'default'))->afterCommit();
            }

            return $run;
        });

        return $run->mode === 'sync' && $run->status === EmploymentRequirementPreflight::PENDING ? $this->execute($run) : $run->fresh();
    }

    /**
     * Run a recorded pre-flight (the job, or record() below the threshold). Claims it first, so two workers never run
     * one; a finished, superseded or cancelled run is left as it is (a retry does nothing). Between batches it stops when
     * the run was superseded or cancelled. At the end it is completed only when nothing it depends on changed meanwhile.
     */
    public function execute(EmploymentRequirementPreflight $run, bool $final = true): EmploymentRequirementPreflight
    {
        // Pending, or running past the job's time limit (its worker died): a run another worker is reading is not claimed.
        $claimed = EmploymentRequirementPreflight::query()->whereKey($run->id)
            ->where(fn ($q) => $q->where('status', EmploymentRequirementPreflight::PENDING)
                ->orWhere(fn ($q) => $q->where('status', EmploymentRequirementPreflight::RUNNING)->where('started_at', '<', now()->subSeconds(self::LEASE_SECONDS))))
            ->update(['status' => EmploymentRequirementPreflight::RUNNING, 'started_at' => now(), 'processed' => 0, 'error' => null]);
        $run = $run->fresh();
        if ($claimed === 0 || $run === null) {
            return $run;
        }
        $policy = Policy::query()->withoutGlobalScope(AccessScope::class)->find($run->policy_id);
        if ($policy === null) {
            return $this->finish($run, EmploymentRequirementPreflight::CANCELLED, error: 'The policy was removed.');
        }

        try {
            $report = $this->run($policy, (array) $run->settings, $run->effective_on, $run->company_id,
                $run->company_id === null ? [] : [(int) $run->company_id => (array) $run->settings],
                function (int $read, int $total) use ($run) {
                    $status = EmploymentRequirementPreflight::query()->whereKey($run->id)->value('status');
                    if ($status !== EmploymentRequirementPreflight::RUNNING) {
                        throw new PreflightStopped((string) $status);
                    }
                    EmploymentRequirementPreflight::query()->whereKey($run->id)->update(['processed' => $read, 'total' => $total]);
                });
        } catch (PreflightStopped) {
            return $run->fresh();
        } catch (Throwable $e) {
            if (! $final) {
                $run->update(['status' => EmploymentRequirementPreflight::PENDING, 'error' => mb_substr('Attempt failed, retrying: '.$e->getMessage(), 0, 1000)]);

                throw $e;
            }

            return $this->finish($run, EmploymentRequirementPreflight::FAILED, error: 'The pre-flight stopped: '.$e->getMessage());
        }

        // Whatever it depends on must not have changed while it ran.
        if ($run->applicability_fingerprint !== $this->applicabilityFingerprint($policy) || $run->overrides_fingerprint !== $this->overridesFingerprint($policy)
            || ($run->purpose === EmploymentRequirementPreflight::REVIEW && $run->fingerprint !== EmploymentRequirements::fingerprint($this->currentSettings($policy, today())))) {
            return $this->finish($run, EmploymentRequirementPreflight::SUPERSEDED, $report, 'The policy, the companies it reaches or their own values changed while it ran: run it again.');
        }
        $run = $this->finish($run, EmploymentRequirementPreflight::COMPLETED, $report);

        // Cancelled or superseded meanwhile: finish() left it so, and nothing is ended.
        return $run->status === EmploymentRequirementPreflight::COMPLETED && $run->purpose === EmploymentRequirementPreflight::COMPANY ? $this->completeCompany($policy, $run) : $run;
    }

    /** The latest run of the policy for the page (a company's coverage runs are listed apart). */
    public function latestRun(Policy $policy): ?EmploymentRequirementPreflight
    {
        return EmploymentRequirementPreflight::query()->where('policy_id', $policy->id)->whereNull('company_id')->latest('id')->first();
    }

    /** A completed, current and recent run that vouches for these settings (Warn or Block read alike), or null. */
    public function usableRun(Policy $policy, array $settings): ?EmploymentRequirementPreflight
    {
        $since = now()->subHours(min($this->evidenceHours(), EmploymentRequirements::PREFLIGHT_DAYS * 24));

        return EmploymentRequirementPreflight::query()->where('policy_id', $policy->id)->whereNull('company_id')
            ->whereIn('purpose', [EmploymentRequirementPreflight::REVIEW, EmploymentRequirementPreflight::SWITCH])
            ->where('status', EmploymentRequirementPreflight::COMPLETED)->where('finished_at', '>=', $since)
            ->where('evidence_fingerprint', self::evidenceFingerprint($settings))
            ->where('applicability_fingerprint', $this->applicabilityFingerprint($policy))
            ->where('overrides_fingerprint', $this->overridesFingerprint($policy))
            ->latest('id')->first();
    }

    /** The rules a completed coverage run lets block for a company whose settings have this fingerprint, or null. */
    public static function companyCoverage(int $policyId, int $companyId, string $fingerprint): ?array
    {
        $run = EmploymentRequirementPreflight::query()->where('policy_id', $policyId)->where('company_id', $companyId)
            ->where('purpose', EmploymentRequirementPreflight::COMPANY)->where('status', EmploymentRequirementPreflight::COMPLETED)
            ->where('outcome', 'covered')->latest('id')->first();

        return $run !== null && ($run->report['company_fingerprints'][$companyId] ?? null) === $fingerprint ? (array) ($run->report['safe_rules'] ?? []) : null;
    }

    public function queues(): bool
    {
        return Employee::query()->withoutGlobalScope(AccessScope::class)->employed()->count() > $this->threshold();
    }

    public function threshold(): int
    {
        return max(0, (int) config('peopleos.employment_requirements.preflight_queue_threshold', 30000));
    }

    /** The settings with every rule that is on read as Warn: what a pre-flight counts does not depend on Warn or Block. */
    public static function evidenceFingerprint(array $settings): string
    {
        return EmploymentRequirements::fingerprint(EmploymentRequirements::forCopy($settings));
    }

    /** Who the policy reaches: its active assignment rules, the companies it applies to, and the companies there are. */
    public function applicabilityFingerprint(Policy $policy): string
    {
        $rules = $policy->assignmentRules()->where('status', 'active')->orderBy('id')->get(['id', 'priority', 'conditions'])
            ->map(fn ($r) => [$r->id, (int) $r->priority, $r->conditions])->all();

        return hash('sha256', json_encode([$rules, $this->applicability->reach($policy), Company::query()->withoutGlobalScope(AccessScope::class)->orderBy('id')->pluck('id')->all()]));
    }

    /** The companies' own values of the policy (configuration overrides), as they stand. */
    public function overridesFingerprint(Policy $policy): string
    {
        return hash('sha256', json_encode(ConfigurationOverride::query()->where('configuration_type', 'policy')->where('configuration_id', $policy->id)
            ->where('status', ConfigurationOverride::ACTIVE)->orderBy('id')->get(['id', 'company_id', 'field', 'value', 'effective_from', 'effective_to', 'version'])
            ->map(fn ($o) => [$o->id, $o->company_id, $o->field, $o->value, $o->effective_from?->toDateString(), $o->effective_to?->toDateString(), $o->version])->all()));
    }

    /** @return array<string, mixed> the version in force (else the latest), without its pre-flight record */
    private function currentSettings(Policy $policy, CarbonInterface $on): array
    {
        $settings = (array) ($policy->versionEffectiveOn($on)?->settings ?? $policy->versions()->first()?->settings ?? []);
        unset($settings['preflight']);

        return $settings;
    }

    /**
     * @return array<int, string> company => the fingerprint of its settings (its own values applied) on the date the
     *                            pre-flight read them: own values starting later make other settings, which then warn until checked
     */
    private function companyFingerprints(Policy $policy, array $settings, array $companies, CarbonInterface $on): array
    {
        $out = [];
        foreach ($companies as $id) {
            $out[(int) $id] = EmploymentRequirements::fingerprint($this->overrides->hasAny('policy', (int) $policy->id)
                ? $this->overrides->applyTo($settings, 'policy', (int) $policy->id, (int) $id, $on) : $settings);
        }

        return $out;
    }

    /** The settings the company will have once these own values end (its other own values still applied). */
    private function settingsAfterEnding(Policy $policy, int $companyId, array $paths, string $from): array
    {
        $settings = $this->currentSettings($policy, Carbon::parse($from));
        $own = $this->overrides->valuesFor('policy', (int) $policy->id, $companyId, $from);
        foreach ($own as $path => $value) {
            if (! in_array($path, $paths, true)) {
                $settings = SettingsPath::set($settings, $path, $value);
            }
        }

        return $settings;
    }

    /** A completed company run: end the values and record the coverage when it is safe, else refuse. */
    private function completeCompany(Policy $policy, EmploymentRequirementPreflight $run): EmploymentRequirementPreflight
    {
        $report = (array) $run->report;
        $blocks = EmploymentRequirements::blockRules((array) $run->settings);
        $problems = [];
        foreach ($blocks as $rule) {
            $row = $report['rules'][$rule] ?? null;
            if ($row !== null && $row['unconfigured'] !== []) {
                $problems[] = 'not safe to block "'.$row['label'].'": '.implode(' ', $row['unconfigured']);
            } elseif ($row !== null && $row['violations'] > 0 && ! $run->accept_gaps) {
                $problems[] = "{$row['violations']} current employees would fail \"{$row['label']}\" on their next change";
            }
        }
        if ($problems !== []) {
            $run->update(['outcome' => 'refused', 'error' => ucfirst(implode('; ', $problems)).'. Complete the data, or accept the gaps, then try again.']);

            return $run->fresh();
        }
        $actor = User::query()->find($run->requested_by);
        try {
            if ($actor === null) {
                throw new ConfigurationException('The person who asked for it no longer exists.');
            }
            $end = (array) $run->end_paths;
            // Asked for a date that has passed while it waited: from today, the earliest a value can end (never earlier).
            $from = max((string) ($end['from'] ?? today()->toDateString()), today()->toDateString());
            $this->endPaths($policy, (int) $run->company_id, (array) ($end['paths'] ?? []), $from, $actor, (string) $run->reason);
        } catch (Throwable $e) {
            $run->update(['outcome' => 'refused', 'error' => 'The values could not be ended: '.$e->getMessage()]);

            return $run->fresh();
        }
        $run->update(['outcome' => 'covered', 'report' => $report + ['safe_rules' => $blocks]]);
        $this->audit->record(AuditAction::Update, 'configuration', $policy, reason: $run->reason,
            metadata: ['employment_requirements_preflight' => 'company_covered', 'run' => $run->ulid, 'company_id' => $run->company_id, 'safe_rules' => $blocks, 'accepted_gaps' => $run->accept_gaps], actor: $actor);

        return $run->fresh();
    }

    private function endPaths(Policy $policy, int $companyId, array $paths, string $from, User $actor, string $reason): void
    {
        $this->overrides->together(function () use ($policy, $companyId, $paths, $from, $actor, $reason) {
            foreach ($paths as $path) {
                $this->overrides->end($policy, $companyId, (string) $path, $from, $actor, $reason);
            }
        });
    }

    /** Ends a run that is still running (a cancel or a newer run that took over meanwhile is kept, not overwritten). */
    private function finish(EmploymentRequirementPreflight $run, string $status, ?array $report = null, ?string $error = null): EmploymentRequirementPreflight
    {
        $fresh = $run->fresh();
        $updated = EmploymentRequirementPreflight::query()->whereKey($run->id)->where('status', EmploymentRequirementPreflight::RUNNING)
            ->update(['status' => $status, 'report' => $report !== null ? json_encode($report) : $fresh?->getRawOriginal('report'), 'error' => $error, 'finished_at' => now()]
                + ($report !== null ? ['processed' => max((int) $fresh?->processed, (int) ($fresh?->total ?? 0))] : []));
        if ($updated === 0) {
            return $run->fresh();
        }
        if ($run->mode === 'queued' || $run->purpose !== EmploymentRequirementPreflight::REVIEW) {
            $this->audit->record(AuditAction::Update, 'configuration', $run->policy()->withoutGlobalScope(AccessScope::class)->first() ?? $run,
                reason: $run->reason, metadata: ['employment_requirements_preflight' => $status, 'run' => $run->ulid, 'purpose' => $run->purpose, 'company_id' => $run->company_id, 'error' => $error],
                actor: User::query()->find($run->requested_by));
        }

        return $run->fresh();
    }

    private function evidenceHours(): int
    {
        return max(1, (int) config('peopleos.employment_requirements.preflight_evidence_hours', 24));
    }
}
