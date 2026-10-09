<?php

namespace App\Domain\Signup\Services;

use App\Domain\Attendance\Models\HolidayCalendar;
use App\Domain\Attendance\Models\Shift;
use App\Domain\Compliance\Models\ComplianceRule;
use App\Domain\Configuration\Models\Policy;
use App\Domain\Employment\Models\Employee;
use App\Domain\Entitlements\Enums\Capability;
use App\Domain\Entitlements\Models\PlanEntitlement;
use App\Domain\Experience\Services\ScreenAccess;
use App\Domain\Identity\Models\User;
use App\Domain\Organisation\Models\BusinessUnit;
use App\Domain\Organisation\Models\Company;
use App\Domain\Organisation\Models\Department;
use App\Domain\Organisation\Models\LegalEntity;
use App\Domain\Organisation\Models\Location;
use App\Domain\Platform\Models\Tenant;
use App\Domain\Signup\Models\SignupApplication;
use App\Domain\Subscriptions\Models\SubscriptionPeriod;
use App\Filament\Resources\BusinessUnits\BusinessUnitResource;
use App\Filament\Resources\Companies\CompanyResource;
use App\Filament\Resources\Departments\DepartmentResource;
use App\Filament\Resources\Employees\EmployeeResource;
use App\Filament\Resources\HolidayCalendars\HolidayCalendarResource;
use App\Filament\Resources\LegalEntities\LegalEntityResource;
use App\Filament\Resources\Locations\LocationResource;
use App\Filament\Resources\Policies\PolicyResource;
use App\Filament\Resources\Roles\RoleResource;
use App\Filament\Resources\Users\UserResource;
use App\Support\Tenancy\TenantContext;
use Throwable;

/**
 * SaaS.10: the first-run checklist of a tenant, read only, for the signed-in administrator. It keeps apart what are
 * separate states: the account exists, the organisation is provisioned, its set-up is complete, a commercial
 * subscription is in force, and sensitive modules are configured and verified. Every count is a count of the tenant's
 * own records (fail-closed tenancy); a link is offered only when the person can open that screen (ScreenAccess), and a
 * step they cannot do says which role it needs instead. Nothing here switches anything on.
 */
final class TenantSetupProgress
{
    public function __construct(private readonly ScreenAccess $screens, private readonly TenantContext $tenants) {}

    /** Whether this tenant was registered through self-service signup (the Home banner shows only then). */
    public static function fromSignup(?int $tenantId): bool
    {
        return $tenantId !== null && SignupApplication::query()->where('provisioned_tenant_id', $tenantId)->exists();
    }

    /** @return array{states: list<array{label: string, state: string, tone: string, detail: string}>, items: list<array<string, mixed>>, done: int, total: int} */
    public function for(User $user): array
    {
        $tenant = Tenant::query()->findOrFail($this->tenants->id() ?? $user->tenant_id);
        $items = $this->items($user);
        $done = count(array_filter($items, fn (array $i) => $i['done']));

        return ['states' => $this->states($user, $tenant, $done, count($items)), 'items' => $items, 'done' => $done, 'total' => count($items)];
    }

    /** @return list<array{label: string, state: string, tone: string, detail: string}> */
    private function states(User $user, Tenant $tenant, int $done, int $total): array
    {
        $commercial = $this->commercial();
        $payrollAdmins = User::query()->where('tenant_id', $tenant->id)->whereHas('roles', fn ($q) => $q->where('slug', 'payroll-admin'))->count();
        $rules = ComplianceRule::query()->count();
        $verified = ComplianceRule::query()->where('verification_status', ComplianceRule::VERIFIED)->count();

        return [
            ['label' => 'Your account', 'state' => 'Active', 'tone' => 'success', 'detail' => "Signed in as {$user->email}."],
            ['label' => 'Organisation in PeopleOS', 'state' => $tenant->status->getLabel(), 'tone' => $tenant->isAccessible() ? 'success' : 'warning',
                'detail' => "{$tenant->name} is set up and isolated from every other organisation."],
            ['label' => 'Organisation set-up', 'state' => $done === $total ? 'Complete' : "{$done} of {$total} steps", 'tone' => $done === $total ? 'success' : 'warning',
                'detail' => $done === $total ? 'Every set-up step below is done.' : 'Finish the steps below; you can come back to this page at any time.'],
            $commercial,
            ['label' => 'Payroll and statutory calculations', 'state' => 'Not active', 'tone' => 'gray',
                'detail' => ($payrollAdmins > 0 ? 'A Payroll Admin is assigned. ' : 'Nobody has the Payroll Admin role yet: payroll stays closed until you give it to someone. ')
                    ."Statutory calculations run only on rules Markedge has verified ({$verified} of {$rules} verified); payroll cannot be finalised on unverified rules."],
        ];
    }

    /** @return array{label: string, state: string, tone: string, detail: string} */
    private function commercial(): array
    {
        $today = now()->toDateString();
        $period = rescue(fn () => SubscriptionPeriod::query()->whereNull('voided_at')->whereDate('starts_on', '<=', $today)
            ->where(fn ($q) => $q->whereNull('ends_on')->orWhereDate('ends_on', '>=', $today))->orderByDesc('starts_on')->first(), null, false);
        if ($period === null) {
            return ['label' => 'Subscription', 'state' => 'Not started', 'tone' => 'warning', 'detail' => 'No plan is in force yet. Markedge agrees your subscription with you.'];
        }
        $plan = $period->planVersion?->loadMissing('plan')->plan?->name ?? 'your plan';
        $limit = PlanEntitlement::query()->where('plan_version_id', $period->plan_version_id)->where('capability', Capability::ActiveEmployeesMax->value)->value('value_int');
        $employees = Employee::query()->count();
        $limitText = $limit === null ? '' : ' It covers up to '.number_format((int) $limit).' active employees (you have '.number_format($employees).').';

        return match ($period->status->value) {
            'trial' => ['label' => 'Subscription', 'state' => 'Trial', 'tone' => 'info', 'detail' => "{$plan} trial until {$period->ends_on?->toDateString()}.{$limitText} No payment is taken during the trial."],
            'active' => ['label' => 'Subscription', 'state' => 'Active', 'tone' => 'success', 'detail' => "{$plan}.{$limitText}"],
            'grace' => ['label' => 'Subscription', 'state' => 'Grace period', 'tone' => 'warning', 'detail' => "{$plan}, in a grace period until {$period->ends_on?->toDateString()}. Contact Markedge."],
            default => ['label' => 'Subscription', 'state' => ucfirst($period->status->value), 'tone' => 'warning', 'detail' => 'Contact Markedge about your subscription.'],
        };
    }

    /** @return list<array{key: string, title: string, why: string, done: bool, url: ?string, needs: ?string}> */
    private function items(User $user): array
    {
        $others = User::query()->where('tenant_id', $user->tenant_id)->whereKeyNot($user->id);

        return [
            $this->item('company', 'Confirm your company', 'The employing company: its name, address and identifiers appear on letters and reports.',
                Company::query()->exists(), CompanyResource::class, 'Account Owner'),
            $this->item('structure', 'Set up your organisation structure', 'Departments or business units, so people, approvals and reports follow your organisation.',
                Department::query()->exists() || BusinessUnit::query()->exists(), DepartmentResource::class, 'Account Owner', BusinessUnitResource::class),
            $this->item('legal', 'Add legal entities and establishments', 'Where statutory registrations apply. Payroll and returns read them from the establishment.',
                LegalEntity::query()->exists(), LegalEntityResource::class, 'Account Owner'),
            $this->item('locations', 'Add your locations', 'Offices and sites, for working days, holidays and reporting.', Location::query()->exists(), LocationResource::class, 'Account Owner'),
            $this->item('working_days', 'Set working days and holidays', 'Shifts and holiday calendars. Attendance stays off until they exist.',
                HolidayCalendar::query()->exists() || Shift::query()->exists(), HolidayCalendarResource::class, 'Attendance Admin'),
            $this->item('policies', 'Choose your policies', 'Leave and other policies people are measured against.', Policy::query()->exists(), PolicyResource::class, 'Tenant HR Admin'),
            $this->item('employees', 'Add your employees', 'One record per person for their whole working life with you.', Employee::query()->exists(), EmployeeResource::class, 'Account Owner'),
            $this->item('invite', 'Invite managers and colleagues', 'They receive an e-mail to choose their own password; you never see it.', (clone $others)->exists(), UserResource::class, 'Account Owner'),
            $this->item('roles', 'Give people their roles', 'Roles decide what each person can see and do. Payroll, attendance and HR administration are separate roles you give explicitly.',
                (clone $others)->whereHas('roles')->exists(), RoleResource::class, 'Account Owner', UserResource::class),
        ];
    }

    /**
     * @param  class-string  $resource
     * @param  class-string|null  $alternative
     * @return array{key: string, title: string, why: string, done: bool, url: ?string, needs: ?string}
     */
    private function item(string $key, string $title, string $why, bool $done, string $resource, string $role, ?string $alternative = null): array
    {
        $url = null;
        foreach (array_filter([$resource, $alternative]) as $candidate) {
            $candidateUrl = rescue(fn () => $candidate::getUrl('index'), null, false);
            if ($candidateUrl !== null && $this->reachable($candidateUrl)) {
                $url = $candidateUrl;
                break;
            }
        }

        return ['key' => $key, 'title' => $title, 'why' => $why, 'done' => $done, 'url' => $url, 'needs' => $url === null ? $role : null];
    }

    private function reachable(string $url): bool
    {
        try {
            return $this->screens->allows($url);
        } catch (Throwable) {
            return false;
        }
    }
}
