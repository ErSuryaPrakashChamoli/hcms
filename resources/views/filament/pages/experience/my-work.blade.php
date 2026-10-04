<x-filament-panels::page>
    @php
        $w = $this->work;
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
        // UX.16: where this person's own kind of work lives (each shown only if they can open it).
        $roleLinks = match ($role['experience']) {
            'employee' => [[\App\Filament\Pages\MyLearning::class, 'My learning', 'Courses and certificates', 'heroicon-o-academic-cap'], [\App\Filament\Pages\MyCareer::class, 'My career', 'Goals, growth and your journey', 'heroicon-o-arrow-trending-up']],
            'manager' => [[\App\Filament\Pages\MyTeam::class, 'My team', 'Your reports, who is in and who needs you', 'heroicon-o-user-group'], [\App\Filament\Pages\LeaveCalendar::class, 'Leave calendar', 'Who is away and when', 'heroicon-o-calendar-days']],
            'hr' => [[\App\Filament\Pages\People::class, 'People', 'Find anyone you look after', 'heroicon-o-users'], [\App\Filament\Pages\AttendanceExceptionCentre::class, 'Attendance exceptions', 'Missed punches and absences to resolve', 'heroicon-o-clock']],
            'payroll' => [[\App\Filament\Pages\PayrollControlRoom::class, 'Payroll control room', 'Runs, exceptions and sign-off', 'heroicon-o-banknotes']],
            'executive' => [[\App\Filament\Pages\WorkforceCommandCentre::class, 'Workforce pulse', 'The workforce story, drillable', 'heroicon-o-chart-bar'], [\App\Filament\Pages\OrganisationMap::class, 'Organisation map', 'Structure and reporting lines', 'heroicon-o-share']],
            'admin' => [[\App\Filament\Pages\AdminCentre::class, 'Admin Centre', 'Find a setting and manage PeopleOS', 'heroicon-o-cog-6-tooth'], [\App\Filament\Pages\ChangeIntelligencePage::class, 'Change history', 'Every audited change, searchable', 'heroicon-o-magnifying-glass-circle']],
            default => [],
        };
    @endphp

    <div class="pos-ws" x-data="posListNav('.pos-work-row')" x-on:keydown.window="handle($event)">
        {{-- UX.17: one scrolling row of filters on phones (they wrapped to three rows); the chosen one scrolls into sight --}}
        <nav class="pos-lens pos-lens-scroll" aria-label="Show" x-data x-init="$el.querySelector('[aria-pressed=true]')?.scrollIntoView({ block: 'nearest', inline: 'nearest' })">
            @foreach (\App\Filament\Pages\MyWork::FILTERS as $key => $label)
                <button type="button" class="pos-chip" aria-pressed="{{ $filter === $key ? 'true' : 'false' }}" wire:click="setFilter('{{ $key }}')">
                    {{ $label }}@if (($counts[$key] ?? 0) > 0)<span class="pos-count">{{ $counts[$key] }}</span>@endif
                </button>
            @endforeach
            <span class="pos-meta ms-auto hidden md:inline" aria-hidden="true"><span class="pos-kbd">J</span> <span class="pos-kbd">K</span> move · <span class="pos-kbd">↵</span> open</span>
        </nav>

        @if ($role['first'])
        @if ($lead)
            <div class="pos-work-lead" data-experience="{{ $role['experience'] }}">
                @include('filament.pages.home.signals', ['items' => $lead['items'], 'title' => $lead['title'], 'sub' => $lead['sub'], 'link' => $lead['link'], 'linkLabel' => $lead['link_label'], 'verb' => 'Open'])
            </div>
        @endif
        @endif

        {{-- Do this next: the single most important thing, with its reason --}}
        @if ($w['next'] && $filter === 'all')
            @php($n = $w['next'])
            <section class="pos-panel pos-panel-pad pos-next-up" data-tone="{{ $n['severity'] === 'danger' ? 'danger' : 'warning' }}" aria-labelledby="pos-next-title">
                <div class="min-w-0 grid gap-1">
                    <p class="pos-ws-eyebrow">Do this next</p>
                    <h2 id="pos-next-title" class="pos-section-title">{{ $n['title'] }}</h2>
                    @if ($n['subject'] ?? null)<div><x-pos.person :id="$n['subject_id']" :name="$n['subject']" size="sm" /></div>@endif
                    <p class="pos-meta">{{ $n['domain'] }}@if (($n['subject'] ?? null) && ($n['reason'] ?? null)) · {{ $n['reason'] }}@elseif ($n['detail']) · {{ $n['detail'] }}@endif
                        @if ($n['due']) · <span class="{{ $n['due']->isPast() ? 'text-pos-danger' : '' }}">{{ $n['due']->isPast() ? 'overdue '.$n['due']->diffForHumans(null, true) : 'due '.$n['due']->diffForHumans() }}</span>@endif</p>
                </div>
                <div class="pos-ws-actions">
                    <button type="button" class="pos-btn pos-btn-ghost" wire:click="later(@js($n['key']))">Later</button>
                    @if ($n['approval_id'])
                        <button type="button" class="pos-btn pos-btn-primary" x-on:click="$dispatch('pos-drawer-open', { type: 'approval', id: @js($n['approval_id']) })">{{ $verb($n) }}</button>
                    @else
                        <a href="{{ $n['url'] }}" wire:navigate class="pos-btn pos-btn-primary">{{ $verb($n) }}</a>
                    @endif
                </div>
            </section>
        @endif

        @unless ($role['first'])
        @if ($lead)
            <div class="pos-work-lead" data-experience="{{ $role['experience'] }}">
                @include('filament.pages.home.signals', ['items' => $lead['items'], 'title' => $lead['title'], 'sub' => $lead['sub'], 'link' => $lead['link'], 'linkLabel' => $lead['link_label'], 'verb' => 'Open'])
            </div>
        @endif
        @endunless

        @if (collect(['decisions', 'tasks', 'followups', 'waiting', 'upcoming', 'completed'])->every(fn ($k) => $w[$k]->isEmpty()) && ! $lead)
            <x-pos.state variant="caught-up" title="You’re all caught up." why="No decisions require your attention right now. New approvals, tasks and reminders will appear here first." />
        @endif

        <div class="pos-ws-cols">
            <div class="pos-ws-main">
                @foreach ($sections as $key => [$title, $sub, $icon])
                    @continue(! $show($key === 'upcoming' ? 'tasks' : $key) || $w[$key]->isEmpty())
                    <x-pos.section :title="$title" :count="$w[$key]->count()" :sub="$sub"
                        :link="$key === 'decisions' && \App\Filament\Pages\Approvals::canAccess() ? \App\Filament\Pages\Approvals::getUrl() : null" link-label="Approval Center">
                        <ul class="pos-panel pos-stream" aria-label="{{ $title }}">
                            @foreach (in_array($key, $this->expanded, true) ? $w[$key] : $w[$key]->take(\App\Filament\Pages\MyWork::PAGE) as $row)
                                <li class="pos-stream-row pos-work-row" data-tone="{{ $key === 'completed' ? 'success' : ($row['severity'] === 'danger' ? 'danger' : ($row['severity'] === 'warning' ? 'warning' : ($key === 'waiting' ? 'info' : 'neutral'))) }}"
                                    tabindex="-1" wire:key="work-{{ md5($row['key']) }}" wire:transition @if ($row['approval_id']) data-approval-id="{{ $row['approval_id'] }}" @endif>
                                    <span class="pos-stream-mark" aria-hidden="true"></span>
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
                        </ul>
                        @if (! in_array($key, $this->expanded, true) && $w[$key]->count() > \App\Filament\Pages\MyWork::PAGE)
                            <button type="button" class="pos-btn pos-btn-ghost pos-btn-sm mt-2" wire:click="showAll(@js($key))">Show all {{ $w[$key]->count() }}</button>
                        @endif
                    </x-pos.section>
                @endforeach
                @if ($filter !== 'all' && ($w[$filter] ?? collect())->isEmpty())
                    <x-pos.state variant="filtered" :title="'Nothing in '.mb_strtolower(\App\Filament\Pages\MyWork::FILTERS[$filter]).' right now.'" why="Choose Everything to see all your work." />
                @endif
            </div>

            <aside class="pos-ws-side" aria-label="Your work at a glance">
                <x-pos.section title="At a glance">
                    <div class="pos-panel pos-panel-pad pos-figures">
                        <x-pos.figure :value="$this->inbox['needs_attention']->count()" label="Need attention" :meaning="$this->inbox['needs_attention']->isNotEmpty() ? 'bad' : null" />
                        <x-pos.figure :value="$this->inbox['today']->count()" label="Today" />
                        <x-pos.figure :value="$this->inbox['upcoming']->count()" label="Upcoming" />
                        <x-pos.figure :value="$w['waiting']->count()" label="Waiting on others" />
                    </div>
                </x-pos.section>
                <x-pos.section title="Elsewhere">
                    <div class="pos-panel pos-stream">
                        @foreach ($roleLinks as [$page, $label, $hint, $icon])
                            @if ($page::canAccess())
                                <a href="{{ $page::getUrl() }}" wire:navigate class="pos-stream-row"><span class="pos-stream-icon" aria-hidden="true"><x-filament::icon :icon="$icon" class="size-4" /></span><span class="pos-stream-body"><span class="pos-stream-title">{{ $label }}</span><span class="pos-stream-meta">{{ $hint }}</span></span><span aria-hidden="true" class="pos-muted">→</span></a>
                            @endif
                        @endforeach
                        @if (\App\Filament\Pages\Approvals::canAccess())
                            <a href="{{ \App\Filament\Pages\Approvals::getUrl() }}" wire:navigate class="pos-stream-row"><span class="pos-stream-icon" aria-hidden="true"><x-filament::icon icon="heroicon-o-check-badge" class="size-4" /></span><span class="pos-stream-body"><span class="pos-stream-title">Approval Center</span><span class="pos-stream-meta">Decide with the full context side by side</span></span><span aria-hidden="true" class="pos-muted">→</span></a>
                        @endif
                        @if (\App\Filament\Pages\NotificationCenter::canAccess())
                            <a href="{{ \App\Filament\Pages\NotificationCenter::getUrl() }}" wire:navigate class="pos-stream-row"><span class="pos-stream-icon" aria-hidden="true"><x-filament::icon icon="heroicon-o-bell" class="size-4" /></span><span class="pos-stream-body"><span class="pos-stream-title">Notifications</span><span class="pos-stream-meta">Everything PeopleOS told you, grouped</span></span><span aria-hidden="true" class="pos-muted">→</span></a>
                        @endif
                        @if (\App\Filament\Pages\MyHr::canAccess())
                            <a href="{{ \App\Filament\Pages\MyHr::getUrl() }}" wire:navigate class="pos-stream-row"><span class="pos-stream-icon" aria-hidden="true"><x-filament::icon icon="heroicon-o-lifebuoy" class="size-4" /></span><span class="pos-stream-body"><span class="pos-stream-title">My HR</span><span class="pos-stream-meta">Requests, documents and services</span></span><span aria-hidden="true" class="pos-muted">→</span></a>
                        @endif
                    </div>
                </x-pos.section>
            </aside>
        </div>
    </div>
</x-filament-panels::page>
