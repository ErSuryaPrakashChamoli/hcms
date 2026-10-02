<?php

namespace App\Domain\ServiceDesk\Contracts;

use App\Domain\Employment\Models\Employee;
use App\Domain\Employment\Support\ChangeOrigin;
use App\Domain\Identity\Models\User;
use App\Domain\ServiceDesk\Models\Ticket;
use Illuminate\Database\Eloquent\Model;

/**
 * Phase 12: how a catalogue service hands a request to the domain that owns the change. A handler
 * never writes another module's tables: it calls that module's action / contract, which authorises
 * the actor again and audits the change.
 *
 * **Timing:**
 * - `on_submit`: the domain keeps its own approval (leave, attendance regularisation, letters). The
 *   request is made in the domain when the service request is submitted, and the case tracks it.
 * - `after_approval`: an HR executor runs it once the request is ready. If the service needs approval,
 *   the workflow must approve first, and requester ≠ approver ≠ executor. Covers profile changes and
 *   manager change.
 * - `link`: the executor links a record they created in the owning domain (a compensation proposal).
 *   The domain's own chain continues there.
 */
interface ServiceDomainAction
{
    public function key(): string;

    public function label(): string;

    /** on_submit | after_approval | link */
    public function timing(): string;

    /** @return list<string> request sources accepted: web (oneself), manager (a report), hr (on someone's behalf) */
    public function sources(): array;

    /**
     * The request fields this action needs (merged into the service form). `class` classifies them
     * (standard | sensitive | restricted); sensitive values are purged after the change.
     *
     * @return array<string, array{label: string, type: string, required?: bool, options?: array<string, string>, class?: string, employee_visible?: bool, help?: string}>
     */
    public function fields(?Employee $employee): array;

    /** @return array<string, array{label: string, type: string, required?: bool, options?: array<string, string>}> fields the executor gives when executing (link actions) */
    public function executionFields(Employee $employee, User $executor): array;

    /**
     * Who may ask for this change for whom (source web = for oneself, manager = for a report, hr = on
     * someone's behalf). Throws ServiceDeskRuleViolation.
     */
    public function authorizeRequest(User $requester, Employee $employee, string $source): void;

    /** The permission an executor needs (the owning domain's permission); null for on_submit actions. */
    public function executorPermission(): ?string;

    /** The permission needed to read this action's sensitive request values. */
    public function viewPermission(): string;

    /**
     * Validate request data with the owning domain's rules.
     *
     * @return array<string, mixed>
     */
    public function validate(Employee $employee, array $data): array;

    /** Run the owning domain's action; returns the resulting record (referenced, never copied). */
    public function execute(Ticket $ticket, Employee $employee, array $data, User $actor, ChangeOrigin $origin): Model;
}
