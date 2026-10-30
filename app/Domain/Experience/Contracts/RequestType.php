<?php

namespace App\Domain\Experience\Contracts;

use App\Domain\Employment\Models\Employee;
use App\Domain\Experience\Enums\RequestStatus;
use App\Domain\Identity\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * HROne parity RQ-1: one kind of request a person can raise for themselves (tag: `peopleos.request_types`).
 *
 * A request type only READS and LINKS. It never creates, changes or cancels anything: the form it points at is the
 * module's existing request form (a page URL that opens the module's own Filament action, `?action=`), so the module's
 * authorisation, validation, approvals and audit apply exactly as they do on the module's own screen.
 *
 * - availableTo(): whether this person can actually raise one now — the destination's own access check, the action's own
 *   visibility rule and the module's own policy / service switches. It must never offer a type the person cannot raise.
 * - ownRecords(): the person's own requests of this type, with the model's global scopes (tenant, access scope) in place.
 *   Fail closed: null when the type has no records or the person has no employee record.
 * - statusMap() maps the module's statuses onto RequestStatus (the tracker's tabs); status() is the per-record answer.
 *
 * Registry: Experience\Services\RequestRegistry. Read model: Experience\Services\MyRequests. Defaults:
 * Experience\RequestTypes\BaseRequestType.
 */
interface RequestType
{
    /** Stable key (e.g. `leave`, `time_office.on_duty`); used for favourites, facets and Quick actions. */
    public function key(): string;

    public function label(): string;

    /** One line shown under the label in the launcher. */
    public function description(): string;

    /** The module group in the launcher ("Time office", "Payroll", "Expense & travel" …). */
    public function module(): string;

    public function icon(): string;

    /** @return list<string> words the launcher search and the Command Center match */
    public function keywords(): array;

    /** The permission the module asks for to raise one (documentation; availableTo() decides). */
    public function permission(): string;

    /** Whether the person can raise one now (signed-in person only; false for anyone else). */
    public function availableTo(User $user, ?Employee $employee): bool;

    /** The module's existing request form for the signed-in person (null when it cannot be opened). */
    public function formUrl(User $user, ?Employee $employee): ?string;

    /** The permission the module asks for to raise one for someone else (null: no on-behalf form). */
    public function onBehalfPermission(): ?string;

    /** The module's existing on-behalf form, when the signed-in person may open it (null otherwise). */
    public function onBehalfUrl(User $user): ?string;

    /** Whether the person's own records of this type may be listed (the module's own list opens for them). */
    public function historyVisibleTo(User $user): bool;

    /** @return class-string<Model>|null the model of the records (null: launcher only, no records of its own) */
    public function model(): ?string;

    /**
     * The person's own records, or null when the type keeps none or the person has no employee record where the type
     * needs one (fail closed). Most types key on the employee; a few on the user who raised them (form submissions).
     */
    public function ownRecords(User $user, ?Employee $employee): ?Builder;

    /** Whether the records belong to an employee (true) or to the user who raised them (false; never shown for a team member). */
    public function ownedByEmployee(): bool;

    /** @return array<string, RequestStatus> module status => tracker status */
    public function statusMap(): array;

    /** Narrow a records query to one tracker status. */
    public function whereStatus(Builder $query, RequestStatus $status): Builder;

    /** @return array<string, int> tracker status value => count, for a records query */
    public function countByStatus(Builder $query): array;

    public function status(Model $record): RequestStatus;

    /** The module's own wording of the record's status. */
    public function statusLabel(Model $record): string;

    /** @return array{title: string, detail: ?string} the row's title (what was asked) and detail (reason, amount …) */
    public function summary(Model $record): array;

    /**
     * The record's dates: requested (always), effective (the day / period asked for) and decided.
     *
     * @return array{requested: ?CarbonInterface, effective: ?CarbonInterface, decided: ?CarbonInterface}
     */
    public function dates(Model $record): array;

    /** @return array<string, array{label: string, column: string}> date kind => label and column (requested always present) */
    public function dateFilters(): array;

    /** @return list<string> columns searched by the tracker's search box (reference numbers, reasons) */
    public function searchColumns(): array;

    /** Where the record opens for this person (its view page or the module list), or null. */
    public function recordUrl(User $user, Model $record): ?string;

    /** The request family whose open requests are shown inside its forms (`time_office`), or null. */
    public function family(): ?string;
}
