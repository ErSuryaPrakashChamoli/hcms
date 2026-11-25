<?php

namespace App\Domain\Configuration\Applicability;

use App\Domain\Identity\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Model-level safeguards for registered configuration types (Part 9 of the applicability requirement), so every path
 * (screens, imports, API) is covered, not only the screens:
 *
 * - a person limited to some companies cannot change or delete a configuration record that reaches other companies
 *   (it is shared beyond their access); they may clone it for their company or override fields for it instead;
 * - a tenant-wide record created by a person limited to some companies applies to their companies only (never silently
 *   to every company);
 * - a deleted record's applicability rows go with it (its history stays in the audit trail).
 *
 * System work (console, queue, no signed-in person) and platform operators are not restricted here; tenant isolation
 * still applies to them.
 */
final class SharedConfigurationGuard
{
    private static int $suspended = 0;

    public function __construct(private readonly ConfigurationTypes $types) {}

    /** Runs a callback without the edit guard (the framework's own writes, already authorised). */
    public static function suspend(callable $callback): mixed
    {
        self::$suspended++;
        try {
            return $callback();
        } finally {
            self::$suspended--;
        }
    }

    public function register(): void
    {
        foreach ($this->types->all() as $type) {
            if (! $type->share || ! class_exists($type->model)) {
                continue;
            }
            $type->model::updating(fn (Model $record) => $this->assertEditable($record, 'change'));
            $type->model::deleting(fn (Model $record) => $this->assertEditable($record, 'delete'));
            $type->model::created(fn (Model $record) => $this->limitToCreator($record));
            $type->model::deleted(fn (Model $record) => $this->forget($record));
        }
    }

    public function assertEditable(Model $record, string $verb): void
    {
        $user = self::actor();
        if (self::$suspended > 0 || $user === null) {
            return;
        }
        if (! app(EntityApplicability::class)->editableBy($record, $user)) {
            throw new ApplicabilityRefusedException($this->explain($record, $user, $verb));
        }
    }

    /**
     * Remediation I.9: why a person limited to some companies may not change a record shared beyond them, who can, and
     * what they can do instead for their own companies. The other companies are counted, never named (the person may
     * not see them).
     */
    public function explain(Model $record, User $user, string $verb): string
    {
        $type = $this->types->forModelOrFail($record);
        $applicability = app(EntityApplicability::class);
        $mine = $applicability->companiesFor($user) ?? [];
        $reach = $applicability->reach($record) ?? $applicability->allCompanyIds();
        $others = count(array_diff($reach, $mine));
        $routes = array_values(array_filter([
            $type->supportsCloning() ? 'clone it for your company and change the copy (Companies → Clone)' : null,
            $type->supportsOverrides() ? 'give your company its own values (Companies → Company values…)' : null,
        ]));

        return "{$type->describe($record)} is shared with companies outside your access ({$others} ".($others === 1 ? 'company' : 'companies')
            ."), so you cannot {$verb} it: a change would reach them too. Someone whose access covers every company it applies to can, such as an administrator without a company limit."
            .($routes === [] ? '' : ' For your own companies you can '.implode(', or ', $routes).'.');
    }

    private function limitToCreator(Model $record): void
    {
        $user = self::actor();
        if (self::$suspended > 0 || $user === null || ! app(TenantContext::class)->id()) {
            return;
        }
        $applicability = app(EntityApplicability::class);
        $mine = $applicability->companiesFor($user);
        $type = $this->types->forModelOrFail($record);
        if ($mine === null || $mine === [] || $applicability->earlierReach($type, $record) !== null) {
            return;
        }
        $owner = $type->ownerColumn === null ? null : $record->getAttribute($type->ownerColumn);
        $companies = array_values(array_intersect($mine, $applicability->allCompanyIds()));
        if ($owner !== null || $companies === []) {
            return;
        }
        self::suspend(fn () => $applicability->apply($record, count($companies) === 1 ? ApplicabilitySelection::single($companies[0]) : ApplicabilitySelection::selected($companies),
            null, 'Created by someone limited to these companies'));
    }

    private function forget(Model $record): void
    {
        $type = $this->types->forModel($record);
        if ($type === null) {
            return;
        }
        DB::table('configuration_applicability_companies')->whereIn('applicability_id', DB::table('configuration_applicability')
            ->where('configuration_type', $type->key)->where('configuration_id', $record->getKey())->select('id'))->delete();
        DB::table('configuration_applicability')->where('configuration_type', $type->key)->where('configuration_id', $record->getKey())->delete();
        EntityApplicability::flushMemo();
    }

    private static function actor(): ?User
    {
        $user = auth()->user();

        return $user instanceof User && ! $user->isPlatformAdmin() ? $user : null;
    }
}
