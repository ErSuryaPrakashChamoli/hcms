<x-filament-panels::page>
    @php
        $w = $this->work;
        $inbox = $this->inbox;
        $filter = $this->filter();
        $counts = ['all' => null, 'decisions' => $w['decisions']->count(), 'tasks' => $w['tasks']->count(), 'followups' => $w['followups']->count(), 'waiting' => $w['waiting']->count(), 'completed' => $w['completed']->count()];
        $show = fn (string $key) => $filter === 'all' || $filter === $key;
        $sections = [
            'decisions' => ['Decisions', 'Approvals that need you, most urgent first. Each opens with its context.', 'heroicon-o-check-badge'],
            'tasks' => ['Tasks', 'Work assigned to you in PeopleOS.', 'heroicon-o-clipboard-document-check'],
            'followups' => ['Follow-ups', 'Reminders about your own records and your team, each with the reason.', 'heroicon-o-bell-alert'],
            'waiting' => ['Waiting on others', 'Your requests while someone else has them.', 'heroicon-o-arrow-path'],
            'upcoming' => ['Coming up', 'Due later. Nothing to do yet.', 'heroicon-o-calendar'],
            'completed' => ['Recently done', 'What you decided in the last two weeks.', 'heroicon-o-check-circle'],
        ];
        $verb = fn ($r) => $r['approval_id'] ? 'Review' : ($r['kind'] === 'task' ? 'Continue' : 'Open');
        // UX.16: the person's own kind of work leads (RoleSignals); employees call their waiting stream "My requests".
        $role = $this->role;
        if ($role['experience'] === 'employee') {
            $sections['waiting'] = ['My requests', 'Your requests and where each one stands.', 'heroicon-o-arrow-path'];
        }
        $lead = $role['lead'] && $role['lead']['items'] !== [] && $filter === 'all' ? $role['lead'] : null;
        $streamsEmpty = collect(['decisions', 'tasks', 'followups', 'waiting', 'upcoming', 'completed'])->every(fn ($k) => $w[$k]->isEmpty());
        $tone = fn (array $row, string $key): string => $key === 'completed' ? 'success' : ($row['severity'] === 'danger' ? 'danger' : ($row['severity'] === 'warning' ? 'warning' : ($key === 'waiting' ? 'info' : 'neutral')));
        $next = $filter === 'all' ? $this->nextUp : null;

        // At a glance: the operational role's own area first (the same count the brief gives), then the inbox buckets.
        $leadOpen = $role['first'] && $role['lead'] ? count(array_filter($role['lead']['items'], fn ($i) => $i['severity'] !== 'info')) : null;
        $leadIcon = ['hr' => 'heroicon-o-users', 'payroll' => 'heroicon-o-banknotes', 'executive' => 'heroicon-o-chart-bar', 'admin' => 'heroicon-o-cog-6-tooth'][$role['experience']] ?? 'heroicon-o-squares-2x2';
        $na = $inbox['needs_attention']->count();
        $today = $inbox['today']->count();
        $upcoming = $inbox['upcoming']->count();
        $waiting = $w['waiting']->count();
        $glance = array_values(array_filter([
            $leadOpen !== null ? ['label' => $role['lead']['title'], 'value' => $leadOpen, 'icon' => $leadIcon, 'tone' => $leadOpen > 0 ? 'warning' : 'success',
                'meta' => $leadOpen > 0 ? 'Open in the area you look after' : 'Nothing open in your area'] : null,
            ['label' => 'Need attention', 'value' => $na, 'icon' => 'heroicon-o-exclamation-triangle', 'tone' => $na > 0 ? 'danger' : 'success',
                'meta' => $na > 0 ? 'Overdue or urgent, so do these first' : 'Nothing overdue or urgent'],
            ['label' => 'Due today', 'value' => $today, 'icon' => 'heroicon-o-sun', 'tone' => $today > 0 ? 'warning' : 'success',
                'meta' => $today > 0 ? 'To decide or finish today' : 'Nothing due today'],
            ['label' => 'Coming up', 'value' => $upcoming, 'icon' => 'heroicon-o-calendar-days', 'tone' => 'info', 'meta' => $upcoming > 0 ? 'Due later, nothing to do yet' : 'Nothing scheduled'],
            ['label' => $sections['waiting'][0], 'value' => $waiting, 'icon' => 'heroicon-o-arrow-path', 'tone' => 'info',
                'meta' => $waiting > 0 ? 'Your requests with someone else' : 'Nothing with anyone else', 'click' => $waiting > 0 ? "setFilter('waiting')" : null],
        ]));

        // UX.16: where this person's own kind of work lives (each shown only if they can open it).
        $roleLinks = match ($role['experience']) {
            'employee' => [[\App\Filament\Pages\MyLearning::class, 'My learning', 'Courses and certificates', 'heroicon-o-academic-cap'], [\App\Filament\Pages\MyCareer::class, 'My career', 'Goals, growth and your journey', 'heroicon-o-arrow-trending-up']],
            'manager' => [[\App\Filament\Pages\MyTeam::class, 'My team', 'Your reports, who is in and who needs you', 'heroicon-o-user-group'], [\App\Filament\Pages\LeaveCalendar::class, 'Leave calendar', 'Who is away and when', 'heroicon-o-calendar-days']],
            'hr' => [[\App\Filament\Pages\People::class, 'People', 'Find anyone you look after', 'heroicon-o-users'], [\App\Filament\Pages\AttendanceExceptionCentre::class, 'Attendance exceptions', 'Missed punches and absences to resolve', 'heroicon-o-clock']],
            'payroll' => [[\App\Filament\Pages\PayrollControlRoom::class, 'Payroll overview', 'Every company\'s month and its runs', 'heroicon-o-banknotes']],
            'executive' => [[\App\Filament\Pages\WorkforceCommandCentre::class, 'Workforce pulse', 'The workforce story, drillable', 'heroicon-o-chart-bar'], [\App\Filament\Pages\OrganisationMap::class, 'Organisation map', 'Structure and reporting lines', 'heroicon-o-share']],
            'admin' => [[\App\Filament\Pages\AdminCentre::class, 'Admin Centre', 'Find a setting and manage PeopleOS', 'heroicon-o-cog-6-tooth'], [\App\Filament\Pages\ChangeIntelligencePage::class, 'Change history', 'Every audited change, searchable', 'heroicon-o-magnifying-glass-circle']],
            default => [],
        };
        $roleLinks = [
            ...$roleLinks,
            [\App\Filament\Pages\Approvals::class, 'Approval Center', 'Decide with the full context side by side', 'heroicon-o-check-badge'],
            [\App\Filament\Pages\NotificationCenter::class, 'Notifications', 'Everything PeopleOS told you, grouped', 'heroicon-o-bell'],
            [\App\Filament\Pages\MyHr::class, 'My HR', 'Requests, documents and services', 'heroicon-o-lifebuoy'],
        ];
        $roleLinks = array_values(array_filter($roleLinks, fn (array $l): bool => $l[0]::canAccess()));
    @endphp

    <div class="pos-ws pos-mw" x-data="posListNav('.pos-work-row')" x-on:keydown.window="handle($event)">
        {{-- The masthead: what needs you (the brief), the one thing to do next, the figures, and the filters --}}
        <x-pos.masthead class="pos-mw-mast" icon="heroicon-m-inbox-stack" :eyebrow="'My work · '.now()->format('l, j F')" title="What needs you today" :brief="$this->getSubheading()">
            {{-- Do this next: the single most important thing, with its reason --}}
            @if ($next)
                <section class="pos-mw-next" data-tone="{{ $next['severity'] === 'danger' ? 'danger' : 'warning' }}" aria-labelledby="pos-next-title">
                    <span class="pos-mw-next-icon" aria-hidden="true"><x-filament::icon icon="heroicon-m-bolt" class="size-5" /></span>
                    <div class="pos-mw-next-body">
                        <p class="pos-mw-next-eyebrow">Do this next</p>
                        <h2 id="pos-next-title" class="pos-mw-next-title">@if (($next['count'] ?? 0) > 0)<span class="pos-mw-next-count">{{ number_format($next['count']) }}</span> @endif{{ $next['title'] }}</h2>
                        <p class="pos-mw-next-meta">
                            @if ($next['subject'] ?? null)<x-pos.person :id="$next['subject_id']" :name="$next['subject']" size="sm" /><span aria-hidden="true">·</span>@endif
                            <span>{{ $next['domain'] }}@if (($next['subject'] ?? null) && ($next['reason'] ?? null)) · {{ $next['reason'] }}@elseif ($next['detail']) · {{ $next['detail'] }}@endif</span>
                            @if ($next['due'])<span aria-hidden="true">·</span><span class="{{ $next['due']->isPast() ? 'text-pos-danger' : '' }}">{{ $next['due']->isPast() ? 'overdue '.$next['due']->diffForHumans(null, true) : 'due '.$next['due']->diffForHumans() }}</span>@endif
                        </p>
                    </div>
                    <div class="pos-mw-next-actions">
                        <button type="button" class="pos-btn pos-btn-ghost" wire:click="later(@js($next['key']))">Later</button>
                        @if ($next['approval_id'])
                            <button type="button" class="pos-btn pos-btn-primary" x-on:click="$dispatch('pos-drawer-open', { type: 'approval', id: @js($next['approval_id']) })">{{ $verb($next) }}</button>
                        @else
                            <a href="{{ $next['url'] }}" wire:navigate class="pos-btn pos-btn-primary">{{ $verb($next) }}</a>
                        @endif
                    </div>
                </section>
            @endif

            <x-slot:glance>
                <x-pos.glance :rows="$glance" label="Your work at a glance" />
            </x-slot:glance>

            {{-- UX.17: one scrolling row of filters on phones; the chosen one scrolls into sight --}}
            <x-slot:band>
                <nav class="pos-mw-filters" aria-label="Show" x-data x-init="posRevealChip($el, $el.querySelector('[aria-pressed=true]'))">
                    <span class="pos-mw-filters-label">Show</span>
                    @foreach (\App\Filament\Pages\MyWork::FILTERS as $key => $label)
                        <button type="button" class="pos-chip" aria-pressed="{{ $filter === $key ? 'true' : 'false' }}" wire:click="setFilter('{{ $key }}')">
                            {{ $key === 'waiting' && $role['experience'] === 'employee' ? 'My requests' : $label }}@if (($counts[$key] ?? 0) > 0)<span class="pos-count">{{ $counts[$key] }}</span>@endif
                        </button>
                    @endforeach
                </nav>
                <span class="pos-mw-keys hidden lg:inline-flex" aria-hidden="true"><span class="pos-kbd">J</span><span class="pos-kbd">K</span> move <span class="pos-kbd">↵</span> open</span>
            </x-slot:band>
        </x-pos.masthead>

        <div class="pos-ws-cols">
            <div class="pos-ws-main">
                @if ($role['first'] && $lead)
                    <div class="pos-work-lead" data-experience="{{ $role['experience'] }}">
                        @include('filament.pages.home.signals', ['items' => $lead['items'], 'title' => $lead['title'], 'sub' => $lead['sub'], 'link' => $lead['link'], 'linkLabel' => $lead['link_label'], 'verb' => 'Open'])
                    </div>
                @endif

                @if (! $role['first'] && $lead)
                    <div class="pos-work-lead" data-experience="{{ $role['experience'] }}">
                        @include('filament.pages.home.signals', ['items' => $lead['items'], 'title' => $lead['title'], 'sub' => $lead['sub'], 'link' => $lead['link'], 'linkLabel' => $lead['link_label'], 'verb' => 'Open'])
                    </div>
                @endif

                @if ($streamsEmpty && ! $lead)
                    <div class="pos-panel pos-mw-empty">
                        <x-pos.state variant="caught-up" title="You’re all caught up." why="No decisions require your attention right now. New approvals, tasks and reminders will appear here first." />
                    </div>
                @endif

                @foreach ($sections as $key => [$title, $sub, $icon])
                    @continue(! $show($key === 'upcoming' ? 'tasks' : $key) || $w[$key]->isEmpty())
                    <x-pos.section :title="$title" :count="$w[$key]->count()" :sub="$sub"
                        :link="$key === 'decisions' && \App\Filament\Pages\Approvals::canAccess() ? \App\Filament\Pages\Approvals::getUrl() : null" link-label="Approval Center">
                        <ul class="pos-panel pos-stream" aria-label="{{ $title }}">
                            @foreach (in_array($key, $this->expanded, true) ? $w[$key] : $w[$key]->take(\App\Filament\Pages\MyWork::PAGE) as $row)
                                <li class="pos-stream-row pos-work-row" data-tone="{{ $tone($row, $key) }}"
                                    tabindex="-1" wire:key="work-{{ md5($row['key']) }}" wire:transition @if ($row['approval_id']) data-approval-id="{{ $row['approval_id'] }}" @endif>
                                    <span class="pos-stream-icon" aria-hidden="true"><x-filament::icon :icon="$icon" class="size-4" /></span>
                                    <div class="pos-stream-body">
                                        <p class="pos-stream-title">{{ $row['title'] }}@if (($row['count'] ?? 0) > 1) <span class="pos-count">{{ $row['count'] }}</span>@endif</p>
                                        <p class="pos-stream-meta flex flex-wrap items-center gap-x-1">
                                            @if ($row['subject'] ?? null)<x-pos.person :id="$row['subject_id']" :name="$row['subject']" /><span>·</span>@endif
                                            <span>{{ $row['domain'] }}@if (($row['subject'] ?? null)) @if ($row['reason'] ?? null) · {{ $row['reason'] }}@endif @elseif ($row['detail']) · {{ $row['detail'] }}@endif</span>
                                            @if ($row['due'])
                                                · <time datetime="{{ $row['due']->toIso8601String() }}" class="{{ $row['due']->isPast() && $key !== 'completed' ? 'text-pos-danger' : '' }}">{{ $key === 'completed' ? $row['due']->diffForHumans() : ($row['due']->isPast() ? 'overdue '.$row['due']->diffForHumans(null, true) : 'due '.$row['due']->diffForHumans()) }}</time>
                                            @endif
                                        </p>
                                    </div>
                                    <div class="pos-stream-end">
                                        @if ($row['approval_id'])
                                            <button type="button" class="pos-btn pos-btn-secondary pos-btn-sm" data-open x-on:click="$dispatch('pos-drawer-open', { type: 'approval', id: @js($row['approval_id']) })">Review</button>
                                        @elseif ($row['url'])
                                            <a href="{{ $row['url'] }}" wire:navigate class="pos-btn pos-btn-ghost pos-btn-sm" data-open>{{ $key === 'completed' || $key === 'waiting' ? 'Open' : $verb($row) }}</a>
                                        @endif
                                    </div>
                                </li>
                            @endforeach
                            @if (! in_array($key, $this->expanded, true) && $w[$key]->count() > \App\Filament\Pages\MyWork::PAGE)
                                <li class="pos-stream-more"><button type="button" class="pos-btn pos-btn-ghost pos-btn-sm" wire:click="showAll(@js($key))">Show all {{ $w[$key]->count() }}</button></li>
                            @endif
                        </ul>
                    </x-pos.section>
                @endforeach

                @if ($filter !== 'all' && ($w[$filter] ?? collect())->isEmpty())
                    <div class="pos-panel pos-mw-empty">
                        <x-pos.state variant="filtered" :title="'Nothing in '.mb_strtolower(\App\Filament\Pages\MyWork::FILTERS[$filter]).' right now.'" why="Choose Everything to see all your work." />
                    </div>
                @endif
            </div>

            @if ($roleLinks !== [])
                <aside class="pos-ws-side" aria-label="Where your work lives">
                    <x-pos.section title="Jump to">
                        <nav class="pos-panel pos-stream pos-links" aria-label="Jump to">
                            @foreach ($roleLinks as [$page, $label, $hint, $icon])
                                <a href="{{ $page::getUrl() }}" wire:navigate class="pos-stream-row">
                                    <span class="pos-stream-icon" aria-hidden="true"><x-filament::icon :icon="$icon" class="size-4" /></span>
                                    <span class="pos-stream-body"><span class="pos-stream-title">{{ $label }}</span><span class="pos-stream-meta">{{ $hint }}</span></span>
                                    <x-filament::icon icon="heroicon-m-arrow-right" class="pos-links-go size-4" />
                                </a>
                            @endforeach
                        </nav>
                    </x-pos.section>
                </aside>
            @endif
        </div>
    </div>
</x-filament-panels::page>
