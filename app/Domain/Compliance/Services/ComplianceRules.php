<?php

namespace App\Domain\Compliance\Services;

use App\Domain\Compliance\Models\ComplianceRule;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/** Resolves the statutory rule version effective on a date; syncs the packs into the table. */
final class ComplianceRules
{
    /** @var array<string, ComplianceRule|null> */
    private array $cache = [];

    public function resolve(string $code, CarbonInterface|string|null $on = null, ?string $state = null, string $jurisdiction = 'IN'): ?ComplianceRule
    {
        $day = Carbon::parse($on ?? now())->toDateString();
        $key = "{$jurisdiction}|{$code}|{$state}|{$day}";

        return $this->cache[$key] ??= ComplianceRule::query()
            ->where('jurisdiction', $jurisdiction)
            ->where('code', $code)
            ->when($state === null, fn ($q) => $q->whereNull('state'), fn ($q) => $q->where('state', $state))
            ->where('status', 'active')
            ->effectiveOn($day)
            ->orderByDesc('version')
            ->first();
    }

    public function forget(): void
    {
        $this->cache = [];
    }

    /** Insert new versions from the packs; existing (code, state, version) rows are updated in place. */
    public function sync(): Collection
    {
        $synced = collect();

        foreach (glob(database_path('data/compliance/*.php')) ?: [] as $file) {
            $jurisdiction = strtoupper(pathinfo($file, PATHINFO_FILENAME));

            foreach (require $file as $definition) {
                $rule = ComplianceRule::query()->updateOrCreate(
                    ['jurisdiction' => $jurisdiction, 'code' => $definition['code'], 'state' => $definition['state'] ?? null, 'version' => $definition['version']],
                    ['name' => $definition['name'], 'effective_from' => $definition['effective_from'], 'effective_to' => $definition['effective_to'] ?? null, 'parameters' => $definition['parameters'], 'source' => $definition['source'] ?? null, 'status' => $definition['status'] ?? 'active', 'verification_status' => $definition['verification_status'] ?? 'illustrative', 'verified_at' => $definition['verified_at'] ?? null],
                );
                $synced->push($rule);
            }
        }

        $this->forget();

        return $synced;
    }

    /** Active rules for a jurisdiction that have not been verified against official sources. */
    public function unverified(string $jurisdiction = 'IN'): Collection
    {
        return ComplianceRule::query()->where('jurisdiction', $jurisdiction)->where('status', 'active')->where('verification_status', '!=', 'verified')->orderBy('code')->get();
    }

    /**
     * Statutory safety (Phase 0.2): when enforcement is on (production by default), payroll may
     * not be finalized on illustrative rules.
     */
    public function assertProductionSafe(string $jurisdiction = 'IN'): void
    {
        if (! config('peopleos.compliance.enforce_verified_rules')) {
            return;
        }

        $unverified = $this->unverified($jurisdiction);

        if ($unverified->isNotEmpty()) {
            throw new \RuntimeException('Statutory rules for '.$jurisdiction.' are illustrative and not verified against official sources: '.$unverified->map(fn (ComplianceRule $r) => $r->label())->unique()->implode(', ').'. Verify them (compliance phase) before finalizing payroll.');
        }
    }
}
