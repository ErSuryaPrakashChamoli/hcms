<?php

namespace App\Domain\Experience\Services;

use App\Domain\Employment\Models\Employee;
use App\Domain\Experience\Contracts\DescribesApprovalRoute;
use App\Domain\Experience\Contracts\RequestType;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\CurrentEmployee;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Model;
use Throwable;

/**
 * HROne parity RQ-1: the request types registered under the `peopleos.request_types` tag, by key, and what the signed-in
 * person may raise now. Read only: every entry links to the module's own request form.
 *
 * Availability is answered only for the signed-in person (the destinations' canAccess() reads them) and fails closed: a
 * type that throws is not offered. While the employee request lock (P4, SelfServiceLock) holds a kind of request, the types
 * of that kind are not offered either, and lockNotices() says why.
 */
final class RequestRegistry
{
    /** Request type key (or key prefix ending in ".") => the SelfServiceLock kind its module asserts before writing. */
    public const LOCK_KINDS = [
        'leave' => 'leave', 'time_office.' => 'time_office', 'attendance_regularisation' => 'regularisation', 'loan' => 'loan',
        'reimbursement' => 'reimbursement_claim', 'variable_pay' => 'variable_pay', 'expense_advance' => 'expense', 'expense_report' => 'expense',
        'travel' => 'travel', 'asset' => 'asset_request', 'helpdesk' => 'service_desk', 'hr_service' => 'service_desk',
        // HROne parity MS-3.
        'profile_change' => 'profile_change',
    ];

    /** @var array<string, RequestType>|null */
    private ?array $types = null;

    /** @var array<string, list<array{type: RequestType, url: string}>> per-request answers (the registry is request-scoped) */
    private array $memo = [];

    public function __construct(private readonly Container $app) {}

    /** @return array<string, RequestType> every registered type, by key, in registration order */
    public function all(): array
    {
        if ($this->types === null) {
            $this->types = [];
            foreach ($this->app->tagged('peopleos.request_types') as $type) {
                $this->types[$type->key()] = $type;
            }
        }

        return $this->types;
    }

    public function find(string $key): ?RequestType
    {
        return $this->all()[$key] ?? null;
    }

    /**
     * HROne parity RQ-2: the type a record belongs to (its model, and the type's own owns() check where several types share
     * a model), or null.
     */
    public function forRecord(Model $record): ?RequestType
    {
        foreach ($this->all() as $type) {
            $model = $type->model();
            if ($model !== null && $record instanceof $model && (! $type instanceof DescribesApprovalRoute || $type->owns($record))) {
                return $type;
            }
        }

        return null;
    }

    /**
     * HROne parity RQ-2: every registered type as "Module · Label" by key (Setup option source `request_types`).
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        return array_map(fn (RequestType $type) => $type->module().' · '.$type->label(), app(self::class)->all());
    }

    /** The signed-in person's own employee record (null for anyone else, or without one). */
    public function employeeOf(User $user): ?Employee
    {
        return $this->signedIn($user) ? app(CurrentEmployee::class)->of($user) : null;
    }

    /**
     * The types the signed-in person can raise now, each with its form URL.
     *
     * @return list<array{type: RequestType, url: string}>
     */
    public function available(User $user): array
    {
        if (! $this->signedIn($user)) {
            return [];
        }

        $employee = $this->employeeOf($user);
        $locks = [];

        return $this->memo['available:'.$this->memoKey($user)] ??= $this->gather(function (RequestType $type) use ($user, $employee, &$locks) {
            if (! $type->availableTo($user, $employee)) {
                return null;
            }
            $kind = self::lockKind($type);
            if ($kind !== null && $employee !== null && ($locks[$kind] ??= (string) $this->lockRefusal($kind, $employee, $user)) !== '') {
                return null;
            }

            return $type->formUrl($user, $employee);
        });
    }

    /** Whether the signed-in person can raise this type now. */
    public function isAvailable(User $user, string $key): bool
    {
        return collect($this->available($user))->contains(fn (array $entry) => $entry['type']->key() === $key);
    }

    /**
     * The module on-behalf forms the signed-in person may open ("Raise for someone").
     *
     * @return list<array{type: RequestType, url: string}>
     */
    public function onBehalf(User $user): array
    {
        if (! $this->signedIn($user)) {
            return [];
        }

        return $this->memo['behalf:'.$this->memoKey($user)] ??= $this->gather(fn (RequestType $type) => $type->onBehalfUrl($user));
    }

    /** Forget the per-request answers (after the person's permissions or the data behind them changed). */
    public function forget(): void
    {
        $this->memo = [];
    }

    /** @return list<string> why some request kinds are locked for the person now (employee request lock), one line per reason */
    public function lockNotices(User $user): array
    {
        $employee = $this->employeeOf($user);
        if ($employee === null || ! app(SelfServiceLock::class)->enabled()) {
            return [];
        }
        $notices = [];
        foreach (array_unique(self::LOCK_KINDS) as $kind) {
            $refusal = $this->attempt(fn () => app(SelfServiceLock::class)->refusal($employee, $kind, $user));
            if (is_string($refusal)) {
                $notices[$refusal] = $refusal;
            }
        }

        return array_values($notices);
    }

    /** The SelfServiceLock kind a type's module asserts, if any. */
    public static function lockKind(RequestType $type): ?string
    {
        foreach (self::LOCK_KINDS as $prefix => $kind) {
            if ($type->key() === $prefix || (str_ends_with($prefix, '.') && str_starts_with($type->key(), $prefix))) {
                return $kind;
            }
        }

        return null;
    }

    private function lockRefusal(string $kind, Employee $employee, User $user): ?string
    {
        $lock = app(SelfServiceLock::class);

        return $lock->enabled() ? $lock->refusal($employee, $kind, $user) : null;
    }

    /**
     * @param  callable(RequestType): ?string  $url
     * @return list<array{type: RequestType, url: string}>
     */
    private function gather(callable $url): array
    {
        $out = [];
        foreach ($this->all() as $type) {
            $target = $this->attempt(fn () => $url($type));
            if (is_string($target) && $target !== '') {
                $out[] = ['type' => $type, 'url' => $target];
            }
        }

        return $out;
    }

    private function memoKey(User $user): string
    {
        return (app(TenantContext::class)->id() ?? 0).':'.$user->getKey();
    }

    private function signedIn(User $user): bool
    {
        return auth()->check() && (int) auth()->id() === (int) $user->getKey();
    }

    private function attempt(callable $callback): mixed
    {
        try {
            return $callback();
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }
}
