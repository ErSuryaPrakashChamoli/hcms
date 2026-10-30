<?php

namespace App\Domain\Employment\Services;

use App\Domain\Documents\Services\DocumentRules;
use App\Domain\Employment\Exceptions\ProfileChangeRefused;
use App\Domain\Employment\Models\Employee;
use App\Domain\Employment\Support\ChangeOrigin;
use App\Domain\Employment\Support\SelfServiceGrant;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\AccessScopes;

/**
 * Phase 12: the authorization every People / Employment profile change action applies itself —
 * tenant (the employee must be reachable in the current tenant), permission, organisation scope —
 * whatever the caller (Employee 360, an approved service request, the API). Screens may hide fields,
 * but the action is what decides.
 */
final class ProfileChangeGuard
{
    public function __construct(private readonly AccessScopes $scopes) {}

    /**
     * HROne setup parity CA-4: a profile field a document type is "required with" (peopleos.documents.linked_fields) is
     * entered or changed only while the employee has a current document of that type.
     */
    public function requireLinkedDocument(Employee $employee, string $field): void
    {
        $missing = app(DocumentRules::class)->missingForField($employee, $field);
        if ($missing !== null) {
            throw new ProfileChangeRefused("Upload the employee's {$missing} first: it is required with this field.");
        }
    }

    /**
     * HROne parity MS-3: a self-service profile change applying without approval passes on its SelfServiceGrant (set only
     * by ProfileChanges for that request, the employee's own record and the rights its operations need) instead of the
     * actor's own rights; every other caller is authorised exactly as before.
     */
    public function authorize(User $actor, Employee $employee, string $permission, ?ChangeOrigin $origin = null): void
    {
        if (! $actor->isActive() && ! $actor->is_platform_admin) {
            throw new ProfileChangeRefused('Only an active user can change employee data.');
        }
        if ($origin !== null && $origin->isSelfService() && SelfServiceGrant::allows($actor, $employee, $permission, $origin)) {
            return;
        }
        if (! $actor->hasPermission($permission)) {
            throw new ProfileChangeRefused("This needs {$permission}.");
        }
        if (! $this->scopes->allows($actor, $employee)) {
            throw new ProfileChangeRefused('That employee is outside your organisation scope.');
        }
    }
}
