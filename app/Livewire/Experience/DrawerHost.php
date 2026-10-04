<?php

namespace App\Livewire\Experience;

use App\Domain\Experience\Services\ApprovalCenter;
use App\Domain\Experience\Services\ApprovalDecisions;
use App\Domain\Experience\Services\ChangeFeed;
use App\Domain\Experience\Services\ExperiencePreferences;
use App\Domain\Experience\Services\PeopleVisibility;
use App\Domain\Experience\Services\UxMetrics;
use App\Filament\Pages\OrganisationMap;
use App\Filament\Resources\Employees\EmployeeResource;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use InvalidArgumentException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;
use RuntimeException;

/**
 * UX: contextual drawers (§16–17). One host for every drawer, so the page underneath keeps its place.
 * Types: person (directory preview), approval (decide with context), person-action (start a guided
 * change). Every open re-resolves the record for the viewer; an id the viewer may not see renders the
 * same "not available" state as a missing one, so drawers never confirm that a record exists.
 */
class DrawerHost extends Component
{
    public ?string $type = null;

    public ?string $key = null;

    /** @var list<array{type: string, key: string}> drawers underneath the current one (stacking) */
    public array $stack = [];

    public ?string $done = null;

    #[On('pos-drawer-open')]
    public function show(string $type, string|int $id): void
    {
        if (! in_array($type, ['person', 'approval', 'person-action', 'change'], true)) {
            return;
        }
        if ($this->type !== null && ($this->type !== $type || $this->key !== (string) $id)) {
            $this->stack[] = ['type' => $this->type, 'key' => (string) $this->key];
            $this->stack = array_slice($this->stack, -3);
        }
        $this->type = $type;
        $this->key = (string) $id;
        $this->done = null;
        unset($this->person, $this->approval, $this->change);
        app(UxMetrics::class)->record('drawer.open');
    }

    public function back(): void
    {
        $previous = array_pop($this->stack);
        $this->type = $previous['type'] ?? null;
        $this->key = $previous['key'] ?? null;
        $this->done = null;
        unset($this->person, $this->approval, $this->change);
    }

    public function close(): void
    {
        $this->type = null;
        $this->key = null;
        $this->stack = [];
        $this->done = null;
    }

    /** @return array<string, mixed>|null */
    #[Computed]
    public function person(): ?array
    {
        if (! in_array($this->type, ['person', 'person-action'], true)) {
            return null;
        }
        $user = auth()->user();
        $visibility = app(PeopleVisibility::class);
        $e = $visibility->query($user)->with(['person', 'currentPosition.designation', 'currentPosition.department', 'currentPosition.location', 'currentManager.manager.person'])
            ->find((int) $this->key);
        if ($e === null) {
            return null;
        }
        $open = $visibility->canOpenProfile($user, $e);
        $profile = $open ? EmployeeResource::getUrl('view', ['record' => $e]) : null;
        $p = $e->currentPosition;
        $manager = $e->currentManager?->manager;
        $changes = [];
        if ($open) {
            $changes = array_values(array_filter([
                $user->can('assignPosition', $e) ? ['key' => 'assignPosition', 'label' => 'Transfer or promote', 'hint' => 'New department, designation, grade, location or seat. You see the Before → After before saving.', 'icon' => 'heroicon-o-arrow-trending-up'] : null,
                $user->can('assignPosition', $e) ? ['key' => 'changeManager', 'label' => 'Change manager', 'hint' => 'New reporting line from a date you choose.', 'icon' => 'heroicon-o-user-circle'] : null,
                $user->can('transition', $e) ? ['key' => 'lifecycle', 'label' => 'Change lifecycle state', 'hint' => 'Confirm probation, start notice, and other life events.', 'icon' => 'heroicon-o-arrow-path'] : null,
                $user->can('onboarding.manage') ? ['key' => 'startOnboarding', 'label' => 'Start onboarding', 'hint' => 'From a template, with tasks for everyone involved.', 'icon' => 'heroicon-o-rocket-launch'] : null,
                $user->can('workflow.run') ? ['key' => 'startWorkflow', 'label' => 'Start a workflow', 'hint' => 'Run a published workflow for this person.', 'icon' => 'heroicon-o-arrows-right-left'] : null,
            ]));
        }

        return [
            'id' => $e->id,
            'name' => $e->display_name ?? $e->employee_code,
            'code' => $e->employee_code,
            'title' => $p?->designation?->name,
            'department' => $p?->department?->name,
            'location' => $p?->location?->name,
            'manager' => $manager ? ['id' => $manager->id, 'name' => $manager->person?->display_name] : null,
            'email' => $e->work_email,
            'phone' => $e->work_phone,
            'status' => $open ? $e->lifecycle_state?->getLabel() : null,
            'tenure' => $open && $e->joining_date ? $e->joining_date->diffForHumans(now(), ['parts' => 2, 'syntax' => 1]) : null,
            'profile' => $profile,
            'org' => OrganisationMap::canAccess() ? OrganisationMap::getUrl(['focus' => $e->id]) : null,
            'pinned' => in_array($e->id, app(ExperiencePreferences::class)->for($user)['pinned_people'] ?? [], true),
            'changes' => $changes,
        ];
    }

    /**
     * UX.15 "What changed": one change in context, re-resolved through ChangeFeed for this viewer (the same
     * timeline categories and people they may already see). Never raw audit records.
     *
     * @return array<string, mixed>|null
     */
    #[Computed]
    public function change(): ?array
    {
        if ($this->type !== 'change' || $this->key === null) {
            return null;
        }
        $item = app(ChangeFeed::class)->find(auth()->user(), $this->key);
        if ($item === null) {
            return null;
        }
        $person = null;
        if ($item['subject_id'] !== null) {
            $e = app(PeopleVisibility::class)->query(auth()->user())->with(['person', 'currentPosition.designation', 'currentPosition.department'])->find($item['subject_id']);
            if ($e !== null) {
                $open = app(PeopleVisibility::class)->canOpenProfile(auth()->user(), $e);
                $person = ['id' => $e->id, 'name' => (string) $e->display_name, 'role' => collect([$e->currentPosition?->designation?->name, $e->currentPosition?->department?->name])->filter()->implode(' · '),
                    'profile' => $open ? EmployeeResource::getUrl('view', ['record' => $e]) : null];
            }
        }

        return $item + ['person' => $person];
    }

    #[Computed]
    public function approval(): mixed
    {
        return $this->type === 'approval' && $this->key ? app(ApprovalCenter::class)->find(auth()->user(), $this->key) : null;
    }

    public function togglePin(): void
    {
        if ($this->person === null) {
            return;
        }
        app(ExperiencePreferences::class)->togglePin(auth()->user(), (int) $this->person['id']);
        unset($this->person);
    }

    public function decide(string $id, string $decision, ?string $note = null): void
    {
        try {
            $this->done = app(ApprovalDecisions::class)->decide(auth()->user(), $id, $decision, $note);
            $this->dispatch('pos-approval-decided', id: $id);
        } catch (InvalidArgumentException $e) {
            $this->addError('note.'.$id, $e->getMessage());
        } catch (AuthorizationException|RuntimeException $e) {
            $this->addError('note.'.$id, $e->getMessage());
        }
        unset($this->approval);
    }

    public function render(): View
    {
        return view('livewire.experience.drawer-host');
    }
}
