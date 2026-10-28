<?php

namespace App\Domain\Analytics\Datasets;

use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Builder;

/** User access review: accounts of the current tenant with roles, MFA and last sign-in. Secrets are never read. */
class UsersDataset extends Dataset
{
    /** @var array<int, string>|null employee code by user id, loaded once per dataset instance */
    private ?array $codes = null;

    public function key(): string
    {
        return 'users';
    }

    public function label(): string
    {
        return 'User accounts and access (roles, MFA, last sign-in)';
    }

    public function category(): string
    {
        return 'Administration';
    }

    public function permissions(): array
    {
        return ['user.view'];
    }

    public function fields(): array
    {
        return [
            'name' => ['label' => 'Name', 'type' => 'string', 'value' => fn ($u) => $u->name],
            'email' => ['label' => 'Email', 'type' => 'string', 'value' => fn ($u) => $u->email],
            'employee_code' => ['label' => 'Employee code', 'type' => 'string', 'value' => fn ($u) => ($this->codes ??= Employee::query()->whereNotNull('user_id')->pluck('employee_code', 'user_id')->all())[$u->id] ?? null],
            'status' => ['label' => 'Status', 'type' => 'string', 'value' => fn ($u) => $u->status],
            'roles' => ['label' => 'Roles', 'type' => 'string', 'value' => fn ($u) => $u->roles->pluck('name')->unique()->sort()->implode(', ')],
            'role_count' => ['label' => 'Number of roles', 'type' => 'number', 'value' => fn ($u) => $u->roles->pluck('id')->unique()->count()],
            'mfa_enabled' => ['label' => 'Two-factor enabled', 'type' => 'boolean', 'value' => fn (User $u) => $u->hasMfaEnabled()],
            'email_verified' => ['label' => 'Email verified', 'type' => 'boolean', 'value' => fn (User $u) => $u->hasVerifiedEmail()],
            'single_sign_on' => ['label' => 'Single sign-on', 'type' => 'boolean', 'value' => fn ($u) => filled($u->sso_subject)],
            'last_login' => ['label' => 'Last sign-in', 'type' => 'date', 'value' => fn ($u) => $u->last_login_at],
            'days_since_login' => ['label' => 'Days since last sign-in', 'type' => 'number', 'value' => fn ($u) => $u->last_login_at ? (int) $u->last_login_at->startOfDay()->diffInDays(now()->startOfDay()) : null],
            'created_on' => ['label' => 'Created on', 'type' => 'date', 'value' => fn ($u) => $u->created_at],
            'count' => ['label' => 'Users (1 per row)', 'type' => 'number', 'value' => fn ($u) => 1],
        ];
    }

    public function query(): Builder
    {
        // Users are not globally tenant-scoped (see the model), so the dataset scopes explicitly.
        return User::query()->forCurrentTenant()->with('roles')->orderBy('name');
    }
}
