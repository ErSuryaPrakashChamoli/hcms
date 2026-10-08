<?php

namespace App\Filament\Pages;

use App\Domain\Attendance\Models\AttendanceRegularisation;
use App\Domain\Communication\Models\Announcement;
use App\Domain\Compensation\Models\CompensationChange;
use App\Domain\Employment\Models\Employee;
use App\Domain\Exit\Models\ExitCase;
use App\Domain\Experience\Services\ExperiencePreferences;
use App\Domain\Experience\Services\RoleLens;
use App\Domain\Experience\Support\NotificationCategories;
use App\Domain\Leave\Models\LeaveRequest;
use App\Domain\Letters\Models\Letter;
use App\Domain\Payroll\Models\Payslip;
use App\Domain\ServiceDesk\Models\Ticket;
use App\Domain\ServiceDesk\Services\CaseAccess;
use App\Domain\Workflow\Models\WorkflowInstance;
use App\Domain\Workflow\Models\WorkflowTask;
use App\Filament\Resources\CompensationChanges\CompensationChangeResource;
use App\Filament\Resources\Employees\EmployeeResource;
use App\Filament\Resources\ExitCases\ExitCaseResource;
use App\Filament\Resources\Letters\LetterResource;
use App\Filament\Resources\Payslips\PayslipResource;
use App\Filament\Resources\Tickets\TicketResource;
use App\Support\Tenancy\TenantContext;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Throwable;
use UnitEnum;

/**
 * UX: the notification center (§30). The person's own in-app notifications, grouped (Needs attention,
 * Approvals, Mentions, Announcements, Updates, System), with mark read / unread, mark all read, snooze
 * and "open in context". Deep links are resolved per record and only when the viewer may open that
 * record; otherwise the notification stays readable without a link. Channel preferences stay in My HR.
 */
class NotificationCenter extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBell;

    protected static string|UnitEnum|null $navigationGroup = 'Me';

    protected static ?string $navigationLabel = 'Notifications';

    protected static ?string $title = 'Notifications';

    protected static ?string $slug = 'notifications';

    protected static ?int $navigationSort = -8;

    protected string $view = 'filament.pages.experience.notification-center';

    #[Url]
    public string $group = 'all';

    #[Url]
    public bool $unread = false;

    #[Url]
    public bool $snoozedOnly = false;

    public int $limit = 40;

    public static function canAccess(): bool
    {
        return auth()->check() && app(TenantContext::class)->has();
    }

    public function getSubheading(): ?string
    {
        $n = auth()->user()->unreadNotifications()->count();

        if ($n === 0) {
            return 'You are all caught up.';
        }
        $first = NotificationCategories::GROUPS[NotificationCategories::ROLE_ORDER[$this->experience()][0]][0] ?? null;

        return $n.' unread'.($first ? ', '.mb_strtolower($first).' first for your role' : '').'. Open one to go straight to its context.';
    }

    /** UX.16: the experience that orders unread notifications (same lens as Home, including "Home opens as"). */
    public function experience(): string
    {
        $user = auth()->user();

        return RoleLens::experienceOf(app(RoleLens::class)->primary($user, app(ExperiencePreferences::class)->for($user)['lens'] ?? null));
    }

    /** @return Collection<int, array<string, mixed>> */
    #[Computed]
    public function items(): Collection
    {
        $snoozed = collect(app(ExperiencePreferences::class)->for(auth()->user())['snoozed'] ?? [])
            ->filter(fn ($until, $key) => str_starts_with((string) $key, 'notif:'))->keyBy(fn ($until, $key) => substr((string) $key, 6));

        return auth()->user()->notifications()->latest()->limit(200)->get()
            ->map(function (DatabaseNotification $n) use ($snoozed) {
                $meta = $n->data['viewData']['peopleos'] ?? [];
                $group = $meta['group'] ?? NotificationCategories::for($meta['event'] ?? null);
                [$label, $icon, $color] = NotificationCategories::meta($group);

                return [
                    'id' => $n->id, 'title' => (string) ($n->data['title'] ?? ''), 'body' => (string) ($n->data['body'] ?? ''), 'group' => $group, 'group_label' => $label,
                    'icon' => $icon, 'tone' => ['danger' => 'rose', 'warning' => 'amber', 'info' => 'sky', 'primary' => 'violet'][$color] ?? 'indigo',
                    'at' => $n->created_at, 'read' => $n->read_at !== null, 'snoozed_until' => $snoozed[$n->id] ?? null,
                    'source_type' => $meta['source_type'] ?? null, 'source_id' => $meta['source_id'] ?? null,
                ];
            });
    }

    /** @return array<string, int> unread counts per group (snoozed excluded) */
    #[Computed]
    public function counts(): array
    {
        $active = $this->items->whereNull('snoozed_until');
        $out = ['all' => $active->where('read', false)->count()];
        foreach (array_keys(NotificationCategories::GROUPS) as $g) {
            $out[$g] = $active->where('group', $g)->where('read', false)->count();
        }

        return $out;
    }

    /** @return Collection<int, array<string, mixed>> */
    public function visible(): Collection
    {
        $experience = $this->experience();

        return $this->items
            ->filter(fn ($i) => $this->snoozedOnly ? $i['snoozed_until'] !== null : $i['snoozed_until'] === null)
            ->when($this->group !== 'all', fn ($c) => $c->where('group', $this->group))
            ->when($this->unread, fn ($c) => $c->where('read', false))
            // UX.16: unread first, in the order that matters for the person's role, newest first within; then read, newest first.
            ->sortBy([
                fn ($a, $b) => (int) $a['read'] <=> (int) $b['read'],
                fn ($a, $b) => $a['read'] ? 0 : NotificationCategories::weight($experience, $a['group']) <=> NotificationCategories::weight($experience, $b['group']),
                fn ($a, $b) => $b['at'] <=> $a['at'],
            ])
            ->take($this->limit)->values();
    }

    public function setGroup(string $group): void
    {
        $this->group = $group === 'all' || isset(NotificationCategories::GROUPS[$group]) ? $group : 'all';
        $this->snoozedOnly = false;
    }

    public function toggleRead(string $id): void
    {
        $n = auth()->user()->notifications()->whereKey($id)->first();
        if ($n !== null) {
            $n->read_at === null ? $n->markAsRead() : $n->markAsUnread();
        }
        unset($this->items, $this->counts);
    }

    public function markAllRead(): void
    {
        auth()->user()->unreadNotifications()->update(['read_at' => now()]);
        unset($this->items, $this->counts);
    }

    public function snooze(string $id, string $for): void
    {
        if (! auth()->user()->notifications()->whereKey($id)->exists()) {
            return;
        }
        $until = match ($for) {
            'hour' => now()->addHour(), 'week' => now()->addWeek()->startOfDay()->setTime(9, 0), default => now()->addDay()->startOfDay()->setTime(9, 0)
        };
        app(ExperiencePreferences::class)->snooze(auth()->user(), 'notif:'.$id, $until->toIso8601String());
        unset($this->items, $this->counts);
    }

    public function unsnooze(string $id): void
    {
        $prefs = app(ExperiencePreferences::class);
        $snoozed = $prefs->for(auth()->user())['snoozed'] ?? [];
        unset($snoozed['notif:'.$id]);
        $prefs->update(auth()->user(), ['snoozed' => $snoozed]);
        unset($this->items, $this->counts);
    }

    /** Mark read and open the record in context, if the viewer may open it. */
    public function open(string $id): void
    {
        $item = $this->items->firstWhere('id', $id);
        if ($item === null) {
            return;
        }
        auth()->user()->notifications()->whereKey($id)->first()?->markAsRead();
        $url = $this->linkFor($item['source_type'], $item['source_id']);
        unset($this->items, $this->counts);
        if ($url !== null) {
            $this->redirect($url, navigate: true);
        }
    }

    public function linkFor(?string $type, mixed $id): ?string
    {
        if ($type === null || $id === null) {
            return null;
        }
        $class = Relation::getMorphedModel($type) ?? $type;
        if (! class_exists($class)) {
            return null;
        }
        $user = auth()->user();
        try {
            // find() goes through the record's own tenant and access scopes: no record, no link.
            $record = $class::query()->find($id);
            if ($record === null) {
                return null;
            }

            return match (true) {
                $record instanceof LeaveRequest, $record instanceof AttendanceRegularisation, $record instanceof WorkflowTask, $record instanceof WorkflowInstance => Approvals::canAccess() ? Approvals::getUrl() : MyWork::getUrl(),
                $record instanceof Ticket => app(CaseAccess::class)->canView($user, $record) ? TicketResource::getUrl('view', ['record' => $record]) : null,
                $record instanceof Letter => $user->can('view', $record) ? LetterResource::getUrl('view', ['record' => $record]) : null,
                $record instanceof Announcement => AnnouncementsFeed::canAccess() ? AnnouncementsFeed::getUrl() : null,
                $record instanceof Employee => $user->can('view', $record) ? EmployeeResource::getUrl('view', ['record' => $record]) : null,
                $record instanceof Payslip => $user->can('view', $record) ? PayslipResource::getUrl('view', ['record' => $record]) : null,
                $record instanceof ExitCase => $user->can('view', $record) ? ExitCaseResource::getUrl('view', ['record' => $record]) : null,
                $record instanceof CompensationChange => CompensationChangeResource::canAccess() ? CompensationChangeResource::getUrl('index') : null,
                default => null,
            };
        } catch (Throwable) {
            return null;
        }
    }
}
