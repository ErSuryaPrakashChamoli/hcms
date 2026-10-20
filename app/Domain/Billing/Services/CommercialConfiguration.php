<?php

namespace App\Domain\Billing\Services;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Billing\Enums\ApprovalAction;
use App\Domain\Billing\Enums\ConfigurationKey;
use App\Domain\Billing\Models\ConfigurationVersion;
use App\Domain\Billing\Models\FinancialApproval;
use App\Domain\Identity\Models\User;
use App\Support\Commercial\OperatorChange;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * SaaS.7 configuration: the values behind Markedge's commercial policy and the statutory parameters billing reads,
 * as approved, effective-dated versions. Reading: the approved version in force on the day (latest start, then
 * version; an expired version never falls back to an older one), else, for a company policy only, the shipped
 * default in config/peopleos.php; a statutory parameter has no default. Changing: an operator proposes a version
 * from today or later with a reason (and, for a statutory value, its source); another operator approves it on the
 * Approvals page (maker-checker); it never changes afterwards. Statutory values shipped in the dataset are loaded
 * pending and activated by a second operator together with the dataset's tax rules.
 */
final class CommercialConfiguration
{
    public function __construct(private readonly FinancialApprovals $approvals, private readonly BillingAudit $audit) {}

    public function value(ConfigurationKey $key, string $scope = '', ?string $day = null): mixed
    {
        return $this->resolve($key, $scope, $day)['value'];
    }

    /** A value billing cannot work without: refused, never assumed, when nothing is configured for the day (e.g. a policy version expired with no successor). */
    public function required(ConfigurationKey $key, string $scope = '', ?string $day = null): mixed
    {
        return $this->value($key, $scope, $day)
            ?? throw new RuntimeException("[CONFIGURATION_MISSING] {$key->label()} is not configured for ".($day ?? now()->toDateString()).': approve a version on Commercial policies.');
    }

    /** @return array{value: mixed, source: string, version: ?ConfigurationVersion} source: approved | shipped_default | not_configured */
    public function resolve(ConfigurationKey $key, string $scope = '', ?string $day = null): array
    {
        $day ??= now()->toDateString();
        $latest = ConfigurationVersion::query()->where(['key' => $key->value, 'scope' => $this->scope($key, $scope), 'status' => ConfigurationVersion::APPROVED])
            ->whereDate('effective_from', '<=', $day)->orderByDesc('effective_from')->orderByDesc('version')->first();
        if ($latest !== null && ($latest->effective_to === null || $latest->effective_to->toDateString() >= $day)) {
            return ['value' => $latest->typedValue(), 'source' => 'approved', 'version' => $latest];
        }
        if ($latest === null && $key->domain() === ConfigurationKey::POLICY && ($default = ((array) config('peopleos.commercial.policy_defaults', []))[$key->value] ?? null) !== null) {
            return ['value' => $key->validate($default), 'source' => 'shipped_default', 'version' => null];
        }

        return ['value' => null, 'source' => 'not_configured', 'version' => null];
    }

    /**
     * Proposes a new version (maker). Executed when another operator approves it.
     *
     * @param  array{source?: ?string, source_reference?: ?string, source_url?: ?string, source_date?: ?string}  $source
     */
    public function propose(ConfigurationKey $key, string $scope, mixed $value, string $effectiveFrom, ?string $effectiveTo, string $reason, User $maker, array $source = []): FinancialApproval
    {
        OperatorChange::assert($maker, $reason, 'commercial configuration');
        $scope = $this->scope($key, $scope);
        $value = $key->validate($value);
        [$from, $to] = [$this->day($effectiveFrom), blank($effectiveTo) ? null : $this->day((string) $effectiveTo)];
        if ($from < now()->toDateString()) {
            throw new RuntimeException('A configuration change takes effect today or later: past days keep the value they had.');
        }
        if ($to !== null && $to < $from) {
            throw new RuntimeException('A version ends on or after the day it starts.');
        }
        if ($key->domain() === ConfigurationKey::STATUTORY && (blank($source['source'] ?? null) || blank($source['source_reference'] ?? null))) {
            throw new RuntimeException('A statutory value names its source and legal reference.');
        }
        $current = $this->resolve($key, $scope, $from);

        return DB::transaction(function () use ($key, $scope, $value, $from, $to, $reason, $maker, $source, $current) {
            $version = $this->create($key, $scope, $value, $from, $to, $reason, $maker, $source + ['origin' => 'operator']);
            $approval = $this->approvals->request(ApprovalAction::ConfigurationChange, $version, null, [
                'key' => $key->value, 'label' => $key->label(), 'scope' => $scope, 'value' => $key->describe($value), 'effective_from' => $from, 'effective_to' => $to,
                'domain' => $key->domain(), 'source' => $version->source, 'source_reference' => $version->source_reference,
            ], ['value' => $key->describe($current['value']), 'source' => $current['source']], ['value' => $key->describe($value), 'from' => $from],
                "{$key->value}:{$scope}:v{$version->version}", $reason, $maker);
            $version->forceFill(['approval_id' => $approval->id])->save();

            return $approval;
        }, 3);   // MySQL: two proposals of one key can deadlock on the version's gap lock; the retry numbers the loser next (race 12)
    }

    /** Executes an approved change (called by the approval desk, inside its transaction). */
    public function executeChange(FinancialApproval $approval): ConfigurationVersion
    {
        $approval = $this->approvals->claim($approval, ApprovalAction::ConfigurationChange);
        $version = ConfigurationVersion::query()->lockForUpdate()->findOrFail($approval->subject_id);
        if ($version->status !== ConfigurationVersion::PENDING) {
            throw new RuntimeException("This version is already {$version->status}.");
        }
        if ($version->effective_from->toDateString() < now()->toDateString()) {
            throw new RuntimeException('This change was to start on '.$version->effective_from->toDateString().', which has passed: past days keep their value. Reject it and propose it again from today or later.');
        }
        $checker = User::query()->findOrFail($approval->checker_id);
        $this->decide($version, ConfigurationVersion::APPROVED, $checker, (string) $approval->checker_reason, $approval->reference);
        $this->approvals->executed($approval, "{$version->key} v{$version->version} from {$version->effective_from->toDateString()}");

        return $version;
    }

    /** Keeps a version in step when its approval request is rejected or withdrawn. */
    public function close(FinancialApproval $approval): void
    {
        $version = ConfigurationVersion::query()->find($approval->subject_id);
        if ($version !== null && $version->status === ConfigurationVersion::PENDING && in_array($approval->status->value, [ConfigurationVersion::REJECTED, ConfigurationVersion::WITHDRAWN], true)) {
            $version->forceFill(['status' => $approval->status->value])->save();
        }
    }

    /**
     * Loads a statutory parameter from the shipped dataset, pending (its loader is the maker).
     *
     * @param  array<string, mixed>  $entry
     */
    public function loadFromDataset(string $datasetVersion, array $entry, User $maker): ConfigurationVersion
    {
        $key = ConfigurationKey::from((string) $entry['key']);
        $scope = $this->scope($key, (string) ($entry['scope'] ?? ''));
        $existing = ConfigurationVersion::query()->where(['key' => $key->value, 'scope' => $scope, 'dataset_version' => $datasetVersion])->first();

        return $existing ?? $this->create($key, $scope, $key->validate($entry['value']), $this->day((string) $entry['effective_from']), null,
            mb_substr("Statutory dataset {$datasetVersion}: ".($entry['summary'] ?? $key->label()), 0, 500), $maker,
            ['source' => $entry['source'] ?? null, 'source_reference' => $entry['source_reference'] ?? null, 'source_url' => $entry['source_url'] ?? null,
                'source_date' => $entry['source_date'] ?? null, 'origin' => 'statutory_dataset', 'dataset_version' => $datasetVersion]);
    }

    /** Activates a dataset-loaded statutory version (the second operator verifies the dataset). */
    public function approveFromDataset(ConfigurationVersion $version, User $checker, string $reference): ConfigurationVersion
    {
        $locked = ConfigurationVersion::query()->lockForUpdate()->findOrFail($version->id);
        if ($locked->status !== ConfigurationVersion::PENDING || $locked->origin !== 'statutory_dataset') {
            return $locked;
        }
        if ((int) $locked->created_by === (int) $checker->id) {
            throw new RuntimeException('The operator who loaded the statutory dataset cannot verify it.');
        }

        return $this->decide($locked, ConfigurationVersion::APPROVED, $checker, $reference, null);
    }

    /** What a version means on $day: PENDING_APPROVAL, SCHEDULED, CURRENT, SUPERSEDED, EXPIRED, REJECTED, WITHDRAWN. */
    public function state(ConfigurationVersion $version, ?string $day = null): string
    {
        $day ??= now()->toDateString();
        if ($version->status !== ConfigurationVersion::APPROVED) {
            return $version->status === ConfigurationVersion::PENDING ? 'PENDING_APPROVAL' : strtoupper($version->status);
        }
        if ($version->effective_from->toDateString() > $day) {
            return 'SCHEDULED';
        }
        $key = $version->configurationKey();
        $inForce = $key === null ? null : $this->resolve($key, $version->scope, $day)['version'];

        return match (true) {
            $inForce?->id === $version->id => 'CURRENT',
            $version->effective_to !== null && $version->effective_to->toDateString() < $day && $this->latestStarted($version, $day)?->id === $version->id => 'EXPIRED',
            default => 'SUPERSEDED',
        };
    }

    /** @return Collection<int, ConfigurationVersion> */
    public function history(ConfigurationKey $key, string $scope = ''): Collection
    {
        return ConfigurationVersion::query()->where(['key' => $key->value, 'scope' => $this->scope($key, $scope)])->orderByDesc('version')->get();
    }

    /** Scopes with any version of a scoped key (e.g. the countries with an invoice-number rule). @return list<string> */
    public function scopes(ConfigurationKey $key): array
    {
        return ConfigurationVersion::query()->where('key', $key->value)->distinct()->orderBy('scope')->pluck('scope')->all();
    }

    private function latestStarted(ConfigurationVersion $version, string $day): ?ConfigurationVersion
    {
        return ConfigurationVersion::query()->where(['key' => $version->key, 'scope' => $version->scope, 'status' => ConfigurationVersion::APPROVED])
            ->whereDate('effective_from', '<=', $day)->orderByDesc('effective_from')->orderByDesc('version')->first();
    }

    /** @param  array<string, mixed>  $attributes */
    private function create(ConfigurationKey $key, string $scope, mixed $value, string $from, ?string $to, string $reason, User $maker, array $attributes): ConfigurationVersion
    {
        $text = fn (string $k, int $max) => blank($attributes[$k] ?? null) ? null : mb_substr(trim((string) $attributes[$k]), 0, $max);
        if (! blank($attributes['source_url'] ?? null) && preg_match('~^https://[^\s<>"]+$~', trim((string) $attributes['source_url'])) !== 1) {
            throw new RuntimeException('A source URL is an https:// address.');
        }

        return DB::transaction(function () use ($key, $scope, $value, $from, $to, $reason, $maker, $attributes, $text) {
            $next = (int) ConfigurationVersion::query()->where(['domain' => $key->domain(), 'key' => $key->value, 'scope' => $scope])->lockForUpdate()->max('version') + 1;
            $version = ConfigurationVersion::query()->create(['domain' => $key->domain(), 'key' => $key->value, 'scope' => $scope, 'value' => ['v' => $value],
                'effective_from' => $from, 'effective_to' => $to, 'version' => $next, 'status' => ConfigurationVersion::PENDING, 'reason' => trim($reason),
                'source' => $text('source', 150), 'source_reference' => $text('source_reference', 500), 'source_url' => $text('source_url', 500),
                'source_date' => blank($attributes['source_date'] ?? null) ? null : $this->day((string) $attributes['source_date']),
                'origin' => $attributes['origin'] ?? 'operator', 'dataset_version' => $attributes['dataset_version'] ?? null, 'created_by' => $maker->id]);
            $this->audit->platform(AuditAction::ConfigurationProposed, 'billing', $version, "{$key->label()}".($scope !== '' ? " ({$scope})" : '')." v{$next}",
                [['field' => $key->value, 'before' => null, 'after' => $key->describe($value)]], trim($reason), $maker,
                ['key' => $key->value, 'scope' => $scope, 'version' => $next, 'effective_from' => $from, 'effective_to' => $to, 'domain' => $key->domain(),
                    'source' => $version->source, 'source_reference' => $version->source_reference, 'origin' => $version->origin], $from);

            return $version;
        });
    }

    private function decide(ConfigurationVersion $version, string $status, User $checker, string $reason, ?string $approvalReference): ConfigurationVersion
    {
        $key = $version->configurationKey();
        $before = $key === null ? null : $this->resolve($key, $version->scope, $version->effective_from->toDateString());
        $version->forceFill(['status' => $status, 'approved_by' => $checker->id, 'approved_at' => now()])->save();
        $this->audit->platform(AuditAction::ConfigurationApproved, 'billing', $version, ($key?->label() ?? $version->key).($version->scope !== '' ? " ({$version->scope})" : '')." v{$version->version}",
            [['field' => $version->key, 'before' => $key?->describe($before['value'] ?? null), 'after' => $key?->describe($version->typedValue())]], $reason, $checker,
            ['key' => $version->key, 'scope' => $version->scope, 'version' => $version->version, 'maker_id' => $version->created_by, 'checker_id' => $checker->id,
                'approval' => $approvalReference, 'source' => $version->source, 'source_reference' => $version->source_reference,
                'correlation_id' => "{$version->key}:{$version->scope}:v{$version->version}"], $version->effective_from->toDateString());

        return $version;
    }

    private function scope(ConfigurationKey $key, string $scope): string
    {
        $scope = strtoupper(trim($scope));
        if ($key->scoped() && preg_match('/^[A-Z]{2}(-[A-Z0-9]{1,3})?$/', $scope) !== 1) {
            throw new RuntimeException("{$key->label()} is set per country: give its ISO code.");
        }

        return $key->scoped() ? $scope : '';
    }

    private function day(string $day): string
    {
        try {
            return Carbon::createFromFormat('!Y-m-d', $day)->toDateString();
        } catch (\Throwable) {
            throw new RuntimeException("{$day} is not a date (YYYY-MM-DD).");
        }
    }
}
