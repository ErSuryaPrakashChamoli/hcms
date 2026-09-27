<?php

namespace App\Domain\Communication\Services;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Communication\Models\Announcement;
use App\Domain\Communication\Models\AnnouncementRead;
use App\Domain\Configuration\Services\EmployeeRuleContext;
use App\Domain\Configuration\Services\RuleEngine;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Models\User;
use App\Domain\ServiceDesk\Events\ServiceDeskEvent;
use Illuminate\Support\Collection;
use RuntimeException;

/** Communication centre (§51): targeted publication, feed, read and acknowledgement tracking. */
final class Communications
{
    public function __construct(private readonly RuleEngine $rules, private readonly EmployeeRuleContext $context, private readonly AuditRecorder $audit) {}

    public function publish(Announcement $announcement, ?User $actor = null): Announcement
    {
        if (trim($announcement->body) === '') {
            throw new RuntimeException('The announcement has no content.');
        }
        $announcement->update(['status' => 'published', 'publish_at' => $announcement->publish_at ?? now()]);
        $this->audit->record(AuditAction::Update, 'communication', $announcement, [['field' => 'status', 'before' => 'draft', 'after' => 'published']], null, actor: $actor, metadata: ['type' => $announcement->type]);

        if ($announcement->isLive()) {
            $this->notify($announcement);
        }

        return $announcement->refresh();
    }

    /** Sends the in-app notice to the audience; idempotent per announcement (uses reads as the marker is not enough, so notification source dedupes). */
    public function notify(Announcement $announcement): int
    {
        $ids = $this->audienceEmployees($announcement)->pluck('user_id')->filter()->values()->all();
        ServiceDeskEvent::dispatch('communication.published', $announcement, ['title' => $announcement->title, 'type' => config("peopleos.communication.types.{$announcement->type}", $announcement->type), 'acknowledge' => $announcement->requires_acknowledgement], $ids);

        return count($ids);
    }

    public function archive(Announcement $announcement, ?User $actor = null): Announcement
    {
        $announcement->update(['status' => 'archived']);

        return $announcement;
    }

    public function inAudience(Announcement $announcement, Employee $employee): bool
    {
        return empty($announcement->audience) || $this->rules->matches($announcement->audience, $this->context->build($employee));
    }

    public function audienceEmployees(Announcement $announcement): Collection
    {
        return Employee::query()->with('person')->employed()->get()->filter(fn (Employee $e) => $this->inAudience($announcement, $e))->values();
    }

    /** Live announcements for this employee, pinned first, newest first. */
    public function feedFor(Employee $employee): Collection
    {
        return Announcement::query()->where('status', 'published')
            ->where(fn ($q) => $q->whereNull('publish_at')->orWhere('publish_at', '<=', now()))
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->orderByDesc('is_pinned')->orderByDesc('publish_at')->get()
            ->filter(fn (Announcement $a) => $this->inAudience($a, $employee))->values();
    }

    public function markRead(Announcement $announcement, Employee $employee): AnnouncementRead
    {
        return AnnouncementRead::query()->firstOrCreate(['announcement_id' => $announcement->id, 'employee_id' => $employee->id], ['read_at' => now()]);
    }

    public function acknowledge(Announcement $announcement, Employee $employee): AnnouncementRead
    {
        $read = $this->markRead($announcement, $employee);
        $read->update(['acknowledged_at' => $read->acknowledged_at ?? now()]);

        return $read;
    }

    public function pendingAcknowledgements(Employee $employee): Collection
    {
        return $this->feedFor($employee)->filter(fn (Announcement $a) => $a->requires_acknowledgement)
            ->reject(fn (Announcement $a) => AnnouncementRead::query()->where('announcement_id', $a->id)->where('employee_id', $employee->id)->whereNotNull('acknowledged_at')->exists())
            ->values();
    }

    /** @return array{audience: int, read: int, acknowledged: int} */
    public function stats(Announcement $announcement): array
    {
        $reads = AnnouncementRead::query()->where('announcement_id', $announcement->id);

        return ['audience' => $this->audienceEmployees($announcement)->count(), 'read' => (clone $reads)->count(), 'acknowledged' => (clone $reads)->whereNotNull('acknowledged_at')->count()];
    }
}
