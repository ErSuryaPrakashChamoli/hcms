<?php

namespace App\Domain\ComplianceReports\Policies;

use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\AccessScopes;
use Illuminate\Database\Eloquent\Model;

/**
 * Compliance report packs. Listed with compliance.reports.view within the company scope; generated with
 * compliance.reports.generate through ComplianceReports, which also limits downloads to the requester. A pack is a
 * generated file: never edited or deleted by hand.
 */
class ComplianceReportPackPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('compliance.reports.view') || $user->hasPermission('compliance.reports.generate');
    }

    public function view(User $user, Model $model): bool
    {
        return $this->viewAny($user) && app(AccessScopes::class)->allows($user, $model);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('compliance.reports.generate');
    }

    public function update(User $user, Model $model): bool
    {
        return false;
    }

    public function delete(User $user, Model $model): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
