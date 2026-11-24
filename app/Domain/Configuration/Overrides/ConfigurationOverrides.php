<?php

namespace App\Domain\Configuration\Overrides;

use App\Domain\Attendance\Services\AttendanceMonthCloses;
use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Configuration\Applicability\ApplicabilityRefusedException;
use App\Domain\Configuration\Applicability\ConfigurationType;
use App\Domain\Configuration\Applicability\ConfigurationTypes;
use App\Domain\Configuration\Applicability\EntityApplicability;
use App\Domain\Configuration\Exceptions\ConfigurationException;
use App\Domain\Configuration\Models\ConfigurationOverride;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Organisation\Models\Company;
use App\Domain\Payroll\Models\PayrollPeriod;
use App\Support\Tenancy\TenantContext;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * Entity-specific overrides (docs/architecture/configuration-applicability.md): a company keeps using a shared record
 * but gives some of its fields its own values, from a date, without changing the record for anyone else.
 *
 * Precedence on a date, for a company: its override in force > the shared record's value (the version in force, for
 * versioned types) > the system default of the field. Overrides are effective-dated and versioned per field; a newer one
 * ends the older from its start, ending one restores the shared value, and nothing is ever deleted. Changes to the shared
 * record never touch overrides: an overridden field keeps the company's value until the override ends.
 *
 * Overrides are applied where the type is read for an employee (PolicyResolver for policies, ShiftResolver for shifts),
 * so every consumer of the type sees them without knowing about them.
 */
final class ConfigurationOverrides
{
    public const PERMISSION = 'configuration.override';

    /** @var array<string, array<int, list<array<string, mixed>>>> process-wide memo: tenant:type => id => rows */
    private static array $memo = [];

    public function __construct(
        private readonly ConfigurationTypes $types,
        private readonly EntityApplicability $applicability,
        private readonly AuditRecorder $audit,
        private readonly TenantContext $tenants,
    ) {}

    public static function flushMemo(): void
    {
        self::$memo = [];
    }

    /** @return array<string, OverrideField> path => field */
    public function fields(Model $record): array
    {
        return $this->provider($this->types->forModelOrFail($record))?->fields($record) ?? [];
    }

    public function supports(Model $record): bool
    {
        return ($type = $this->types->forModel($record)) !== null && $type->supportsOverrides();
    }

    /**
     * The company's own values in force on a date, path => value (nothing when the company has none).
     *
     * @return array<string, mixed>
     */
    public function valuesFor(string $typeKey, int $id, int $companyId, CarbonInterface|string|null $on = null): array
    {
        $date = self::date($on);
        $values = [];
        foreach ($this->rows($typeKey, $id) as $row) {
            if ($row['company_id'] === $companyId && in_array($row['status'], [ConfigurationOverride::ACTIVE, ConfigurationOverride::ENDED], true)
                && $row['effective_from'] <= $date && ($row['effective_to'] === null || $row['effective_to'] >= $date)) {
                $values[$row['field']] = $row['value'];
            }
        }

        return $values;
    }

    /**
     * The shared values with the company's overrides applied (what a consumer of the type reads for that company).
     *
     * @param  array<string, mixed>  $shared
     * @return array<string, mixed>
     */
    public function applyTo(array $shared, string $typeKey, int $id, int $companyId, CarbonInterface|string|null $on = null): array
    {
        foreach ($this->valuesFor($typeKey, $id, $companyId, $on) as $path => $value) {
            $shared = SettingsPath::set($shared, $path, $value);
        }

        return $shared;
    }

    /** Whether any company has an override of the record (the cheap check before applying). */
    public function hasAny(string $typeKey, int $id): bool
    {
        return $this->rows($typeKey, $id) !== [];
    }

    /**
     * Field by field for one company on a date: the shared value, the company's value, which one applies and why.
     *
     * @return list<array{path: string, label: string, group: ?string, shared: mixed, override: mixed, overridden: bool, effective: mixed, source: string, shared_display: string, effective_display: string}>
     */
    public function explain(Model $record, int $companyId, CarbonInterface|string|null $on = null): array
    {
        $type = $this->types->forModelOrFail($record);
        $provider = $this->provider($type) ?? throw new ConfigurationException("{$type->label} records cannot be overridden per company.");
        $date = self::date($on);
        $shared = $provider->shared($record, $date);
        $mine = $this->valuesFor($type->key, (int) $record->getKey(), $companyId, $date);
        $rows = [];
        foreach ($provider->fields($record) as $path => $field) {
            $sharedValue = SettingsPath::get($shared, $path, null);
            $overridden = array_key_exists($path, $mine);
            $effective = $overridden ? $mine[$path] : ($sharedValue ?? $field->default);
            $rows[] = [
                'path' => $path, 'label' => $field->label, 'group' => $field->group, 'shared' => $sharedValue, 'override' => $mine[$path] ?? null,
                'overridden' => $overridden, 'effective' => $effective,
                'source' => $overridden ? 'override' : ($sharedValue !== null ? 'shared' : 'default'),
                'shared_display' => $field->display($sharedValue ?? $field->default), 'effective_display' => $field->display($effective),
            ];
        }

        return $rows;
    }

    /** @return Collection<int, ConfigurationOverride> every override of the record (of one company, when given), newest first */
    public function history(Model $record, ?int $companyId = null): Collection
    {
        $type = $this->types->forModelOrFail($record);

        return ConfigurationOverride::query()->for($type->key, (int) $record->getKey())->with(['company', 'creator'])
            ->when($companyId !== null, fn ($q) => $q->where('company_id', $companyId))
            ->orderByDesc('effective_from')->orderByDesc('version')->get();
    }

    /** Gives the company its own value for one field from a date (ending its earlier value of that field from then). */
    public function set(Model $record, int $companyId, string $path, mixed $value, CarbonInterface|string|null $from, ?User $actor, ?string $reason = null): ConfigurationOverride
    {
        $type = $this->types->forModelOrFail($record);
        $provider = $this->provider($type) ?? throw new ApplicabilityRefusedException("{$type->label} records cannot be overridden per company.");
        $date = self::date($from);
        $fields = $provider->fields($record);
        $field = $fields[$path] ?? throw new ApplicabilityRefusedException("\"{$path}\" is not a field a company can override on this {$type->label}.");
        $this->authorise($type, $record, $companyId, $date, $actor);

        $validator = Validator::make(['value' => $value], ['value' => $field->validationRules()], [], ['value' => $field->label]);
        if ($validator->fails()) {
            throw new ApplicabilityRefusedException($validator->errors()->first('value'));
        }
        $value = $field->cast($value);
        $shared = $provider->shared($record, $date);
        if (str_contains($path, '[') && ! SettingsPath::has($shared, $path)) {
            throw new ApplicabilityRefusedException("{$field->label} is not in the shared {$type->label} any more, so it cannot be overridden.");
        }
        // The shared values with every override of the company in force from that date, and this one, must be valid together.
        $provider->validate($record, SettingsPath::set($this->applyTo($shared, $type->key, (int) $record->getKey(), $companyId, $date), $path, $value));
        $before = $this->valuesFor($type->key, (int) $record->getKey(), $companyId, $date)[$path] ?? SettingsPath::get($shared, $path, $field->default);

        $override = DB::transaction(function () use ($type, $record, $companyId, $path, $value, $date, $actor, $reason, $shared, $field, $before) {
            $version = $this->closeFrom($type->key, (int) $record->getKey(), $companyId, $path, $date, $actor);

            $override = ConfigurationOverride::create([
                'ulid' => (string) Str::ulid(), 'configuration_type' => $type->key, 'configuration_id' => $record->getKey(), 'company_id' => $companyId,
                'field' => $path, 'value' => $value, 'shared_value' => SettingsPath::get($shared, $path, $field->default), 'effective_from' => $date,
                'status' => ConfigurationOverride::ACTIVE, 'version' => $version + 1, 'reason' => $reason !== null ? mb_substr($reason, 0, 1000) : null,
                'created_by' => $actor?->getKey(),
            ]);
            $this->audit->record(AuditAction::OverrideSet, $type->module, $record, [['field' => $field->label.' · '.$this->companyName($companyId), 'before' => $field->display($before), 'after' => $field->display($value)]],
                $reason, effectiveDate: $date, metadata: ['configuration_type' => $type->key, 'company_id' => $companyId, 'field' => $path, 'override' => $override->ulid, 'version' => $override->version], actor: $actor);

            return $override;
        });
        self::flushMemo();

        return $override;
    }

    /** Ends the company's value of a field from a date: the shared value applies again from then. */
    public function end(Model $record, int $companyId, string $path, CarbonInterface|string|null $from, ?User $actor, ?string $reason = null): bool
    {
        $type = $this->types->forModelOrFail($record);
        $date = self::date($from);
        $this->authorise($type, $record, $companyId, $date, $actor, requireApplies: false);
        $field = $this->fields($record)[$path] ?? null;

        $ended = DB::transaction(function () use ($type, $record, $companyId, $path, $date, $actor, $reason, $field) {
            $before = $this->valuesFor($type->key, (int) $record->getKey(), $companyId, $date);
            if (! array_key_exists($path, $before) && ! $this->startsLater($type->key, (int) $record->getKey(), $companyId, $path, $date)) {
                return false;
            }
            $this->closeFrom($type->key, (int) $record->getKey(), $companyId, $path, $date, $actor);
            $this->audit->record(AuditAction::OverrideEnded, $type->module, $record, [['field' => ($field?->label ?? $path).' · '.$this->companyName($companyId),
                'before' => $field?->display($before[$path] ?? null) ?? json_encode($before[$path] ?? null), 'after' => 'Shared value']],
                $reason, effectiveDate: $date, metadata: ['configuration_type' => $type->key, 'company_id' => $companyId, 'field' => $path], actor: $actor);

            return true;
        });
        self::flushMemo();

        return $ended;
    }

    /** Refuses unless the person may override the record for the company from that date. */
    public function authorise(ConfigurationType $type, Model $record, int $companyId, string $date, ?User $actor, bool $requireApplies = true): void
    {
        if (! $type->supportsOverrides()) {
            throw new ApplicabilityRefusedException("{$type->label} records cannot be overridden per company.");
        }
        $company = Company::query()->withoutGlobalScope(AccessScope::class)->find($companyId)
            ?? throw new ApplicabilityRefusedException('That company does not exist in this organisation.');
        if ($date < today()->toDateString()) {
            throw new ApplicabilityRefusedException('An override cannot start in the past: what already happened keeps the values it used. Choose today or a later date.');
        }
        if ($requireApplies && ! $this->applicability->appliesTo($record, $companyId, $date)) {
            throw new ApplicabilityRefusedException("This {$type->label} does not apply to {$company->name} on that date, so there is nothing to override there.");
        }
        if ($type->payrollSensitive && ($closed = $this->closedPeriod($companyId, $date)) !== null) {
            throw new ApplicabilityRefusedException("{$company->name} has closed {$closed} already; an override can start only after it.");
        }
        if ($actor === null || $actor->isPlatformAdmin()) {
            return;
        }
        if (! $actor->hasPermission(self::PERMISSION, $companyId) || ! $actor->hasPermission($type->permission, $companyId)) {
            throw new ApplicabilityRefusedException("Your roles do not let you override {$type->plural()} for {$company->name}.");
        }
        $mine = $this->applicability->companiesFor($actor);
        if ($mine !== null && ! in_array($companyId, $mine, true)) {
            throw new ApplicabilityRefusedException("{$company->name} is outside your access.");
        }
    }

    /** The closed payroll or attendance month the date falls in for the company, if any (overrides never reach into it). */
    public function closedPeriod(int $companyId, string $date): ?string
    {
        $payroll = PayrollPeriod::query()->where('company_id', $companyId)->where('status', 'closed')
            ->where('start_date', '<=', $date.' 23:59:59')->where('end_date', '>=', $date)->first();
        if ($payroll !== null) {
            return 'the payroll period '.$payroll->start_date->format('j M Y').' – '.$payroll->end_date->format('j M Y');
        }
        $day = Carbon::parse($date);
        if (class_exists(AttendanceMonthCloses::class) && app(AttendanceMonthCloses::class)->isClosed($companyId, $day->year, $day->month)) {
            return 'attendance for '.$day->format('F Y');
        }

        return null;
    }

    /** @return list<array{company_id: int, field: string, value: mixed, status: string, effective_from: string, effective_to: ?string}> */
    private function rows(string $typeKey, int $id): array
    {
        $tenant = (string) ($this->tenants->id() ?? '0');
        $bucket = "{$tenant}:{$typeKey}";
        if (! isset(self::$memo[$bucket])) {
            // One query per type and tenant: which records have any override at all.
            self::$memo[$bucket] = ConfigurationOverride::query()->where('configuration_type', $typeKey)
                ->whereIn('status', [ConfigurationOverride::ACTIVE, ConfigurationOverride::ENDED])
                ->orderBy('effective_from')->orderBy('version')->get()
                ->groupBy('configuration_id')
                ->map(fn ($rows) => $rows->map(fn (ConfigurationOverride $o) => [
                    'company_id' => (int) $o->company_id, 'field' => $o->field, 'value' => $o->value, 'status' => $o->status,
                    'effective_from' => $o->effective_from->toDateString(), 'effective_to' => $o->effective_to?->toDateString(),
                ])->values()->all())->all();
        }

        return self::$memo[$bucket][$id] ?? [];
    }

    private function startsLater(string $typeKey, int $id, int $companyId, string $path, string $date): bool
    {
        return ConfigurationOverride::query()->for($typeKey, $id)->where('company_id', $companyId)->where('field', $path)
            ->where('status', ConfigurationOverride::ACTIVE)->where('effective_from', '>', $date.' 23:59:59')->exists();
    }

    /** Ends the field's overrides in force on or after the date; returns the highest version so far. */
    private function closeFrom(string $typeKey, int $id, int $companyId, string $path, string $date, ?User $actor): int
    {
        $rows = ConfigurationOverride::query()->for($typeKey, $id)->where('company_id', $companyId)->where('field', $path)->lockForUpdate()->get();
        $day = Carbon::parse($date);
        foreach ($rows as $row) {
            if ($row->status === ConfigurationOverride::SUPERSEDED || ($row->effective_to !== null && $row->effective_to->lt($day))) {
                continue;
            }
            if ($row->effective_from->gte($day)) {
                // It had not started yet: replaced before it ever applied.
                $row->update(['status' => ConfigurationOverride::SUPERSEDED, 'ended_by' => $actor?->getKey(), 'ended_at' => now()]);
            } else {
                $row->update(['status' => ConfigurationOverride::ENDED, 'effective_to' => $day->copy()->subDay()->toDateString(), 'ended_by' => $actor?->getKey(), 'ended_at' => now()]);
            }
        }

        return (int) $rows->max('version');
    }

    private function provider(ConfigurationType $type): ?OverrideFields
    {
        if (! $type->supportsOverrides()) {
            return null;
        }
        if (is_string($type->overrides) && class_exists($type->overrides)) {
            return app($type->overrides);
        }

        return new AttributeOverrideFields((array) $type->overrides);
    }

    private function companyName(int $companyId): string
    {
        return (string) (Company::query()->withoutGlobalScope(AccessScope::class)->find($companyId)?->name ?? "Company #{$companyId}");
    }

    private static function date(CarbonInterface|string|null $on): string
    {
        return $on === null ? today()->toDateString() : Carbon::parse($on)->toDateString();
    }
}
