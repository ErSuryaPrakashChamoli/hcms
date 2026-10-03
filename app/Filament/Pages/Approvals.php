<?php

namespace App\Filament\Pages;

use App\Domain\Experience\Services\ApprovalCenter;
use App\Domain\Experience\Services\ApprovalDecisions;
use App\Domain\Experience\Services\RoleLens;
use App\Support\Tenancy\TenantContext;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Auth\Access\AuthorizationException;
use InvalidArgumentException;
use Livewire\Attributes\Computed;
use RuntimeException;
use UnitEnum;

/**
 * UX: the Approval Center (§22). Every decision waiting for the viewer, grouped Urgent / Today /
 * Upcoming, with what, who, why, impact, effective date, risk and requester, and the Before → After
 * where the record has one. Decisions go through ApprovalDecisions to the owning domain service.
 */
class Approvals extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCheckBadge;

    protected static string|UnitEnum|null $navigationGroup = 'Workflows';

    protected static ?string $navigationLabel = 'Approvals';

    protected static ?string $title = 'Approvals';

    protected static ?string $slug = 'approvals';

    protected static ?int $navigationSort = -5;

    protected string $view = 'filament.pages.experience.approvals';

    /** @var list<string> ids decided in this session, for the success state */
    public array $decided = [];

    public static function canAccess(): bool
    {
        $user = auth()->user();
        if ($user === null || ! app(TenantContext::class)->has()) {
            return false;
        }
        $lenses = app(RoleLens::class);

        return $lenses->has($user, RoleLens::MANAGER) || $user->hasPermission('task.view') || $user->hasPermission('leave.approve')
            || $user->hasPermission('attendance.approve') || $user->hasPermission('compensation.approve') || $user->hasPermission('compensation.review') || $user->hasPermission('letter.issue');
    }

    public function getSubheading(): ?string
    {
        $count = $this->pendingCount;

        return $count === 0 ? 'Nothing is waiting for your decision.' : $count.' '.($count === 1 ? 'decision is' : 'decisions are').' waiting for you. Context comes with each one.';
    }

    #[Computed]
    public function groups(): array
    {
        return app(ApprovalCenter::class)->grouped(auth()->user());
    }

    #[Computed]
    public function pendingCount(): int
    {
        $g = $this->groups;

        return $g['urgent']->count() + $g['today']->count() + $g['upcoming']->count();
    }

    public function decide(string $id, string $decision, ?string $note = null): void
    {
        if (! in_array($decision, ['approve', 'reject', 'request_change', 'complete'], true)) {
            return;
        }
        try {
            $message = app(ApprovalDecisions::class)->decide(auth()->user(), $id, $decision, $note);
            $this->decided[] = $id;
            Notification::make()->success()->title($message)->send();
            $this->dispatch('pos-approval-decided', id: $id);
        } catch (InvalidArgumentException $e) {
            $this->addError('note.'.$id, $e->getMessage());

            return;
        } catch (AuthorizationException|RuntimeException $e) {
            Notification::make()->danger()->title('Not done')->body($e->getMessage())->persistent()->send();
        }
        unset($this->groups, $this->pendingCount);
    }
}
