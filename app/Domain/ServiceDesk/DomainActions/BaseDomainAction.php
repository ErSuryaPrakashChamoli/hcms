<?php

namespace App\Domain\ServiceDesk\DomainActions;

use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\AccessScopes;
use App\Domain\Performance\Services\PerformanceRelationships;
use App\Domain\ServiceDesk\Contracts\ServiceDomainAction;
use App\Domain\ServiceDesk\Exceptions\ServiceDeskRuleViolation;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Phase 12: shared request authorisation and validation for domain hand-offs. */
abstract class BaseDomainAction implements ServiceDomainAction
{
    /** Sources this action accepts: web (oneself), manager (a report), hr (on someone's behalf). */
    protected array $sources = ['web', 'hr'];

    public function key(): string
    {
        return (string) array_search(static::class, config('peopleos.servicedesk.domain_actions', []), true);
    }

    public function sources(): array
    {
        return $this->sources;
    }

    public function executionFields(Employee $employee, User $executor): array
    {
        return [];
    }

    public function authorizeRequest(User $requester, Employee $employee, string $source): void
    {
        if (! in_array($source, $this->sources, true)) {
            throw new ServiceDeskRuleViolation($this->label().' cannot be requested '.match ($source) {
                'manager' => 'by a manager for a report', 'hr' => 'by HR on someone\'s behalf', default => 'through self-service',
            }.'.');
        }
        match ($source) {
            // Self-service is for one's own record only: employee A never requests a change to B's record.
            'web' => (int) $employee->user_id === (int) $requester->id ?: throw new ServiceDeskRuleViolation('Self-service requests are for your own record only.'),
            'manager' => app(PerformanceRelationships::class)->manages(app(PerformanceRelationships::class)->forUser($requester), $employee->id) && $requester->hasPermission('servicedesk.team')
                ?: throw new ServiceDeskRuleViolation('You can raise this only for people you manage.'),
            'hr' => $requester->hasPermission('servicedesk.agent') && app(AccessScopes::class)->allows($requester, $employee) && (int) $employee->user_id !== (int) $requester->id
                ?: throw new ServiceDeskRuleViolation('That employee is outside your service desk scope.'),
            default => throw new ServiceDeskRuleViolation('Unknown request source.'),
        };
    }

    public function viewPermission(): string
    {
        return $this->executorPermission() ?? 'servicedesk.agent';
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $rules
     * @return array<string, mixed>
     */
    protected function check(array $data, array $rules): array
    {
        try {
            return Validator::make(array_intersect_key($data, $rules), $rules)->validate();
        } catch (ValidationException $e) {
            throw new ServiceDeskRuleViolation(collect($e->errors())->flatten()->first() ?? 'The request is not valid.');
        }
    }
}
