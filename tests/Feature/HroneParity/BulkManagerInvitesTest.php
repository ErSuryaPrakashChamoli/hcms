<?php

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Employment\Actions\ChangeManagerAction;
use App\Domain\Employment\Actions\HireEmployeeAction;
use App\Domain\Employment\Models\Employee;
use App\Domain\Employment\Models\ReportingRelationship;
use App\Domain\Employment\Services\EmployeeSelection;
use App\Domain\Exit\Services\Exits;
use App\Domain\Experience\Models\BulkRun;
use App\Domain\Experience\Models\BulkRunItem;
use App\Domain\Experience\Services\BulkOperations;
use App\Domain\Experience\Services\BulkRuns;
use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Notifications\PasswordResetLink;
use App\Domain\Identity\Notifications\TemporaryPasswordMail;
use App\Domain\Identity\Services\AccessScopes;
use App\Domain\Identity\Services\BulkInvitations;
use App\Domain\Identity\Services\TemporaryPasswords;
use App\Domain\Lifecycle\Enums\LifecycleState;
use App\Domain\Notifications\Models\NotificationDelivery;
use App\Domain\Organisation\Models\Company;
use App\Domain\Organisation\Models\Location;
use App\Domain\Workflow\Enums\TaskStatus;
use App\Domain\Workflow\Models\WorkflowTask;
use App\Domain\Workflow\Services\WorkflowEngine;
use App\Filament\Pages\WorkforceDesk;
use App\Filament\Resources\BulkRuns\Pages\ViewBulkRun;
use App\Filament\Resources\BulkRuns\RelationManagers\DeliveryListRelationManager;
use App\Filament\Resources\Employees\EmployeeResource;
use App\Filament\Resources\Employees\Pages\ListEmployees;
use App\Filament\Resources\Employees\Pages\ViewEmployee;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Support\Tenancy\TenantContext;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Livewire\Livewire;

require_once __DIR__.'/../Workflow/WorkflowTestHelpers.php';

/*
| HROne parity WO-16 (matrix 23 §23.9 #4–5, #7–8, #10, #14, #17; §23.6.3 #13 the bulk part): bulk change manager with an
| effective date and history, the optional move of manager-resolved open approvals, bulk invitations (new user / reset),
| CC notices that never carry the link, the delivery list, the wizards and the access scope.
*/

beforeEach(function () {
    $this->travelTo('2026-10-20 10:00:00');
    $this->tenant = provisionTenant();
    actAsTenant($this->tenant);
    $this->hr = tenantUser($this->tenant, ['*']);
    $this->actingAs($this->hr);
    Filament::getPanel('admin')->boot();
    $this->company = Company::factory()->create();
    $this->delhi = Location::factory()->create(['company_id' => $this->company->id, 'name' => 'Delhi']);
    $this->mumbai = Location::factory()->create(['company_id' => $this->company->id, 'name' => 'Mumbai']);
    $this->runs = app(BulkRuns::class);
});

function wo16Hire(string $first, Location $location, ?Employee $manager = null, array $employment = []): Employee
{
    return app(HireEmployeeAction::class)->handle(['first_name' => $first, 'last_name' => 'Test'], $employment + ['joining_date' => '2025-01-01'],
        ['company_id' => $location->company_id, 'location_id' => $location->id], $manager?->id);
}

/** Give an employee an active (or other) sign-in. */
function wo16Login(Employee $employee, array $permissions = ['task.view', 'task.act'], UserStatus $status = UserStatus::Active): User
{
    $user = tenantUser(app(TenantContext::class)->current(), $permissions, ['status' => $status]);
    $employee->forceFill(['user_id' => $user->id])->save();

    return $user;
}

/** @return array<int, BulkRunItem> */
function wo16Items(BulkRun $run): array
{
    return BulkRunItem::query()->where('bulk_run_id', $run->id)->get()->keyBy('record_id')->all();
}

function wo16Approval(Employee $subject, array $approver): WorkflowTask
{
    [$nodes, $edges] = linear([['id' => 'approve', 'type' => 'approval', 'name' => 'Approve', 'config' => ['mode' => 'single', 'approver' => $approver]]]);

    return app(WorkflowEngine::class)->start(publishWorkflow($nodes, $edges), $subject)->tasks()->sole();
}

it('changes nothing by default: no new permission, the single change as before, no delivery list on other runs', function () {
    $lead = wo16Hire('Lead', $this->delhi);
    $asha = wo16Hire('Asha', $this->delhi, $lead);
    $newLead = wo16Hire('Nina', $this->delhi);
    foreach (['employees.change_manager', 'employees.invite_users', 'employees.send_reset_links', 'users.resend_invitations', 'users.send_reset_links'] as $key) {
        expect(app(BulkOperations::class)->find($key))->not->toBeNull();
    }
    expect(app(BulkOperations::class)->get('employees.change_manager')->permission())->toBe('employee.position')
        ->and(app(BulkOperations::class)->get('employees.invite_users')->permission())->toBe('user.create')
        ->and(app(BulkOperations::class)->get('employees.send_reset_links')->permission())->toBe('user.update');

    // The one-person change on the Employee 360 is untouched.
    Livewire::test(ViewEmployee::class, ['record' => $asha->getRouteKey()])
        ->callAction('changeManager', ['type' => 'line', 'manager_id' => $newLead->id, 'effective_from' => '2026-10-20'])
        ->assertHasNoActionErrors();
    expect($asha->refresh()->currentManager->manager_id)->toBe($newLead->id)->and(BulkRun::query()->count())->toBe(0);

    // Without bulk.run nobody sees the wizards; people who may not run them see no desk entry.
    $viewer = tenantUser($this->tenant, ['employee.view', 'employee.position', 'user.create', 'user.update', 'exit.view']);
    $this->actingAs($viewer);
    Livewire::test(ListEmployees::class)->assertActionHidden('bulkChangeManager')->assertActionHidden('bulkInvite')->assertActionHidden('bulkResetLinks');
    $this->get(WorkforceDesk::getUrl())->assertOk()->assertDontSee('action=bulkChangeManager', false)->assertDontSee('action=bulkInvite', false);

    // Runs of other operations show no delivery list.
    $this->actingAs($this->hr);
    $run = $this->runs->start($this->hr, 'employees', 'employees.initiate_exit', [$asha->id], ['type' => 'resignation']);
    expect(DeliveryListRelationManager::canViewForRecord($run, ViewBulkRun::class))->toBeFalse();
});

it('moves a manager\'s reporting team from an effective date, keeping the earlier line as history', function () {
    $lead = wo16Hire('Lead', $this->delhi);
    $asha = wo16Hire('Asha', $this->delhi, $lead);
    $ravi = wo16Hire('Ravi', $this->delhi, $lead);
    $other = wo16Hire('Other', $this->delhi);
    $nina = wo16Hire('Nina', $this->delhi);
    $ids = app(EmployeeSelection::class)->ids(['source' => 'team', 'team_of' => $lead->id, 'team_type' => 'line'], 100);
    expect($ids)->toEqualCanonicalizing([$asha->id, $ravi->id]);

    $run = $this->runs->start($this->hr, 'employees', 'employees.change_manager', $ids, ['manager_id' => $nina->id, 'type' => 'line', 'effective_from' => '2026-11-01', 'reason' => 'Re-organisation']);

    expect($run->done)->toBe(2)->and($run->refused)->toBe(0);
    $rows = ReportingRelationship::query()->where('employee_id', $asha->id)->where('type', 'line')->orderBy('effective_from')->get();
    expect($rows)->toHaveCount(2)
        ->and($rows[0]->manager_id)->toBe($lead->id)->and($rows[0]->effective_from->toDateString())->toBe('2025-01-01')->and($rows[0]->effective_to->toDateString())->toBe('2026-10-31')
        ->and($rows[1]->manager_id)->toBe($nina->id)->and($rows[1]->effective_from->toDateString())->toBe('2026-11-01')->and($rows[1]->effective_to)->toBeNull()
        // Until the date the old line holds; from it, the new one.
        ->and($asha->refresh()->currentManager->manager_id)->toBe($lead->id)
        ->and(ReportingRelationship::query()->where('employee_id', $asha->id)->where('is_primary', true)->effectiveOn('2026-11-02')->value('manager_id'))->toBe($nina->id)
        ->and(ReportingRelationship::query()->where('employee_id', $other->id)->count())->toBe(0);
    expect(AuditEvent::query()->withoutGlobalScopes()->where('action', AuditAction::ManagerChanged->value)->where('operation_id', $run->operation_id)->count())->toBe(2)
        ->and(wo16Items($run)[$asha->id]->message)->toContain('Now reports to')->toContain('1 Nov 2026');
});

it('refuses each person who cannot move, with the reason, and never rewrites history', function () {
    $lead = wo16Hire('Lead', $this->delhi);
    $asha = wo16Hire('Asha', $this->delhi, $lead);
    $nina = wo16Hire('Nina', $this->delhi);
    $loop = wo16Hire('Loop', $this->delhi, $asha);        // reports to Asha
    $deep = wo16Hire('Deep', $this->delhi, $loop);         // reports to Asha through Loop
    $already = wo16Hire('Already', $this->delhi, $nina);
    $later = wo16Hire('Later', $this->delhi, $lead);
    app(ChangeManagerAction::class)->handle($later, $asha, 'line', '2026-12-01', 'Planned');
    $today = wo16Hire('Today', $this->delhi, $lead, ['joining_date' => '2026-10-20']);
    $gone = forceLifecycle(wo16Hire('Gone', $this->delhi, $lead), LifecycleState::Exited);

    $run = $this->runs->start($this->hr, 'employees', 'employees.change_manager', [$asha->id, $nina->id, $already->id, $later->id, $today->id, $gone->id],
        ['manager_id' => $nina->id, 'type' => 'line', 'effective_from' => '2026-10-20', 'reason' => 'Moves']);
    $items = wo16Items($run);
    expect($items[$asha->id]->outcome)->toBe('done')
        ->and($items[$nina->id]->message)->toBe('An employee cannot be their own manager.')
        ->and($items[$already->id]->message)->toContain('Already reports to')
        ->and($items[$later->id]->message)->toContain('A later change of this reporting line is already recorded (from 1 Dec 2026)')
        ->and($items[$today->id]->message)->toContain('recorded from that same day')
        ->and($items[$gone->id]->message)->toBe('Has left the organisation.');
    // The refused people's history is exactly as it was.
    expect(ReportingRelationship::query()->where('employee_id', $later->id)->orderBy('effective_from')->get(['manager_id', 'effective_from', 'effective_to'])->map(fn ($r) => [$r->manager_id, $r->effective_from->toDateString(), $r->effective_to?->toDateString()])->all())
        ->toBe([[$lead->id, '2025-01-01', '2026-11-30'], [$asha->id, '2026-12-01', null]])
        ->and(ReportingRelationship::query()->where('employee_id', $today->id)->sole()->effective_to)->toBeNull();

    // A loop: the new manager reports to the person, directly or through others.
    $run = $this->runs->start($this->hr, 'employees', 'employees.change_manager', [$asha->id], ['manager_id' => $deep->id, 'type' => 'line', 'effective_from' => '2026-10-21', 'reason' => 'Loop']);
    expect(wo16Items($run)[$asha->id]->message)->toContain('reports to this employee (directly or through others)');

    // A new manager who has left, has not joined or is suspended is refused for the whole run.
    forceLifecycle($lead, LifecycleState::Exited);
    $starter = forceLifecycle(wo16Hire('Starter', $this->delhi), LifecycleState::Preboarding);
    expect(fn () => $this->runs->start($this->hr, 'employees', 'employees.change_manager', [$asha->id], ['manager_id' => $lead->id, 'type' => 'line', 'effective_from' => '2026-10-21', 'reason' => 'x']))
        ->toThrow(RuntimeException::class, 'has left the organisation')
        ->and(fn () => $this->runs->start($this->hr, 'employees', 'employees.change_manager', [$asha->id], ['manager_id' => $starter->id, 'type' => 'line', 'effective_from' => '2026-10-21', 'reason' => 'x']))
        ->toThrow(RuntimeException::class, 'has not joined yet')
        ->and(fn () => $this->runs->start($this->hr, 'employees', 'employees.change_manager', [$asha->id], ['manager_id' => $nina->id, 'type' => 'line', 'effective_from' => '2026-10-21', 'reason' => '']))
        ->toThrow(RuntimeException::class, 'Give the reason');
});

it('moves people only inside the actor\'s access scope, and refuses a new manager outside it', function () {
    $nina = wo16Hire('Nina', $this->delhi);
    $asha = wo16Hire('Asha', $this->delhi, null, ['work_email' => 'asha@acme.test']);
    $vikram = wo16Hire('Vikram', $this->mumbai, null, ['work_email' => 'vikram@acme.test']);
    $mumbaiLead = wo16Hire('Mumbai Lead', $this->mumbai);
    $delhiHr = tenantUser($this->tenant, ['*']);
    app(AccessScopes::class)->assign($delhiHr, ['location' => [$this->delhi->id]]);
    $this->actingAs($delhiHr);

    $run = $this->runs->start($delhiHr, 'employees', 'employees.change_manager', [$asha->id, $vikram->id], ['manager_id' => $nina->id, 'type' => 'line', 'effective_from' => '2026-10-20', 'reason' => 'Scope']);
    $items = wo16Items($run);
    expect($items[$asha->id]->outcome)->toBe('done')
        ->and($items[$vikram->id]->outcome)->toBe('refused')
        ->and(ReportingRelationship::query()->withoutGlobalScopes()->where('employee_id', $vikram->id)->count())->toBe(0)
        ->and(fn () => $this->runs->start($delhiHr, 'employees', 'employees.change_manager', [$asha->id], ['manager_id' => $mumbaiLead->id, 'type' => 'line', 'effective_from' => '2026-10-21', 'reason' => 'x']))
        ->toThrow(RuntimeException::class, 'Choose the new manager');

    // Invitations too: the Mumbai employee is never invited.
    Notification::fake();
    $run = $this->runs->start($delhiHr, 'employees', 'employees.invite_users', [$asha->id, $vikram->id]);
    expect(wo16Items($run)[$vikram->id]->outcome)->toBe('refused')->and($vikram->refresh()->user_id)->toBeNull()
        ->and(wo16Items($run)[$asha->id]->outcome)->toBe('done');
    expect(app(BulkInvitations::class)->refusal($vikram, 'new', $delhiHr))->toBe('Outside your access scope.');
});

it('leaves open approvals where they are unless asked', function () {
    $lead = wo16Hire('Lead', $this->delhi);
    wo16Login($lead);
    $asha = wo16Hire('Asha', $this->delhi, $lead);
    wo16Login($asha);
    $nina = wo16Hire('Nina', $this->delhi);
    wo16Login($nina);
    $task = wo16Approval($asha, ['type' => 'manager']);
    expect($task->assignee_id)->toBe($lead->user_id);

    $this->runs->start($this->hr, 'employees', 'employees.change_manager', [$asha->id], ['manager_id' => $nina->id, 'type' => 'line', 'effective_from' => '2026-10-20', 'reason' => 'Moves']);

    expect($task->refresh()->assignee_id)->toBe($lead->user_id)->and($task->status)->toBe(TaskStatus::Pending);
});

it('moves only the approvals the old manager holds as the moved person\'s manager, when asked', function () {
    $lead = wo16Hire('Lead', $this->delhi);
    $leadUser = wo16Login($lead);
    $asha = wo16Hire('Asha', $this->delhi, $lead);
    wo16Login($asha);
    $ravi = wo16Hire('Ravi', $this->delhi, $lead);
    wo16Login($ravi);
    $nina = wo16Hire('Nina', $this->delhi);
    $ninaUser = wo16Login($nina);
    $other = tenantUser($this->tenant, ['task.view', 'task.act']);

    $asManager = wo16Approval($asha, ['type' => 'manager']);
    $named = wo16Approval($asha, ['type' => 'user', 'user_id' => $leadUser->id]);           // held as a named approver
    $notMoved = wo16Approval($ravi, ['type' => 'manager']);                                  // someone else's request
    $handedBack = wo16Approval($asha, ['type' => 'manager']);                                // reassigned away and back
    app(WorkflowEngine::class)->reassign($handedBack, $other, null, 'Cover', $this->hr);
    app(WorkflowEngine::class)->reassign($handedBack->refresh(), $leadUser, null, 'Back again', $this->hr);

    // A future-dated change cannot move approvals; nor without task.reassign.
    expect(fn () => $this->runs->start($this->hr, 'employees', 'employees.change_manager', [$asha->id], ['manager_id' => $nina->id, 'type' => 'line', 'effective_from' => '2026-11-01', 'reason' => 'x', 'reassign_tasks' => true]))
        ->toThrow(RuntimeException::class, 'effective today or earlier');
    $noReassign = tenantUser($this->tenant, ['employee.view', 'employee.position', 'bulk.run']);
    expect(fn () => $this->runs->start($noReassign, 'employees', 'employees.change_manager', [$asha->id], ['manager_id' => $nina->id, 'type' => 'line', 'effective_from' => '2026-10-20', 'reason' => 'x', 'reassign_tasks' => true]))
        ->toThrow(RuntimeException::class, 'task.reassign');

    $run = $this->runs->start($this->hr, 'employees', 'employees.change_manager', [$asha->id], ['manager_id' => $nina->id, 'type' => 'line', 'effective_from' => '2026-10-20', 'reason' => 'Lead leaves', 'reassign_tasks' => true]);

    expect($asManager->refresh()->assignee_id)->toBe($ninaUser->id)
        ->and($named->refresh()->assignee_id)->toBe($leadUser->id)
        ->and($notMoved->refresh()->assignee_id)->toBe($leadUser->id)
        ->and($handedBack->refresh()->assignee_id)->toBe($leadUser->id)
        ->and(wo16Items($run)[$asha->id]->message)->toContain('1 open approval(s) moved');
    $assign = AuditEvent::query()->withoutGlobalScopes()->where('action', AuditAction::Assign->value)->where('operation_id', $run->operation_id)->sole();
    expect($assign->reason)->toBe('Manager change: Lead leaves')->and($assign->metadata['task_id'])->toBe($asManager->id);
});

it('invites employees in "new user" mode and sends reset links in "reset" mode, refusing with the reason', function () {
    Notification::fake();
    $employeeRole = Role::query()->where('slug', 'employee')->sole();
    $asha = wo16Hire('Asha', $this->delhi, null, ['work_email' => 'Asha@Acme.test']);
    $noMail = wo16Hire('Nomail', $this->delhi);
    tenantUser($this->tenant, [], ['email' => 'taken@acme.test']);
    $taken = wo16Hire('Taken', $this->delhi, null, ['work_email' => 'TAKEN@acme.test']);
    $gone = forceLifecycle(wo16Hire('Gone', $this->delhi, null, ['work_email' => 'gone@acme.test']), LifecycleState::Exited);
    $active = wo16Hire('Active', $this->delhi, null, ['work_email' => 'active@acme.test']);
    $activeUser = wo16Login($active);
    $invited = wo16Hire('Invited', $this->delhi, null, ['work_email' => 'invited@acme.test']);
    $invitedUser = wo16Login($invited, [], UserStatus::Invited);

    $run = $this->runs->start($this->hr, 'employees', 'employees.invite_users', [$asha->id, $noMail->id, $taken->id, $gone->id, $active->id, $invited->id], ['roles' => [$employeeRole->id]]);
    $items = wo16Items($run);
    $ashaUser = User::query()->findOrFail($asha->refresh()->user_id);
    expect($items[$asha->id]->message)->toBe('Sign-in created; invitation sent to asha@acme.test')
        ->and($ashaUser->status)->toBe(UserStatus::Invited)->and($ashaUser->email)->toBe('asha@acme.test')->and($ashaUser->tenant_id)->toBe($this->tenant->id)
        ->and($ashaUser->roles()->pluck('slug')->all())->toBe(['employee'])
        ->and($items[$noMail->id]->message)->toBe('No work e-mail on file.')
        ->and($items[$taken->id]->message)->toBe('The work e-mail taken@acme.test is already used by another user.')
        ->and($items[$gone->id]->message)->toBe('Has left the organisation.')
        ->and($items[$active->id]->message)->toBe('Already has an active sign-in: use "Reset for existing user".')
        ->and($items[$invited->id]->message)->toBe('New invitation sent to '.$invitedUser->email)
        ->and($noMail->refresh()->user_id)->toBeNull()->and($taken->refresh()->user_id)->toBeNull();
    // Owner decision 2026-10-31: the invitation is an e-mailed temporary password.
    Notification::assertSentTo($ashaUser, TemporaryPasswordMail::class);
    Notification::assertSentTo($invitedUser, TemporaryPasswordMail::class);
    Notification::assertNotSentTo($activeUser, TemporaryPasswordMail::class);
    expect($ashaUser->fresh()->holdsTemporaryPassword())->toBeTrue()
        ->and(AuditEvent::query()->withoutGlobalScopes()->where('action', AuditAction::TemporaryPasswordIssued->value)->where('operation_id', $run->operation_id)->count())->toBe(2);

    // Reset mode: only active sign-ins; the reverse of each mode is refused with the reason.
    $run = $this->runs->start($this->hr, 'employees', 'employees.send_reset_links', [$active->id, $asha->id, $noMail->id]);
    $items = wo16Items($run);
    expect($items[$active->id]->message)->toBe('Reset link sent to '.$activeUser->email)
        ->and($items[$asha->id]->message)->toContain('Has not accepted the invitation yet')
        ->and($items[$noMail->id]->message)->toBe('Has no sign-in yet: use "Send to new user".');
    Notification::assertSentTo($activeUser, PasswordResetLink::class);
    Notification::assertNotSentTo($ashaUser, PasswordResetLink::class);

    // Giving roles needs user.assign_roles; a role above one's own is refused.
    $noRoles = tenantUser($this->tenant, ['employee.view', 'user.create', 'bulk.run']);
    expect(fn () => $this->runs->start($noRoles, 'employees', 'employees.invite_users', [$noMail->id], ['roles' => [$employeeRole->id]]))->toThrow(RuntimeException::class, 'user.assign_roles')
        ->and(fn () => $this->runs->start($this->hr, 'employees', 'employees.invite_users', [$noMail->id], ['cc' => ['not-an-address']]))->toThrow(RuntimeException::class, 'is not an e-mail address');
});

it('sends the CC a separate notice that never carries the temporary password', function () {
    Notification::fake();
    $asha = wo16Hire('Asha', $this->delhi, null, ['work_email' => 'asha@acme.test']);
    $run = $this->runs->start($this->hr, 'employees', 'employees.invite_users', [$asha->id], ['cc' => ['HR-Team@Acme.test', 'asha@acme.test']]);
    $user = User::query()->findOrFail($asha->refresh()->user_id);

    $token = null;
    Notification::assertSentTo($user, TemporaryPasswordMail::class, function (TemporaryPasswordMail $n) use ($user, &$token) {
        $mail = $n->toMail($user);
        $token = Str::after((string) collect($mail->introLines)->first(fn (string $l) => str_starts_with($l, 'Temporary password: ')), 'Temporary password: ');

        return $mail->cc === [] && $mail->bcc === [];
    });
    Notification::assertSentTimes(TemporaryPasswordMail::class, 1);
    expect(strlen((string) $token))->toBeGreaterThanOrEqual(14);

    $copies = NotificationDelivery::query()->where('event', BulkInvitations::CC_EVENT)->get();
    expect($copies)->toHaveCount(1)   // the invitee's own address is never copied
        ->and($copies[0]->recipient)->toBe('hr-team@acme.test')
        ->and($copies[0]->subject)->toBe('Invitation sent to '.$asha->employee_code.' · Asha Test')
        ->and($copies[0]->body)->toContain('asha@acme.test')->toContain('carries no password')
        ->and($copies[0]->body)->not->toContain($token)
        ->and($copies[0]->body)->not->toContain('http')
        ->and($copies[0]->correlation_id)->toBe($run->operation_id)
        ->and(wo16Items($run)[$asha->id]->message)->toContain('CC notice to 1 address(es)');

    // No password anywhere it could be read back: deliveries, run items, run input, the audit trail.
    $everything = NotificationDelivery::query()->get()->map(fn ($d) => $d->subject.$d->body)->implode('|')
        .BulkRunItem::query()->pluck('message')->implode('|').json_encode(BulkRun::query()->pluck('input'))
        .AuditEvent::query()->withoutGlobalScopes()->get()->map(fn ($e) => json_encode($e->metadata).$e->reason)->implode('|');
    expect($everything)->not->toContain($token)->and($everything)->not->toContain('invitation/');

    // Reset mode: the same, with the reset link.
    $active = wo16Hire('Active', $this->delhi, null, ['work_email' => 'active@acme.test']);
    $activeUser = wo16Login($active);
    $this->runs->start($this->hr, 'employees', 'employees.send_reset_links', [$active->id], ['cc' => ['hr-team@acme.test']]);
    $resetUrl = null;
    Notification::assertSentTo($activeUser, PasswordResetLink::class, function (PasswordResetLink $n) use ($activeUser, &$resetUrl) {
        $resetUrl = $n->toMail($activeUser)->actionUrl;

        return true;
    });
    $copy = NotificationDelivery::query()->where('event', BulkInvitations::CC_EVENT)->latest('id')->first();
    expect($resetUrl)->toBeString()->not->toBe('')
        ->and($copy->subject)->toStartWith('Password reset link sent to')
        ->and($copy->body)->not->toContain((string) $resetUrl)
        ->and($copy->body)->not->toContain('http');
});

it('lists each invitation of a run with code, name, e-mail and status, and downloads it', function () {
    Notification::fake();
    $asha = wo16Hire('Asha', $this->delhi, null, ['work_email' => 'asha@acme.test']);
    $ravi = wo16Hire('Ravi', $this->delhi, null, ['work_email' => 'ravi@acme.test']);
    $noMail = wo16Hire('Nomail', $this->delhi);
    $run = $this->runs->start($this->hr, 'employees', 'employees.invite_users', [$asha->id, $ravi->id, $noMail->id], ['cc' => ['hr@acme.test']]);
    $user = User::query()->findOrFail($asha->refresh()->user_id);
    $token = null;
    Notification::assertSentTo($user, TemporaryPasswordMail::class, function (TemporaryPasswordMail $n) use ($user, &$token) {
        $token = Str::after((string) collect($n->toMail($user)->introLines)->first(fn (string $l) => str_starts_with($l, 'Temporary password: ')), 'Temporary password: ');

        return true;
    });
    // Asha signs in with it and chooses her own password.
    app(TemporaryPasswords::class)->replace($user->fresh(), 'A-Long-Unique-Passphrase-2026!');

    $rows = collect(app(BulkInvitations::class)->deliveryRows($run->fresh()))->keyBy('code');
    expect($rows[$asha->employee_code])->toMatchArray(['name' => 'Asha Test', 'email' => 'asha@acme.test', 'status' => 'Accepted', 'detail' => ''])
        ->and($rows[$asha->employee_code]['cc'])->toContain('hr@acme.test')
        ->and($rows[$ravi->employee_code]['status'])->toBe('Sent — waiting for the first sign-in')
        ->and($rows[$noMail->employee_code])->toMatchArray(['status' => 'Not sent', 'detail' => 'No work e-mail on file.', 'email' => '']);

    $csv = app(BulkInvitations::class)->deliveryCsv($run->fresh());
    expect(Str::before($csv, "\n"))->toBe('"Employee code","Employee name",E-mail,"E-mail status",Detail,"CC notices"')
        ->and($csv)->toContain($asha->employee_code)->toContain('Accepted')->not->toContain((string) $token);

    expect(DeliveryListRelationManager::canViewForRecord($run, ViewBulkRun::class))->toBeTrue();
    Livewire::test(DeliveryListRelationManager::class, ['ownerRecord' => $run->fresh(), 'pageClass' => ViewBulkRun::class])
        ->assertOk()->assertSee('asha@acme.test')->assertSee('Accepted')->assertSee('No work e-mail on file.');
    $this->get(ViewBulkRun::getUrl(['record' => $run]))->assertOk();

    // Someone who may not open the run does not get its delivery list.
    $this->actingAs(tenantUser($this->tenant, ['bulk.run', 'employee.view']));
    expect(DeliveryListRelationManager::canViewForRecord($run, ViewBulkRun::class))->toBeFalse();
});

it('runs the wizards from the Employees register, a manager\'s page, the Users list and the desk', function () {
    Notification::fake();
    $lead = wo16Hire('Lead', $this->delhi);
    $asha = wo16Hire('Asha', $this->delhi, $lead, ['work_email' => 'asha@acme.test']);
    $ravi = wo16Hire('Ravi', $this->delhi, $lead);
    $nina = wo16Hire('Nina', $this->delhi);

    Livewire::test(ListEmployees::class)
        ->callAction('bulkChangeManager', ['source' => 'team', 'team_of' => $lead->id, 'team_type' => 'line', 'manager_id' => $nina->id, 'type' => 'line', 'effective_from' => '2026-10-20', 'reason' => 'Re-org'])
        ->assertHasNoActionErrors();
    $run = BulkRun::query()->latest('id')->firstOrFail();
    expect($run->operation)->toBe('employees.change_manager')->and($run->record_ids)->toEqualCanonicalizing([$asha->id, $ravi->id])->and($run->done)->toBe(2);

    // A manager's own page moves their team; someone without a team has no such action.
    Livewire::test(ViewEmployee::class, ['record' => $asha->getRouteKey()])->assertActionHidden('changeTeamManager');
    Livewire::test(ViewEmployee::class, ['record' => $nina->getRouteKey()])->assertActionVisible('changeTeamManager')
        ->callAction('changeTeamManager', ['team_type' => 'line', 'manager_id' => $lead->id, 'type' => 'line', 'effective_from' => '2026-10-22', 'reason' => 'Back'])
        ->assertHasNoActionErrors();
    expect(BulkRun::query()->latest('id')->firstOrFail()->done)->toBe(2);

    // Invite by pasted codes; reset links through the fixed-mode wizard.
    Livewire::test(ListEmployees::class)
        ->callAction('bulkInvite', ['mode' => 'new', 'source' => 'codes', 'codes' => $asha->employee_code.', '.$ravi->employee_code, 'cc' => ['hr@acme.test']])
        ->assertHasNoActionErrors();
    $run = BulkRun::query()->latest('id')->firstOrFail();
    expect($run->operation)->toBe('employees.invite_users')->and($run->done)->toBe(1)->and($run->refused)->toBe(1)
        ->and($run->input['cc'])->toBe(['hr@acme.test']);

    $active = wo16Hire('Active', $this->delhi, null, ['work_email' => 'active@acme.test']);
    $activeUser = wo16Login($active);
    Livewire::test(ListEmployees::class)
        ->callAction('bulkResetLinks', ['source' => 'filters', 'location_id' => [$this->delhi->id], 'sign_in' => ['active']])
        ->assertHasNoActionErrors();
    expect(BulkRun::query()->latest('id')->firstOrFail()->only(['operation', 'done']))->toBe(['operation' => 'employees.send_reset_links', 'done' => 1]);
    Notification::assertSentTo($activeUser, PasswordResetLink::class);

    // Filters must narrow (or "everyone" be ticked on purpose).
    expect(fn () => app(EmployeeSelection::class)->ids(['source' => 'filters'], 10))->toThrow(RuntimeException::class, 'Choose at least one filter')
        ->and(app(EmployeeSelection::class)->ids(['source' => 'filters', 'joined_from' => '2025-01-01', 'sign_in' => ['none']], 10))->not->toContain($active->id);

    // Users list: reset links for the users ticked (someone sent one moments ago is refused: the reset broker's throttle).
    $colleague = tenantUser($this->tenant, ['task.view']);
    $second = tenantUser($this->tenant, ['task.view']);
    Livewire::test(ListUsers::class)->callTableBulkAction('bulk_users_send_reset_links', [$colleague, $second, $activeUser], [])->assertHasNoTableBulkActionErrors();
    $run = BulkRun::query()->latest('id')->firstOrFail();
    expect($run->only(['operation', 'register', 'done', 'refused']))->toBe(['operation' => 'users.send_reset_links', 'register' => 'users', 'done' => 2, 'refused' => 1])
        ->and(wo16Items($run)[$activeUser->id]->message)->toContain('moments ago');

    // The desk opens the wizards on the Employees register.
    $this->get(WorkforceDesk::getUrl())->assertOk()
        ->assertSee(EmployeeResource::getUrl('index', ['action' => 'bulkChangeManager']), false)
        ->assertSee(EmployeeResource::getUrl('index', ['action' => 'bulkInvite']), false);
});

it('moves a leaver\'s team from the day after the last working day by default (the bulk part of §23.6.3 #13)', function () {
    $lead = wo16Hire('Lead', $this->delhi);
    $asha = wo16Hire('Asha', $this->delhi, $lead);
    $nina = wo16Hire('Nina', $this->delhi);
    $case = app(Exits::class)->initiate($lead, 'resignation', 'Moving on');

    $page = Livewire::test(ViewEmployee::class, ['record' => $lead->getRouteKey()])->mountAction('changeTeamManager');
    expect($page->instance()->mountedActions[0]['data']['effective_from'] ?? null)->toBe($case->last_working_day->copy()->addDay()->toDateString());

    Livewire::test(ViewEmployee::class, ['record' => $lead->getRouteKey()])
        ->callAction('changeTeamManager', ['team_type' => 'line', 'manager_id' => $nina->id, 'type' => 'line', 'effective_from' => $case->last_working_day->copy()->addDay()->toDateString(), 'reason' => 'Hand-over'])
        ->assertHasNoActionErrors();
    expect(ReportingRelationship::query()->where('employee_id', $asha->id)->where('manager_id', $nina->id)->sole()->effective_from->toDateString())
        ->toBe($case->last_working_day->copy()->addDay()->toDateString());
});
