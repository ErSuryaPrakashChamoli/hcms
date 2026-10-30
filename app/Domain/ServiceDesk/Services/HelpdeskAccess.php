<?php

namespace App\Domain\ServiceDesk\Services;

use App\Domain\Configuration\Services\PolicyResolver;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\ServiceDesk\Exceptions\ServiceDeskRuleViolation;
use App\Domain\ServiceDesk\Models\ServiceDefinition;
use Illuminate\Support\Carbon;

/**
 * HROne setup parity HD-C (Helpdesk → Helpdesk policy): the `helpdesk` policy resolved for an employee decides which
 * ticket categories and catalogue services (HROne sub-categories) can be raised for them by themselves or their manager,
 * and whether the managers it covers may raise requests for their team. HR raising on someone's behalf is not limited.
 *
 * - No policy for the employee: everything as before.
 * - `categories` empty: every category; `services` empty: every service of an allowed category; a listed service is
 *   allowed whatever its category.
 * - `raise_on_behalf_of_team` not answered: yes (as before).
 */
final class HelpdeskAccess
{
    public function __construct(private readonly PolicyResolver $policies) {}

    /** @return array<string, mixed>|null the employee's helpdesk policy settings today, or null with no policy */
    public function settingsFor(Employee $employee): ?array
    {
        $version = $this->policies->resolve('helpdesk', $employee, Carbon::today());

        return $version === null ? null : (array) ($version->settings ?? []);
    }

    /** @param  array<string, mixed>|null|false  $settings  already resolved settings (false: resolve them) */
    public function allowsCategory(Employee $employee, ?int $categoryId, array|null|false $settings = false): bool
    {
        $settings = $settings === false ? $this->settingsFor($employee) : $settings;
        $allowed = $this->ids($settings['categories'] ?? []);

        return $settings === null || $allowed === [] || in_array((int) $categoryId, $allowed, true);
    }

    /** @param  array<string, mixed>|null|false  $settings  already resolved settings (false: resolve them) */
    public function allowsService(Employee $employee, ServiceDefinition $service, array|null|false $settings = false): bool
    {
        $settings = $settings === false ? $this->settingsFor($employee) : $settings;
        if ($settings === null) {
            return true;
        }
        $services = $this->ids($settings['services'] ?? []);

        return $services !== [] ? in_array((int) $service->id, $services, true) : $this->allowsCategory($employee, $service->ticket_category_id, $settings);
    }

    /** May this manager raise requests for their team (their own helpdesk policy)? */
    public function managerMayRaise(User $manager): bool
    {
        $own = Employee::query()->withoutGlobalScope(AccessScope::class)->where('user_id', $manager->id)->first();
        $answer = $own ? ($this->settingsFor($own)['raise_on_behalf_of_team'] ?? null) : null;

        return $answer === null || $answer === '' || filter_var($answer, FILTER_VALIDATE_BOOLEAN);
    }

    /** Refuses a self-service or manager request the helpdesk policy does not allow (HR and API are not limited). */
    public function assertService(User $requester, Employee $employee, ?ServiceDefinition $service, string $source): void
    {
        if (! in_array($source, ['web', 'manager'], true) || $service === null) {
            return;
        }
        if ($source === 'manager' && ! $this->managerMayRaise($requester)) {
            throw new ServiceDeskRuleViolation('Your helpdesk policy does not let you raise requests for your team.');
        }
        if (! $this->allowsService($employee, $service)) {
            throw new ServiceDeskRuleViolation('This service is not available for this employee (helpdesk policy).');
        }
    }

    /** @return list<int> */
    private function ids(mixed $values): array
    {
        return array_values(array_map('intval', array_filter((array) $values, fn ($v) => $v !== null && $v !== '')));
    }
}
