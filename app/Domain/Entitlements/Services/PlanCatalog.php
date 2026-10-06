<?php

namespace App\Domain\Entitlements\Services;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Configuration\Enums\VersionStatus;
use App\Domain\Entitlements\Enums\Capability;
use App\Domain\Entitlements\Enums\CapabilityType;
use App\Domain\Entitlements\Enums\EnforcementClass;
use App\Domain\Entitlements\Models\Plan;
use App\Domain\Entitlements\Models\PlanEntitlement;
use App\Domain\Entitlements\Models\PlanVersion;
use App\Domain\Identity\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * SaaS.4: the only way Markedge's commercial plan catalogue changes. Platform operators only (refused here, not just
 * hidden from a screen); every change needs a reason and is audited on the platform chain.
 *
 * - A plan is a stable code plus versions: draft → published → retired (ADR-0009). One draft at a time.
 * - A draft says, per capability of the code-owned catalogue, what the plan includes: a module or feature on (or
 *   explicitly off), a limit (or unlimited). It can be edited freely and affects no tenant.
 * - Publishing freezes the version and opens its sale window from a date (today or later); the previous version's
 *   window ends the day before ("superseded"). Tenants already on the previous version keep it (grandfathering,
 *   ADR-0021): nothing in the catalogue ever changes a tenant's past or current answer.
 * - Retiring stops new assignments of a version; tenants on it keep it.
 * - A plan never switches a protected capability off (payroll, onboarding, exit; a zero employee limit). It may
 *   leave one out ("not in the plan"), which shadow mode reports as NOT_IN_PLAN, never as switched off.
 * - Serialised per plan on the plans row (FOR UPDATE); repeating a change changes nothing and is not audited.
 */
final class PlanCatalog
{
    public function __construct(private readonly AuditRecorder $audit) {}

    /** A new plan with an empty draft v1. The code is permanent. */
    public function create(string $code, string $name, ?string $description, string $reason, User $actor): Plan
    {
        $this->guard($actor, $reason);
        $code = trim($code);
        [$name, $description] = $this->describedAs($name, $description);
        if (preg_match(Plan::CODE_PATTERN, $code) !== 1) {
            throw new RuntimeException('A plan code is 2 to 64 lower-case letters, digits, "-" or "_", starting with a letter.');
        }

        try {
            return DB::transaction(function () use ($code, $name, $description, $reason, $actor) {
                $plan = Plan::query()->create(['code' => $code, 'name' => $name, 'description' => $description, 'created_by' => $actor->id, 'updated_by' => $actor->id]);
                $draft = PlanVersion::query()->create(['plan_id' => $plan->id, 'version' => 1, 'status' => VersionStatus::Draft, 'created_by' => $actor->id]);
                $this->recordAudit(AuditAction::PlanCreated, $plan, $reason, $actor, ['plan' => $code, 'name' => $name, 'draft_version_id' => $draft->id],
                    [['field' => 'code', 'before' => null, 'after' => $code], ['field' => 'name', 'before' => null, 'after' => $name]]);

                return $plan;
            });
        } catch (UniqueConstraintViolationException) {
            throw new RuntimeException("A plan with the code {$code} already exists.");
        }
    }

    /** The plan's name and description (its code never changes). */
    public function update(Plan $plan, string $name, ?string $description, string $reason, User $actor): Plan
    {
        $this->guard($actor, $reason);
        [$name, $description] = $this->describedAs($name, $description);

        return $this->locked($plan, function (Plan $plan) use ($name, $description, $reason, $actor) {
            $changes = collect(['name' => $name, 'description' => $description])
                ->filter(fn ($value, $field) => $plan->{$field} !== $value)
                ->map(fn ($value, $field) => ['field' => $field, 'before' => $plan->{$field}, 'after' => $value])->values()->all();
            if ($changes === []) {
                return $plan;
            }
            $plan->forceFill(['name' => $name, 'description' => $description, 'updated_by' => $actor->id])->save();
            $this->recordAudit(AuditAction::PlanUpdated, $plan, $reason, $actor, ['plan' => $plan->code], $changes);

            return $plan;
        });
    }

    /** The plan's draft: the existing one, or a new version copying the latest published (or retired) one. */
    public function draft(Plan $plan, string $reason, User $actor): PlanVersion
    {
        $this->guard($actor, $reason);

        return $this->locked($plan, function (Plan $plan) use ($reason, $actor) {
            $versions = PlanVersion::query()->where('plan_id', $plan->id)->with('entitlements')->orderBy('version')->get();
            if ($existing = $versions->firstWhere('status', VersionStatus::Draft)) {
                return $existing;
            }
            $basis = $versions->last();
            $draft = PlanVersion::query()->create(['plan_id' => $plan->id, 'version' => (int) $versions->max('version') + 1, 'status' => VersionStatus::Draft, 'created_by' => $actor->id]);
            foreach ($basis?->entitlements ?? [] as $row) {
                PlanEntitlement::query()->create(['plan_version_id' => $draft->id, 'capability' => $row->capability, 'value_bool' => $row->value_bool, 'value_int' => $row->value_int]);
            }
            $this->recordAudit(AuditAction::PlanVersionDrafted, $plan, $reason, $actor, ['plan' => $plan->code, 'plan_version' => $draft->version,
                'plan_version_id' => $draft->id, 'copied_from_version' => $basis?->version]);

            return $draft;
        });
    }

    /**
     * The draft's complete content: capability key => value (bool for a module or feature, int or null = unlimited for
     * a limit). A capability absent from $values is not in the plan. Unknown, non-commercial and protected-off values
     * are refused before anything changes.
     *
     * @param  array<string, bool|int|null>  $values
     */
    public function define(PlanVersion $draft, array $values, string $reason, User $actor): PlanVersion
    {
        return $this->change($draft, fn () => $values, $reason, $actor);
    }

    /** One capability of the draft (null value for a limit = unlimited). */
    public function set(PlanVersion $draft, Capability $capability, bool|int|null $value, string $reason, User $actor): PlanVersion
    {
        return $this->change($draft, fn (array $current) => [$capability->value => $value] + $current, $reason, $actor);
    }

    /** Leaves one capability out of the draft ("not in the plan"). */
    public function remove(PlanVersion $draft, Capability $capability, string $reason, User $actor): PlanVersion
    {
        return $this->change($draft, fn (array $current) => array_diff_key($current, [$capability->value => true]), $reason, $actor);
    }

    /**
     * Freezes the draft and puts it on sale from $from (today or later). The current version's sale ends the day
     * before. Tenants already assigned keep their version.
     */
    public function publish(PlanVersion $draft, string $from, string $reason, User $actor, ?string $changeNote = null): PlanVersion
    {
        $this->guard($actor, $reason);
        $from = $this->startDay($from);

        return $this->locked($draft->plan_id, function (Plan $plan) use ($draft, $from, $reason, $actor, $changeNote) {
            $versions = PlanVersion::query()->where('plan_id', $plan->id)->orderBy('version')->get();
            $draft = $versions->firstWhere('id', $draft->id) ?? throw new RuntimeException('No such plan version.');
            if ($draft->status === VersionStatus::Published && $draft->effective_from->toDateString() === $from) {
                return $draft; // the same publication again
            }
            if ($draft->status !== VersionStatus::Draft) {
                throw new RuntimeException("{$plan->code} v{$draft->version} is already {$draft->status->value}.");
            }
            $rows = PlanEntitlement::query()->where('plan_version_id', $draft->id)->get();
            if ($rows->isEmpty()) {
                throw new RuntimeException('A plan version must include or limit at least one capability before it is published.');
            }
            $rows->each(fn (PlanEntitlement $r) => $this->value($r->capability, $r->value())); // the rules again, at the point of no return

            $superseded = [];
            foreach ($versions->where('status', VersionStatus::Published) as $previous) {
                if ($previous->effective_from->toDateString() >= $from) {
                    throw new RuntimeException("v{$previous->version} goes on sale on {$previous->effective_from->toDateString()}; a later version must start after it.");
                }
                $lastDay = Carbon::parse($from)->subDay()->toDateString();
                if ($previous->effective_to === null || $previous->effective_to->toDateString() > $lastDay) {
                    $previous->forceFill(['effective_to' => $lastDay])->save();
                    $superseded[] = ['plan_version_id' => $previous->id, 'version' => $previous->version, 'sale_ends' => $lastDay];
                }
            }
            $draft->forceFill(['status' => VersionStatus::Published, 'effective_from' => $from, 'change_note' => $changeNote !== null ? Str::limit(trim($changeNote), 255, '') : null,
                'published_by' => $actor->id, 'published_at' => now()])->save();
            $this->recordAudit(AuditAction::PlanVersionPublished, $plan, $reason, $actor, ['plan' => $plan->code, 'plan_version' => $draft->version, 'plan_version_id' => $draft->id,
                'effective_from' => $from, 'entitlements' => $rows->mapWithKeys(fn (PlanEntitlement $r) => [$r->capability->value => $r->value()])->all(), 'superseded' => $superseded],
                [['field' => 'status', 'before' => 'draft', 'after' => 'published']], $from);

            return $draft;
        });
    }

    /** Stops new assignments of a published version. Tenants already on it keep it. */
    public function retire(PlanVersion $version, string $reason, User $actor): PlanVersion
    {
        $this->guard($actor, $reason);

        return $this->locked($version->plan_id, function (Plan $plan) use ($version, $reason, $actor) {
            $version = PlanVersion::query()->whereKey($version->id)->lockForUpdate()->firstOrFail();
            if ($version->status === VersionStatus::Retired) {
                return $version;
            }
            if ($version->status === VersionStatus::Draft) {
                throw new RuntimeException('A draft is not on sale: there is nothing to retire.');
            }
            $version->forceFill(['status' => VersionStatus::Retired, 'retired_by' => $actor->id, 'retired_at' => now()])->save();
            $this->recordAudit(AuditAction::PlanVersionRetired, $plan, $reason, $actor, ['plan' => $plan->code, 'plan_version' => $version->version, 'plan_version_id' => $version->id],
                [['field' => 'status', 'before' => 'published', 'after' => 'retired']]);

            return $version;
        });
    }

    /**
     * Applies $wanted (current content => new content) to the draft under the plan's lock, so two operators editing
     * the same draft at once never lose each other's change. Every value is checked before any row is written.
     *
     * @param  \Closure(array<string, bool|int|null>): array<string, bool|int|null>  $wanted
     */
    private function change(PlanVersion $draft, \Closure $wanted, string $reason, User $actor): PlanVersion
    {
        $this->guard($actor, $reason);

        return $this->locked($draft->plan_id, function (Plan $plan) use ($draft, $wanted, $reason, $actor) {
            $draft = PlanVersion::query()->whereKey($draft->id)->firstOrFail();
            if ($draft->status !== VersionStatus::Draft) {
                throw new RuntimeException("{$plan->code} v{$draft->version} is {$draft->status->value}: published versions never change. Create a new draft.");
            }
            $current = PlanEntitlement::query()->where('plan_version_id', $draft->id)->get()->keyBy(fn (PlanEntitlement $r) => $r->capability->value);
            $values = [];
            foreach ($wanted($current->map(fn (PlanEntitlement $r) => $r->value())->all()) as $key => $value) {
                $capability = Capability::tryFrom((string) $key) ?? throw new RuntimeException("{$key} is not a capability of the catalogue.");
                $values[$capability->value] = $this->value($capability, $value);
            }
            $changes = [];
            foreach ($current as $key => $row) {
                if (! array_key_exists($key, $values)) {
                    $changes[] = ['field' => $key, 'before' => $this->describe($row->capability, $row->value()), 'after' => 'not in plan'];
                    $row->delete();
                }
            }
            foreach ($values as $key => [$valueBool, $valueInt]) {
                $row = $current->get($key);
                if ($row !== null && $row->value_bool === $valueBool && $row->value_int === $valueInt) {
                    continue;
                }
                $capability = Capability::from($key);
                $changes[] = ['field' => $key, 'before' => $row ? $this->describe($capability, $row->value()) : 'not in plan', 'after' => $this->describe($capability, $valueBool ?? $valueInt)];
                ($row ?? new PlanEntitlement(['plan_version_id' => $draft->id, 'capability' => $capability]))->fill(['value_bool' => $valueBool, 'value_int' => $valueInt])->save();
            }
            if ($changes !== []) {
                $this->recordAudit(AuditAction::PlanVersionEdited, $plan, $reason, $actor, ['plan' => $plan->code, 'plan_version' => $draft->version, 'plan_version_id' => $draft->id], $changes);
            }

            return $draft;
        });
    }

    /** @return array<string, bool|int|null> the draft's current content, as define() takes it */
    public function values(PlanVersion $version): array
    {
        return PlanEntitlement::query()->where('plan_version_id', $version->id)->get()
            ->mapWithKeys(fn (PlanEntitlement $r) => [$r->capability->value => $r->value()])->all();
    }

    /**
     * Checks one plan value against the catalogue: commercial capabilities only, the right type, and never a
     * protected capability switched off (a module or feature false, a limit of zero).
     *
     * @return array{0: ?bool, 1: ?int}
     */
    private function value(Capability $capability, bool|int|null $value): array
    {
        if (! $capability->commercial()) {
            throw new RuntimeException("{$capability->value} is not a commercial capability: no plan can include or exclude it.");
        }
        $protected = $capability->enforcement() === EnforcementClass::Protected;
        if ($capability->type() === CapabilityType::Limit) {
            if (is_bool($value) || (is_int($value) && $value < 0)) {
                throw new RuntimeException("{$capability->value} takes a whole number of {$capability->unit()} (or unlimited).");
            }
            if ($protected && $value === 0) {
                throw new RuntimeException("{$capability->value} is protected: a plan may limit it or leave it out, never set it to zero.");
            }

            return [null, $value];
        }
        if (! is_bool($value)) {
            throw new RuntimeException("{$capability->value} is included or not.");
        }
        if ($protected && $value === false) {
            throw new RuntimeException("{$capability->value} is protected: a plan may include it or leave it out, never switch it off.");
        }

        return [$value, null];
    }

    private function describe(Capability $capability, bool|int|null $value): string
    {
        return match (true) {
            $capability->type() === CapabilityType::Limit => $value === null ? 'unlimited' : (string) $value,
            default => $value ? 'included' : 'excluded',
        };
    }

    /** @return array{0: string, 1: ?string} */
    private function describedAs(string $name, ?string $description): array
    {
        $name = trim($name);
        $description = $description === null || trim($description) === '' ? null : trim($description);
        if ($name === '' || Str::length($name) > 120) {
            throw new RuntimeException('A plan needs a name of at most 120 characters.');
        }
        if ($description !== null && Str::length($description) > 500) {
            throw new RuntimeException('A plan description is at most 500 characters.');
        }

        return [$name, $description];
    }

    /** Runs $work with the plan's row locked (FOR UPDATE): one change per plan at a time. */
    private function locked(Plan|int $plan, \Closure $work): mixed
    {
        $id = $plan instanceof Plan ? $plan->id : $plan;

        return DB::transaction(fn () => $work(Plan::query()->whereKey($id)->lockForUpdate()->firstOrFail()));
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @param  list<array{field: string, before: mixed, after: mixed}>  $changes
     */
    private function recordAudit(AuditAction $action, Plan $plan, string $reason, User $actor, array $metadata, array $changes = [], ?string $effectiveDate = null): void
    {
        $this->audit->record($action, 'entitlements', $plan, $changes, $reason, effectiveDate: $effectiveDate, metadata: $metadata,
            entityLabel: "Plan {$plan->code}", actor: $actor, platform: true);
    }

    private function guard(User $actor, string $reason): void
    {
        if (! $actor->isPlatformAdmin()) {
            throw new RuntimeException('Only platform operators can change commercial plans.');
        }
        if (Str::length(trim($reason)) < 5) {
            throw new RuntimeException('A reason is required.');
        }
    }

    private function startDay(string $day): string
    {
        try {
            $parsed = Carbon::createFromFormat('!Y-m-d', $day);
        } catch (\InvalidArgumentException) {
            $parsed = false;
        }
        if ($parsed === false || $parsed->toDateString() !== $day) {
            throw new RuntimeException("{$day} is not a date (YYYY-MM-DD).");
        }
        if ($day < now()->toDateString()) {
            throw new RuntimeException('A plan version goes on sale today or later: past days keep their answer.');
        }

        return $day;
    }
}
