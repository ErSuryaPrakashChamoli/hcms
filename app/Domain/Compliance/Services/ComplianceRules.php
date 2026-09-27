<?php

namespace App\Domain\Compliance\Services;

use App\Domain\Compliance\Models\ComplianceRule;
use App\Domain\Payroll\Models\PayrollRun;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Resolves the statutory rule version effective on a date and publishes the platform packs
 * (Phase 5 Parts E and F).
 *
 * Resolution picks the newest version effective on the date that is not SUPERSEDED or REJECTED. It
 * never falls back to an older version because the newest one is unverified: that version is
 * returned and the calculation flags it (blocking when enforcement is on).
 */
final class ComplianceRules
{
    /** @var array<string, ComplianceRule|null> */
    private array $cache = [];

    public function __construct(private readonly RuleVerifications $verifications) {}

    public function resolve(string $code, CarbonInterface|string|null $on = null, ?string $state = null, string $jurisdiction = 'IN'): ?ComplianceRule
    {
        $day = Carbon::parse($on ?? now())->toDateString();
        $key = "{$jurisdiction}|{$code}|{$state}|{$day}";

        return $this->cache[$key] ??= ComplianceRule::query()
            ->where('jurisdiction', $jurisdiction)
            ->where('code', $code)
            ->when($state === null, fn ($q) => $q->whereNull('state'), fn ($q) => $q->where('state', $state))
            ->where('status', 'active')
            ->whereNotIn('verification_status', [ComplianceRule::SUPERSEDED, ComplianceRule::REJECTED])
            ->effectiveOn($day)
            ->orderByDesc('version')
            ->first();
    }

    public function forget(): void
    {
        $this->cache = [];
    }

    public static function enforced(): bool
    {
        return (bool) config('peopleos.payroll.enforce_verified_rules', true);
    }

    /**
     * Publish new versions from the packs. Insert-only: an existing version whose pack definition
     * changed is refused (publish a new version). Packs can attach official evidence, which moves a
     * new draft to REVIEW; a pack can never mark a rule VERIFIED.
     */
    public function sync(): Collection
    {
        $synced = collect();

        foreach (glob(database_path('data/compliance/*.php')) ?: [] as $file) {
            $jurisdiction = strtoupper(pathinfo($file, PATHINFO_FILENAME));

            foreach (require $file as $definition) {
                $state = $definition['state'] ?? null;
                $checksum = ComplianceRule::checksumFor($jurisdiction, $definition['code'], $state, (int) $definition['version'], $definition['effective_from'], $definition['effective_to'] ?? null, $definition['parameters']);
                $rule = ComplianceRule::query()->where('jurisdiction', $jurisdiction)->where('code', $definition['code'])
                    ->when($state === null, fn ($q) => $q->whereNull('state'), fn ($q) => $q->where('state', $state))
                    ->where('version', $definition['version'])->first();

                if ($rule !== null && $rule->checksum !== null && ! hash_equals($rule->checksum, $checksum)) {
                    throw new RuntimeException("The pack changes {$rule->label()} ({$jurisdiction}".($state ? "/{$state}" : '').'), which is immutable. Publish it as version '.($rule->version + 1).' instead.');
                }

                if ($rule === null) {
                    $rule = ComplianceRule::query()->create([
                        'jurisdiction' => $jurisdiction,
                        'code' => $definition['code'],
                        'state' => $state,
                        'authority' => $definition['authority'] ?? null,
                        'name' => $definition['name'],
                        'version' => $definition['version'],
                        'effective_from' => $definition['effective_from'],
                        'effective_to' => $definition['effective_to'] ?? null,
                        'parameters' => $definition['parameters'],
                        'source' => $definition['source'] ?? null,
                        'status' => 'active',
                        'verification_status' => ComplianceRule::DRAFT,
                        'verification_notes' => $definition['notes'] ?? null,
                    ]);
                    $this->verifications->recordCreated($rule, actorLabel: 'pack:'.basename($file));
                }

                if (isset($definition['evidence']) && $rule->verification_status === ComplianceRule::DRAFT) {
                    $this->verifications->submit($rule, $definition['evidence'], actorLabel: 'pack:'.basename($file));
                }

                $synced->push($rule->refresh());
            }
        }

        $this->forget();

        return $synced;
    }

    /** Active rule versions of a jurisdiction that are not VERIFIED (dashboard / readiness). */
    public function unverified(string $jurisdiction = 'IN'): Collection
    {
        return ComplianceRule::query()->where('jurisdiction', $jurisdiction)->where('status', 'active')
            ->whereNotIn('verification_status', [ComplianceRule::VERIFIED, ComplianceRule::SUPERSEDED, ComplianceRule::REJECTED])
            ->orderBy('code')->get();
    }

    /**
     * Part F gate for one run: when enforcement is on, every rule version the run used must still be
     * VERIFIED and intact (its checksum unchanged since calculation). Missing and unverified rules
     * were already raised as blocking exceptions by the statutory engine.
     *
     * @return list<string> problems (empty = safe)
     */
    public function problemsFor(PayrollRun $run): array
    {
        if (! self::enforced()) {
            return [];
        }

        $problems = [];
        $used = (array) $run->rule_versions;
        $rules = ComplianceRule::query()->whereIn('id', array_keys($used))->get()->keyBy('id');

        foreach ($used as $id => $ref) {
            $rule = $rules->get($id);
            $label = ($ref['rule_code'] ?? 'rule').' v'.($ref['rule_version'] ?? '?').(isset($ref['state']) && $ref['state'] ? " ({$ref['state']})" : '');

            if ($rule === null) {
                $problems[] = "{$label} no longer exists.";
            } elseif (! $rule->isVerified()) {
                $problems[] = "{$label} is {$rule->verification_status}, not verified against an official source.";
            } elseif (! $rule->checksumIntact() || (isset($ref['rule_checksum']) && ! hash_equals((string) $ref['rule_checksum'], (string) $rule->checksum))) {
                $problems[] = "{$label} does not match the version the run was calculated with.";
            }
        }

        return $problems;
    }

    public function assertRunVerified(PayrollRun $run): void
    {
        $problems = $this->problemsFor($run);

        if ($problems !== []) {
            throw new RuntimeException('Statutory rules are not verified for production finalization: '.implode(' ', $problems).' Verify them (compliance) before finalizing payroll.');
        }
    }
}
