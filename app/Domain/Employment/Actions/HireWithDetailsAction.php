<?php

namespace App\Domain\Employment\Actions;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Configuration\Services\CustomFields;
use App\Domain\Employment\Exceptions\ProfileChangeRefused;
use App\Domain\Employment\Models\Employee;
use App\Domain\Employment\Services\ReturningEmployees;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\BulkInvitations;
use App\Domain\Lifecycle\Enums\LifecycleState;
use App\Domain\People\Actions\ChangeAddressAction;
use App\Domain\People\Actions\ChangeExperienceAction;
use App\Domain\People\Actions\ChangeFamilyMemberAction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

/**
 * HROne parity WO-3 (matrix 23 §23.2.3 #7–8, 12, 15–18, 22): the hire wizard's "hire with details". It is HireEmployeeAction
 * plus the optional parts HR may fill at hire, each written by the action the Employee 360 uses for it — so validation,
 * encryption, CA-4 linked documents, audit, timeline and events are those of the 360:
 * - starting state (`employee.lifecycle`): HireEmployeeAction's historic start states;
 * - position (seat) (`workforce.view` / `workforce.manage`): AssignPositionAction's seat guard (open, capacity, scope);
 * - functional manager and HR business partner: ChangeManagerAction from the joining date, as the line manager is;
 * - PAN / Aadhaar reference: ChangeStatutoryIdentityAction (`employee.sensitive.update`);
 * - addresses, family members, previous employment: the People change actions (`employee.create` to add);
 * - bank account: ChangeBankAccountAction (`employee.sensitive.update`);
 * - other details (custom fields): CustomFields::store, as the edit form (the employee's `update` policy).
 * The hire and these parts are one transaction: a refusal by any of them leaves nothing behind. With nothing optional
 * given, this is exactly HireEmployeeAction (same records, same order).
 *
 * The returning-employee check (ReturningEmployees) runs first: a match marked not eligible for rehire needs the
 * confirmation reason, which is then audited on the new employee (REVIEWED, metadata check = returning_employee).
 *
 * "Create a sign-in and invite" runs after the commit through WO-16's BulkInvitations::invite (the same user creation,
 * RoleGrants ceiling and UserInvitations::issue), because the invitation e-mail is sent at once and must never point at a
 * hire that was rolled back. A refusal there leaves the hire in place and is reported in the outcome.
 */
final class HireWithDetailsAction
{
    public function __construct(
        private readonly HireEmployeeAction $hire,
        private readonly ChangeManagerAction $managers,
        private readonly ChangeStatutoryIdentityAction $statutory,
        private readonly ChangeAddressAction $addresses,
        private readonly ChangeFamilyMemberAction $family,
        private readonly ChangeExperienceAction $experiences,
        private readonly ChangeBankAccountAction $bank,
        private readonly CustomFields $customFields,
        private readonly ReturningEmployees $returning,
        private readonly BulkInvitations $invitations,
        private readonly AuditRecorder $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $person  as HireEmployeeAction (attributes, or ['id' => existing person])
     * @param  array<string, mixed>  $employee  as HireEmployeeAction
     * @param  array<string, mixed>  $position  dimension ids, optionally position_id (a seat)
     * @param  array{start_state?: ?string, confirmation_date?: ?string, functional_manager_id?: ?int, hrbp_id?: ?int, statutory?: array<string, ?string>, returning_confirmation?: ?string, addresses?: list<array<string, mixed>>, family?: list<array<string, mixed>>, bank?: ?array<string, mixed>, experiences?: list<array<string, mixed>>, custom_fields?: ?array<string, mixed>, invite?: ?array{roles?: list<int>}}  $details
     * @return array{employee: Employee, created: list<string>, skipped: list<string>, invitation: ?string, invitation_refused: ?string}
     */
    public function handle(User $actor, array $person, array $employee, array $position, ?int $managerId = null, ?string $reason = null, array $details = []): array
    {
        $startState = filled($details['start_state'] ?? null)
            ? (LifecycleState::tryFrom((string) $details['start_state']) ?? throw new ProfileChangeRefused('Unknown starting state.'))
            : null;
        if ($startState !== null && ! $actor->hasPermission('employee.lifecycle')) {
            throw new ProfileChangeRefused('Choosing the starting state needs employee.lifecycle.');
        }
        if (filled($position['position_id'] ?? null) && ! $actor->hasPermission('workforce.view') && ! $actor->hasPermission('workforce.manage')) {
            throw new ProfileChangeRefused('Seating the hire in a position needs workforce.view.');
        }
        $statutory = array_filter(array_intersect_key($details['statutory'] ?? [], array_flip(['pan', 'aadhaar_reference'])), fn ($v) => filled($v));
        $invite = $details['invite'] ?? null;
        $inviteInput = null;
        if ($invite !== null) {
            if (! $actor->hasPermission('user.create')) {
                throw new ProfileChangeRefused('Creating a sign-in needs user.create.');
            }
            $inviteInput = $this->invitations->prepare('new', ['roles' => $invite['roles'] ?? []], $actor);
        }

        // §23.2.3 #8: the returning-employee check, on the same details the hire is made with.
        $matches = $this->returning->matches($actor, isset($person['id']) ? [] : $person, $employee, $statutory);
        $confirmation = trim(strip_tags((string) ($details['returning_confirmation'] ?? '')));
        if ($this->returning->needsConfirmation($matches) && $confirmation === '') {
            throw new ProfileChangeRefused(ReturningEmployees::CONFIRMATION_REQUIRED);
        }

        $created = [];
        $skipped = [];
        $record = DB::transaction(function () use ($actor, $person, $employee, $position, $managerId, $reason, $details, $startState, $statutory, $matches, $confirmation, &$created, &$skipped) {
            $record = $this->hire->handle($person, $employee, $position, $managerId, $reason, $startState, $details['confirmation_date'] ?? null);
            $created[] = 'Employee '.$record->employee_code.($startState !== null ? ' ('.mb_strtolower($startState->getLabel()).')' : '');
            if (filled($position['position_id'] ?? null)) {
                $created[] = 'Position (seat)';
            }
            if ($managerId !== null) {
                $created[] = 'Line manager';
            }
            $joining = $record->joining_date;

            if ($this->returning->needsConfirmation($matches)) {
                $this->audit->record(AuditAction::Reviewed, 'employment', $record, [], 'Hired despite a match not eligible for rehire: '.mb_substr($confirmation, 0, 500), effectiveDate: $joining, metadata: $this->returning->auditMetadata($matches));
                $created[] = 'Returning-employee confirmation (audited)';
            }

            foreach (['functional_manager_id' => 'functional', 'hrbp_id' => 'hrbp'] as $key => $type) {
                if (filled($details[$key] ?? null)) {
                    $manager = Employee::query()->find((int) $details[$key]) ?? throw new ProfileChangeRefused('That '.config("peopleos.people.reporting_types.{$type}").' is not in your access.');
                    $this->managers->handle($record, $manager, $type, $joining, $reason);
                    $created[] = (string) config("peopleos.people.reporting_types.{$type}");
                }
            }

            $this->section($statutory !== [], 'Statutory identifiers', $created, $skipped, fn () => $this->statutory->handle($record, $statutory, $actor, $reason));
            $this->section(($details['addresses'] ?? []) !== [], 'Address', $created, $skipped, fn () => collect($details['addresses'])->each(fn (array $row) => $this->addresses->add($record, $row, $actor, $reason)), count($details['addresses'] ?? []));
            $this->section(($details['family'] ?? []) !== [], 'Family member', $created, $skipped, fn () => collect($details['family'])->each(fn (array $row) => $this->family->add($record, $row, $actor, $reason)), count($details['family'] ?? []));
            $this->section(filled($details['bank'] ?? null), 'Bank account', $created, $skipped, fn () => $this->bank->add($record, (array) $details['bank'], $actor, $reason));
            $this->section(($details['experiences'] ?? []) !== [], 'Previous employment', $created, $skipped, fn () => collect($details['experiences'])->each(fn (array $row) => $this->experiences->add($record, $row, $actor, $reason)), count($details['experiences'] ?? []));
            $this->section(($details['custom_fields'] ?? null) !== null, 'Other details', $created, $skipped, function () use ($record, $actor, $details, $reason) {
                if (Gate::forUser($actor)->denies('update', $record)) {
                    throw new ProfileChangeRefused('Other details need employee.update.');
                }
                try {
                    $this->customFields->store($record, (array) $details['custom_fields'], $reason);
                } catch (ValidationException $e) {
                    throw new ProfileChangeRefused(collect($e->errors())->flatten()->first() ?? 'The other details are not valid.');
                }
            });

            return $record;
        });

        $invitation = null;
        $refused = null;
        if ($inviteInput !== null) {
            try {
                // WO-16 exactly: user from the work e-mail, roles within the RoleGrants ceiling, UserInvitations::issue.
                $invitation = DB::transaction(fn () => $this->invitations->invite($record, $actor, $inviteInput, 'Hire wizard invitation'));
                $created[] = 'Sign-in and invitation';
            } catch (Throwable $e) {
                if (! $e instanceof RuntimeException) {
                    report($e);
                }
                $refused = $e instanceof RuntimeException ? $e->getMessage() : 'The invitation could not be sent.';
            }
        } else {
            $skipped[] = 'Sign-in';
        }

        return ['employee' => $record->refresh(), 'created' => $created, 'skipped' => $skipped, 'invitation' => $invitation, 'invitation_refused' => $refused];
    }

    /** @param  list<string>  $created  @param  list<string>  $skipped */
    private function section(bool $given, string $label, array &$created, array &$skipped, callable $apply, int $count = 1): void
    {
        if (! $given) {
            $skipped[] = $label;

            return;
        }
        $apply();
        $created[] = $count > 1 ? $label.' ×'.$count : $label;
    }
}
