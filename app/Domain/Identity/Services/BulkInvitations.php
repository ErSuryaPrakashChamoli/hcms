<?php

namespace App\Domain\Identity\Services;

use App\Domain\Employment\Models\Employee;
use App\Domain\Experience\Models\BulkRun;
use App\Domain\Experience\Models\BulkRunItem;
use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Identity\Jobs\SyncRoleMemberships;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Models\UserInvitation;
use App\Domain\Identity\Scopes\AccessScope;
use App\Domain\Lifecycle\Enums\LifecycleState;
use App\Domain\Notifications\Jobs\DeliverNotification;
use App\Domain\Notifications\Models\NotificationDelivery;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * HROne parity WO-16 (matrix 23 §23.9 #7–8, #10, #14, #17): invite many employees to sign in, or send many reset links.
 *
 * Two modes, each the existing single-user flow and nothing else (no second invitation mechanism):
 * - `new` ("Send to new user"): an employee without a sign-in gets a user created from their work e-mail (status
 *   invited, a password nobody knows — as Users → New user), linked to the employee, given the chosen roles through
 *   RoleMembers (user.assign_roles and the delegation ceiling, RoleGrants), and invited by TemporaryPasswords::email (a
 *   temporary password e-mailed directly, only its hash stored; they choose their own at the first sign-in). An employee
 *   whose user is still `invited` gets a new invitation (as "Send a new invitation"; the earlier one stops working).
 * - `reset` ("Reset for existing user"): an employee with an active sign-in is sent the password reset link by
 *   PasswordResets::sendNow (the same broker token and e-mail as "Send password reset link", sent at once so the result
 *   is known).
 * Refused with the reason: no (valid) work e-mail; an e-mail already used by another user; an employee who has left or is
 * suspended; outside the actor's access scope; an active sign-in asked for "new user", or no / an invited sign-in asked
 * for "reset"; a suspended sign-in; missing user.create (new user) or the user's `update` policy (resend / reset).
 *
 * CC (§23.9 #10): the invitation or reset e-mail itself goes only to the person. Each CC address gets a separate notice
 * through the notification delivery log (event `user.invitation_copied`, the tenant mail transport) saying that an
 * invitation / reset link was sent to whom and at which address — it never carries the link or its token. The notices
 * carry the bulk run's audit operation id as correlation id, so the run's delivery list can show them.
 *
 * Nothing here stores, logs or shows a link, token or password: the bulk run items keep "Invitation sent to <address>", the audit
 * trail keeps INVITATION_ISSUED / PASSWORD_RESET_REQUESTED as the single-user flows record them.
 */
final class BulkInvitations
{
    public const MODES = ['new' => 'Send to new user', 'reset' => 'Reset for existing user'];

    /** Bulk operation key => mode. */
    public const OPERATIONS = [
        'employees.invite_users' => 'new',
        'employees.send_reset_links' => 'reset',
        'users.resend_invitations' => 'new',
        'users.send_reset_links' => 'reset',
    ];

    public const CC_MAX = 5;

    public const CC_EVENT = 'user.invitation_copied';

    public function __construct(
        private readonly TemporaryPasswords $temporaryPasswords,
        private readonly PasswordResets $resets,
        private readonly AccessScopes $scopes,
        private readonly RoleMembers $members,
        private readonly RoleGrants $grants,
        private readonly RoleMembershipSync $ruleSync,
        private readonly TenantContext $tenants,
    ) {}

    /**
     * The run's inputs, checked once (the bulk operation's prepare()).
     *
     * @param  array<string, mixed>  $data
     * @return array{cc: list<string>, roles: list<int>}
     */
    public function prepare(string $mode, array $data, User $actor): array
    {
        $roles = $mode === 'new' ? array_values(array_unique(array_filter(array_map('intval', (array) ($data['roles'] ?? []))))) : [];
        if ($roles !== []) {
            if (! $actor->hasPermission('user.assign_roles')) {
                throw new RuntimeException('Giving roles needs the user.assign_roles permission.');
            }
            $found = Role::query()->whereKey($roles)->get();
            if ($found->count() !== count($roles)) {
                throw new RuntimeException('Choose roles of this organisation.');
            }
            $refused = $this->grants->refused($actor, $roles);
            if ($refused->isNotEmpty()) {
                throw new RuntimeException($this->grants->refusal($refused->first()));
            }
        }

        return ['cc' => self::ccAddresses($data['cc'] ?? []), 'roles' => $roles];
    }

    /** @return list<string> the CC addresses, lower-cased, unique; a RuntimeException for a malformed one or too many */
    public static function ccAddresses(mixed $value): array
    {
        $parts = is_array($value) ? $value : (preg_split('/[\s,;]+/', (string) $value) ?: []);
        $out = [];
        foreach ($parts as $part) {
            $address = mb_strtolower(trim((string) $part));
            if ($address === '') {
                continue;
            }
            if (mb_strlen($address) > 191 || filter_var($address, FILTER_VALIDATE_EMAIL) === false) {
                throw new RuntimeException('"'.Str::limit($address, 60).'" is not an e-mail address.');
            }
            $out[$address] = $address;
        }
        if (count($out) > self::CC_MAX) {
            throw new RuntimeException('Copy at most '.self::CC_MAX.' addresses.');
        }

        return array_values($out);
    }

    /** @return list<int> the roles offered by default to a new user: the Employee role, when the actor may give it */
    public function defaultRoleIds(User $actor): array
    {
        if (! $actor->hasPermission('user.assign_roles')) {
            return [];
        }
        $role = Role::query()->where('slug', 'employee')->first();

        return $role !== null && $this->grants->canAssign($actor, $role) ? [(int) $role->getKey()] : [];
    }

    /** Why this employee cannot be invited / sent a reset link in this mode (null = can). */
    public function refusal(Employee $employee, string $mode, User $actor): ?string
    {
        if (! array_key_exists($mode, self::MODES)) {
            return 'Unknown invitation mode.';
        }
        if (! $this->scopes->allows($actor, $employee)) {
            return 'Outside your access scope.';
        }
        if (in_array($employee->lifecycle_state, [LifecycleState::Exited, LifecycleState::Alumni], true)) {
            return 'Has left the organisation.';
        }
        if ($employee->lifecycle_state === LifecycleState::Suspended) {
            return 'Is suspended.';
        }
        if ($employee->lifecycle_state === LifecycleState::NotJoined) {
            return 'Did not join.';
        }
        $user = $this->userOf($employee);
        if ($mode === 'reset') {
            return match (true) {
                $user === null => 'Has no sign-in yet: use "'.self::MODES['new'].'".',
                default => $this->userRefusal($user, 'reset', $actor, $employee),
            };
        }
        if ($user !== null) {
            return $user->status === UserStatus::Active
                ? 'Already has an active sign-in: use "'.self::MODES['reset'].'".'
                : $this->userRefusal($user, 'new', $actor, $employee);
        }
        if (! $actor->hasPermission('user.create')) {
            return 'You cannot create users.';
        }
        $email = $this->workEmail($employee);
        if ($email === null) {
            return 'No work e-mail on file.';
        }
        if (User::query()->whereRaw('lower(email) = ?', [$email])->exists()) {
            return 'The work e-mail '.$email.' is already used by another user.';
        }

        return null;
    }

    /** Why this user cannot be sent a new invitation (`new`) or a reset link (`reset`) (null = can). */
    public function userRefusal(User $user, string $mode, User $actor, ?Employee $employee = null): ?string
    {
        if ($user->is_platform_admin || $user->tenant_id === null || (int) $user->tenant_id !== (int) $this->tenants->id()) {
            return 'Not a user of this organisation.';
        }
        $employee ??= $this->employeeOf($user);
        if ($employee !== null) {
            if (! $this->scopes->allows($actor, $employee)) {
                return 'Outside your access scope.';
            }
            if (in_array($employee->lifecycle_state, [LifecycleState::Exited, LifecycleState::Alumni], true)) {
                return 'Has left the organisation.';
            }
            if ($employee->lifecycle_state === LifecycleState::NotJoined) {
                return 'Did not join.';
            }
        }
        $wrongState = match (true) {
            $user->status === UserStatus::Suspended => 'The sign-in is suspended.',
            $mode === 'new' && $user->status !== UserStatus::Invited => 'Already has an active sign-in: use "'.self::MODES['reset'].'".',
            $mode === 'reset' && $user->status === UserStatus::Invited => 'Has not accepted the invitation yet: use "'.self::MODES['new'].'" to send a new invitation.',
            $mode === 'reset' && ! $this->resets->mayReset($user) => 'This sign-in cannot reset its password now.',
            default => null,
        };
        if ($wrongState !== null) {
            return $wrongState;
        }
        if (Gate::forUser($actor)->denies('update', $user)) {
            return $mode === 'new' ? 'You cannot send a new invitation to this user.' : 'You cannot send a reset link to this user.';
        }

        return null;
    }

    /**
     * "Send to new user" for one employee (a new user, or a new invitation for one still invited).
     *
     * @param  array{cc?: list<string>, roles?: list<int>}  $input
     * @param  string  $context  what the audit trail names as the source (HROne parity WO-3: "Hire wizard invitation")
     */
    public function invite(Employee $employee, User $actor, array $input = [], string $context = 'Bulk invitation'): string
    {
        if (($refusal = $this->refusal($employee, 'new', $actor)) !== null) {
            throw new RuntimeException($refusal);
        }
        $user = $this->userOf($employee);
        $created = $user === null;
        if ($user === null) {
            $employee->loadMissing('person');
            $user = User::query()->create([
                'tenant_id' => $this->tenants->id(),
                'name' => mb_substr((string) ($employee->person?->display_name ?: $employee->employee_code), 0, 255),
                'email' => (string) $this->workEmail($employee),
                // SaaS.2: as Users → New user — invited, with a password nobody knows; the invitee chooses their own.
                'password' => Str::random(64),
                'status' => UserStatus::Invited,
                'is_platform_admin' => false,
            ]);
            $employee->forceFill(['user_id' => $user->getKey()])->withAuditReason($context.': sign-in created')->save();
            foreach (Role::query()->whereKey($input['roles'] ?? [])->get() as $role) {
                $this->members->add($role, $user, null, $actor, $context);
            }
            if ($this->ruleSync->hasRules()) {
                SyncRoleMemberships::dispatch([(int) $employee->getKey()])->afterCommit();
            }
        }
        $this->temporaryPasswords->email($user, $actor, $created ? $context : $context.': new invitation');
        $copies = $this->copy('new', $user, $employee, $actor, $input['cc'] ?? [], $employee);

        return ($created ? 'Sign-in created; invitation sent to ' : 'New invitation sent to ').$user->email.$this->copyNote($copies);
    }

    /**
     * "Reset for existing user" for one employee.
     *
     * @param  array{cc?: list<string>}  $input
     */
    public function sendReset(Employee $employee, User $actor, array $input = []): string
    {
        if (($refusal = $this->refusal($employee, 'reset', $actor)) !== null) {
            throw new RuntimeException($refusal);
        }

        return $this->resetFor($this->userOf($employee) ?? throw new RuntimeException('Has no sign-in yet.'), $employee, $actor, $input['cc'] ?? [], $employee);
    }

    /**
     * From the Users list: a new invitation (`new`) or a reset link (`reset`) for one user.
     *
     * @param  array{cc?: list<string>}  $input
     */
    public function forUser(User $user, string $mode, User $actor, array $input = []): string
    {
        $employee = $this->employeeOf($user);
        if (($refusal = $this->userRefusal($user, $mode, $actor, $employee)) !== null) {
            throw new RuntimeException($refusal);
        }
        if ($mode === 'reset') {
            return $this->resetFor($user, $employee, $actor, $input['cc'] ?? [], $user);
        }
        $this->temporaryPasswords->email($user, $actor, 'Bulk invitation: new invitation');

        return 'New invitation sent to '.$user->email.$this->copyNote($this->copy('new', $user, $employee, $actor, $input['cc'] ?? [], $user));
    }

    /**
     * §23.9 #14: one line per record of an invitation / reset run — code, name, e-mail, e-mail status and the CC notices.
     * Never a link or a token (none is stored anywhere to show).
     *
     * @return list<array{item_id: int, code: string, name: string, email: string, status: string, detail: string, cc: string}>
     */
    public function deliveryRows(BulkRun $run): array
    {
        $mode = self::OPERATIONS[$run->operation] ?? null;
        if ($mode === null) {
            return [];
        }
        $items = BulkRunItem::query()->where('bulk_run_id', $run->getKey())->orderBy('id')->get();
        $byUser = str_starts_with($run->operation, 'users.');
        $ids = $items->pluck('record_id')->map(fn ($id) => (int) $id)->all();
        $employees = $byUser
            ? Employee::query()->with('person')->whereIn('user_id', $ids)->get()->keyBy(fn (Employee $e) => (int) $e->user_id)
            : Employee::query()->with('person')->whereKey($ids)->get()->keyBy(fn (Employee $e) => (int) $e->getKey());
        $userIds = $byUser ? $ids : $employees->pluck('user_id')->filter()->map(fn ($id) => (int) $id)->all();
        $users = User::query()->forCurrentTenant()->whereKey($userIds)->get()->keyBy(fn (User $u) => (int) $u->getKey());
        $invitations = $mode === 'new' ? UserInvitation::query()->whereIn('user_id', $userIds)->orderBy('id')->get()->groupBy('user_id') : collect();
        $copies = $run->operation_id === null ? collect() : NotificationDelivery::query()->where('event', self::CC_EVENT)->where('correlation_id', $run->operation_id)
            ->where('source_type', ($byUser ? new User : new Employee)->getMorphClass())
            ->orderBy('id')->get(['source_type', 'source_id', 'status', 'recipient'])->groupBy(fn (NotificationDelivery $d) => (int) $d->source_id);

        $rows = [];
        foreach ($items as $item) {
            $recordId = (int) $item->record_id;
            $employee = $employees->get($recordId);
            $user = $byUser ? $users->get($recordId) : ($employee?->user_id ? $users->get((int) $employee->user_id) : null);
            [$code, $name] = $this->who($item, $employee, $user);
            $status = match ($item->outcome) {
                'refused' => 'Not sent',
                'failed' => 'Failed',
                default => $mode === 'reset' ? 'Reset link sent' : $this->invitationState($invitations->get((int) $user?->getKey()), $item, $user),
            };
            $cc = $copies->get($recordId, collect())
                ->map(fn (NotificationDelivery $d) => $d->recipient.' ('.$d->status.')')->implode(', ');
            $rows[] = [
                'item_id' => (int) $item->getKey(),
                'code' => $code,
                'name' => $name,
                'email' => (string) ($user?->email ?? $employee?->work_email ?? ''),
                'status' => $status,
                'detail' => $item->outcome === 'done' ? '' : (string) $item->message,
                'cc' => $cc,
            ];
        }

        return $rows;
    }

    /** The delivery list as CSV (cells that a spreadsheet would run as a formula are quoted). */
    public function deliveryCsv(BulkRun $run): string
    {
        $out = fopen('php://temp', 'r+');
        fputcsv($out, ['Employee code', 'Employee name', 'E-mail', 'E-mail status', 'Detail', 'CC notices'], escape: '\\');
        foreach ($this->deliveryRows($run) as $row) {
            fputcsv($out, array_map(fn ($v) => self::cell((string) $v), [$row['code'], $row['name'], $row['email'], $row['status'], $row['detail'], $row['cc']]), escape: '\\');
        }
        rewind($out);
        $csv = (string) stream_get_contents($out);
        fclose($out);

        return $csv;
    }

    public static function isInvitationRun(BulkRun $run): bool
    {
        return array_key_exists($run->operation, self::OPERATIONS);
    }

    private static function cell(string $text): string
    {
        return $text !== '' && in_array($text[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$text : $text;
    }

    /** @param  list<string>  $cc */
    private function resetFor(User $user, ?Employee $employee, User $actor, array $cc, Model $source): string
    {
        if (! $this->resets->sendNow($user)) {
            throw new RuntimeException('A reset link was sent to this person moments ago; try again in a few minutes.');
        }

        return 'Reset link sent to '.$user->email.$this->copyNote($this->copy('reset', $user, $employee, $actor, $cc, $source));
    }

    /**
     * The separate CC notice (never the invitation or reset e-mail itself, never its link).
     *
     * @param  list<string>  $cc
     * @param  Model  $source  the run's record (the employee, or the user on the Users list): the delivery list finds the notice by it
     */
    private function copy(string $mode, User $user, ?Employee $employee, User $actor, array $cc, Model $source): int
    {
        $own = mb_strtolower((string) $user->email);
        $cc = array_values(array_filter(self::ccAddresses($cc), fn (string $address) => $address !== $own));
        if ($cc === [] || ! config('peopleos.notifications.channels.email')) {
            return 0;
        }
        $who = $employee !== null ? trim($employee->auditLabel(), ' ·') : $user->name;
        $organisation = (string) ($user->tenant()->value('name') ?? 'Your organisation');
        [$subject, $body] = $mode === 'new'
            ? ['Invitation sent to '.$who, "{$organisation} sent {$who} an invitation to sign in to PeopleOS, at {$user->email}.\n\nThe temporary password went only to them; this copy carries no password.\n\nSent by {$actor->name}."]
            : ['Password reset link sent to '.$who, "{$organisation} sent {$who} a link to choose a new PeopleOS password, at {$user->email}.\n\nThe link went only to them; this copy carries no link.\n\nSent by {$actor->name}."];
        $operation = Context::get('audit.operation_id');
        foreach ($cc as $address) {
            $delivery = NotificationDelivery::query()->create([
                'user_id' => null,
                'channel' => 'email',
                'event' => self::CC_EVENT,
                'subject' => mb_substr($subject, 0, 255),
                'body' => $body,
                'status' => 'queued',
                'recipient' => $address,
                'source_type' => $source->getMorphClass(),
                'source_id' => $source->getKey(),
                'correlation_id' => $operation ?? Context::get('request_id'),
                'dedupe_key' => $operation === null ? null : 'wo16:'.substr(hash('sha256', $operation.'|'.$source->getMorphClass().'|'.$source->getKey().'|'.$address), 0, 40),
            ]);
            DeliverNotification::dispatch($delivery->getKey())->afterCommit();
        }

        return count($cc);
    }

    private function copyNote(int $copies): string
    {
        return $copies > 0 ? '; CC notice to '.$copies.' address(es)' : '';
    }

    /** @return array{0: string, 1: string} code, name */
    private function who(BulkRunItem $item, ?Employee $employee, ?User $user): array
    {
        if ($employee !== null) {
            return [(string) $employee->employee_code, (string) ($employee->person?->display_name ?? '')];
        }
        // Outside the viewer's access now, or no employee: the run's own label ("E001 · Asha Rao" / "Name · e-mail").
        $parts = array_map('trim', explode(' · ', (string) $item->label, 2));

        return $user !== null ? ['', (string) $user->name] : [(string) ($parts[0] ?? ''), (string) ($parts[1] ?? '')];
    }

    /** @param  Collection<int, UserInvitation>|null  $invitations */
    private function invitationState($invitations, BulkRunItem $item, ?User $user = null): string
    {
        // A temporary password (the invitation since 2026-10-31) is waiting until the person chooses their own.
        if ($user !== null && $user->mustReplacePassword()) {
            return $user->holdsTemporaryPassword() ? 'Sent — waiting for the first sign-in' : 'Expired';
        }
        // The invitation this run issued: the latest one created up to the moment the record's outcome was written.
        $invitation = $invitations?->filter(fn (UserInvitation $i) => $i->created_at === null || $item->created_at === null || $i->created_at->lte($item->created_at))->last();

        return match (true) {
            $invitation === null => $user?->status === UserStatus::Active ? 'Accepted' : 'Sent',
            $invitation->accepted_at !== null => 'Accepted',
            $invitation->revoked_at !== null => 'Replaced or revoked',
            $invitation->expires_at->isPast() => 'Expired',
            default => 'Sent — waiting for acceptance',
        };
    }

    private function userOf(Employee $employee): ?User
    {
        return $employee->user_id ? User::query()->find($employee->user_id) : null;
    }

    private function employeeOf(User $user): ?Employee
    {
        return Employee::query()->withoutGlobalScope(AccessScope::class)->where('user_id', $user->getKey())->first();
    }

    private function workEmail(Employee $employee): ?string
    {
        $email = mb_strtolower(trim((string) $employee->work_email));

        return $email !== '' && mb_strlen($email) <= 255 && filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? $email : null;
    }
}
