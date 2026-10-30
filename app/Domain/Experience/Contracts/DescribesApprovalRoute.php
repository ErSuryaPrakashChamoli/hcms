<?php

namespace App\Domain\Experience\Contracts;

use App\Domain\Employment\Models\Employee;
use Illuminate\Database\Eloquent\Model;

/**
 * HROne parity RQ-2 (request chrome): what a request type tells the chrome about its approval route. Optional next to
 * RequestType (BaseRequestType implements it with neutral defaults); every answer is read only and describes what the
 * module already does — nothing here changes who approves anything.
 *
 * - raisedEvents() / workflowSubject(): the domain events the module dispatches when such a request is raised. They are the workflow
 *   triggers ("Who approves this": the first workflow that would start on them) and the notification events whose rules
 *   say who is informed (intimation).
 * - builtInApprovers(): how the module decides the request when no workflow runs on it (a label, never names).
 * - approverPermissions(): the rights of the people who decide it in the Approval Center (to find whom a reminder goes to).
 * - approvalKind(): the Approval Center kind of a record (discussion and inbox settings), null when it has none.
 * - waitingRecords(): the records whose approvals stand for the request (an expense report waits as its parts).
 * - knowledgeCategory(): the knowledge-base category whose articles are "the policy" of this request.
 * - owns(): whether a record of the type's model is of this type (several types share a model: the time-office kinds).
 */
interface DescribesApprovalRoute
{
    /** @return list<string> */
    public function raisedEvents(): array;

    /** @return class-string<Model>|null the model a workflow started on raising runs on (usually the request's own model) */
    public function workflowSubject(): ?string;

    public function builtInApprovers(): string;

    /** @return list<string> */
    public function approverPermissions(): array;

    public function approvalKind(Model $record): ?string;

    /** @return list<Model> */
    public function waitingRecords(Model $record): array;

    public function knowledgeCategory(): ?string;

    public function owns(Model $record): bool;

    /** The record attribute holding the module status (read from the audit trail's status changes). */
    public function statusField(): string;

    /** The module's own wording of one of its raw statuses. */
    public function statusText(string $raw): string;

    /** The employee a record is for (null when the type keeps none, e.g. a form submission). */
    public function employeeOf(Model $record): ?Employee;
}
