<?php

namespace App\Domain\Grievance\Services;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Employment\Models\Employee;
use App\Domain\Grievance\Models\Grievance;
use App\Domain\Grievance\Models\GrievanceCategory;
use App\Domain\Grievance\Models\GrievanceNote;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\ServiceDesk\Events\ServiceDeskEvent;
use App\Domain\ServiceDesk\Services\ServiceDesk;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Grievance handling (§49). Confidential by design: access is the assignee, explicitly granted users,
 * holders of the category's handler roles, and (for non-anonymous cases) the employee who raised it.
 * Every read of a case is audited as sensitive access.
 */
final class Grievances
{
    public function __construct(private readonly AuditRecorder $audit, private readonly ServiceDesk $numbers) {}

    public function raise(GrievanceCategory $category, ?Employee $employee, string $subject, string $details, string $severity = 'medium', bool $anonymous = false, ?User $raiser = null): Grievance
    {
        if ($anonymous && ! $category->allow_anonymous) {
            throw new RuntimeException('This category does not accept anonymous cases.');
        }
        if (! $anonymous && $employee === null) {
            throw new RuntimeException('A named grievance needs the employee.');
        }

        return DB::transaction(function () use ($category, $employee, $subject, $details, $severity, $anonymous, $raiser) {
            $grievance = Grievance::create([
                'number' => $this->numbers->nextNumber('GRV'),
                'grievance_category_id' => $category->id,
                'employee_id' => $anonymous ? null : $employee?->id,
                'is_anonymous' => $anonymous,
                'subject' => $subject,
                'details' => $details,
                'severity' => $severity,
                'status' => 'submitted',
                'assignee_id' => $this->pickHandler($category),
                'access_user_ids' => [],
                'due_on' => now()->addDays($category->sla_days)->startOfDay(),
            ]);

            if ($grievance->assignee_id) {
                $grievance->update(['status' => 'under_review']);
            }

            // The audit trail never names an anonymous complainant.
            $this->audit->record(AuditAction::Create, 'grievance', $grievance, [], null, actor: $anonymous ? null : $raiser, metadata: ['category' => $category->code, 'severity' => $severity, 'anonymous' => $anonymous]);
            ServiceDeskEvent::dispatch('grievance.raised', $grievance, ['number' => $grievance->number, 'category' => $category->name, 'severity' => $severity], array_filter([$grievance->assignee_id]));

            return $grievance;
        });
    }

    private function pickHandler(GrievanceCategory $category): ?int
    {
        $candidates = Role::query()->whereIn('id', $category->handler_role_ids ?? [])->get()->flatMap(fn (Role $r) => $r->users()->get())->unique('id')->filter(fn (User $u) => $u->isActive());
        if ($candidates->isEmpty()) {
            return null;
        }

        return $candidates->sortBy(fn (User $u) => Grievance::query()->where('assignee_id', $u->id)->whereIn('status', Grievance::OPEN)->count())->first()->id;
    }

    public function canAccess(User $user, Grievance $grievance): bool
    {
        if ($user->is_platform_admin) {
            return true;
        }
        if ($grievance->assignee_id === $user->id || in_array($user->id, $grievance->access_user_ids ?? [], true)) {
            return true;
        }
        $category = $grievance->relationLoaded('category') ? $grievance->category : $grievance->category()->first();
        $handlerRoles = $category?->handler_role_ids ?? [];
        if ($handlerRoles !== [] && $user->roles()->whereIn('roles.id', $handlerRoles)->exists()) {
            return true;
        }
        // Grievance managers see non-confidential cases, and confidential ones only where no handler
        // roles are configured yet (otherwise nobody could work the case).
        if ($category && (! $category->is_confidential || $handlerRoles === []) && $user->hasPermission('grievance.manage')) {
            return true;
        }
        if (! $grievance->is_anonymous && $grievance->employee_id && Employee::query()->where('user_id', $user->id)->where('id', $grievance->employee_id)->exists()) {
            return true;
        }

        return false;
    }

    public function recordAccess(Grievance $grievance, User $user): void
    {
        $this->audit->record(AuditAction::View, 'grievance', $grievance, [], null, actor: $user, metadata: ['sensitive' => true, 'confidential' => (bool) $grievance->category()->value('is_confidential')]);
    }

    public function assign(Grievance $grievance, User $assignee, ?User $actor = null): Grievance
    {
        if (! $grievance->isOpen()) {
            throw new RuntimeException('The case is closed.');
        }
        $before = $grievance->assignee_id;
        $grievance->update(['assignee_id' => $assignee->id, 'status' => $grievance->status === 'submitted' ? 'under_review' : $grievance->status]);
        $this->audit->record(AuditAction::Delegated, 'grievance', $grievance, [['field' => 'assignee_id', 'before' => $before, 'after' => $assignee->id]], null, actor: $actor);
        ServiceDeskEvent::dispatch('grievance.assigned', $grievance, ['number' => $grievance->number], [$assignee->id]);

        return $grievance;
    }

    public function grantAccess(Grievance $grievance, User $user, string $reason, ?User $actor = null): Grievance
    {
        $ids = collect($grievance->access_user_ids ?? [])->push($user->id)->unique()->values()->all();
        $grievance->withAuditReason($reason)->update(['access_user_ids' => $ids]);
        $this->audit->record(AuditAction::PermissionChanged, 'grievance', $grievance, [['field' => 'access_user_ids', 'before' => null, 'after' => $user->email]], $reason, actor: $actor);

        return $grievance;
    }

    public function addNote(Grievance $grievance, User $author, string $type, string $body, bool $visibleToEmployee = false, ?string $attachmentPath = null, ?string $attachmentName = null): GrievanceNote
    {
        if ($grievance->status === 'closed') {
            throw new RuntimeException('The case is closed.');
        }

        $note = GrievanceNote::create(['grievance_id' => $grievance->id, 'author_id' => $author->id, 'type' => $type, 'body' => $body, 'visible_to_employee' => $visibleToEmployee || $type === 'employee', 'attachment_path' => $attachmentPath, 'attachment_name' => $attachmentName]);

        if ($type === 'action' && in_array($grievance->status, ['under_review', 'investigating'], true)) {
            $grievance->update(['status' => 'action_taken']);
        }

        $employeeUser = $grievance->is_anonymous ? null : $grievance->employee()->value('user_id');
        $recipients = $type === 'employee' && $author->id === $employeeUser ? array_filter([$grievance->assignee_id]) : ($visibleToEmployee ? array_filter([$employeeUser]) : []);
        ServiceDeskEvent::dispatch('grievance.updated', $grievance, ['number' => $grievance->number, 'type' => $type], $recipients);

        return $note;
    }

    public function setStatus(Grievance $grievance, string $status, ?User $actor = null, ?string $note = null): Grievance
    {
        if (! array_key_exists($status, config('peopleos.grievance.statuses'))) {
            throw new RuntimeException('Unknown status.');
        }
        if (in_array($status, ['resolved', 'closed'], true)) {
            throw new RuntimeException('Use resolve() or close().');
        }
        $before = $grievance->status;
        $grievance->withAuditReason($note)->update(['status' => $status]);
        $this->audit->record(AuditAction::Update, 'grievance', $grievance, [['field' => 'status', 'before' => $before, 'after' => $status]], $note, actor: $actor);

        return $grievance;
    }

    public function resolve(Grievance $grievance, string $resolution, ?User $actor = null): Grievance
    {
        if (! $grievance->isOpen()) {
            throw new RuntimeException('The case is not open.');
        }
        $grievance->update(['status' => 'resolved', 'resolution' => $resolution, 'resolved_at' => now()]);
        $this->audit->record(AuditAction::Approved, 'grievance', $grievance, [['field' => 'status', 'before' => 'open', 'after' => 'resolved']], $resolution, actor: $actor);
        ServiceDeskEvent::dispatch('grievance.resolved', $grievance, ['number' => $grievance->number], array_filter([$grievance->is_anonymous ? null : $grievance->employee()->value('user_id')]));

        return $grievance;
    }

    public function close(Grievance $grievance, ?User $actor = null): Grievance
    {
        if (! in_array($grievance->status, ['resolved', 'withdrawn'], true)) {
            throw new RuntimeException('Resolve the case before closing it.');
        }
        $grievance->update(['status' => 'closed', 'closed_at' => now()]);

        return $grievance;
    }

    public function withdraw(Grievance $grievance, User $employeeUser, string $reason): Grievance
    {
        if ($grievance->is_anonymous || $grievance->employee()->value('user_id') !== $employeeUser->id) {
            throw new RuntimeException('Only the employee who raised the case can withdraw it.');
        }
        if (! $grievance->isOpen()) {
            throw new RuntimeException('The case is not open.');
        }
        $grievance->withAuditReason($reason)->update(['status' => 'withdrawn', 'closed_at' => now()]);

        return $grievance;
    }

    /** Cases past due that are still open: escalate to grievance managers once a day. */
    public function overdue()
    {
        return Grievance::query()->with('category')->whereIn('status', Grievance::OPEN)->whereDate('due_on', '<', now())->get();
    }
}
