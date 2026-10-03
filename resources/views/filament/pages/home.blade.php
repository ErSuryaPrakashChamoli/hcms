<x-filament-panels::page>
    @php
        $h = $this->home;
        $tenant = app(\App\Support\Tenancy\TenantContext::class)->has();
        $lensLabels = [
            'employee' => 'For you', 'manager' => 'Your team', 'hr' => 'People operations', 'hr_admin' => 'HR admin',
            'payroll' => 'Payroll', 'executive' => 'Company', 'system_admin' => 'Platform',
        ];
        $num = fn ($v) => rtrim(rtrim(number_format((float) $v, 1), '0'), '.');
    @endphp

    @if (! $tenant)
        {{-- Platform administrators outside a tenant --}}
        <div class="grid gap-[var(--pos-gap)] lg:grid-cols-3">
            <section class="pos-card lg:col-span-2 pos-enter" aria-labelledby="pos-tenants">
                <header class="pos-card-head">
                    <h2 id="pos-tenants" class="pos-h3">Tenants</h2>
                    @if ($this->platform['tenants_url'])
                        <a href="{{ $this->platform['tenants_url'] }}" wire:navigate class="pos-link">Manage tenants</a>
                    @endif
                </header>
                <ul class="pos-list">
                    @forelse ($this->platform['tenants'] as $t)
                        <li class="pos-list-row">
                            <x-pos.avatar :name="$t->name" size="sm" />
                            <div class="min-w-0 flex-1">
                                <p class="pos-body font-medium truncate">{{ $t->name }}</p>
                                <p class="pos-caption">{{ $t->slug }}</p>
                            </div>
                            @php($tStatus = $t->status instanceof \BackedEnum ? $t->status->value : (string) $t->status)
                            <x-pos.status :tone="$tStatus === 'active' ? 'success' : 'warning'" :label="ucfirst($tStatus)" />
                        </li>
                    @empty
                        <li><x-pos.empty title="No tenants yet" why="Provision the first tenant to start." icon="heroicon-o-building-office" /></li>
                    @endforelse
                </ul>
            </section>
            <section class="pos-card pos-enter-2">
                <h2 class="pos-h3">Platform health</h2>
                <p class="pos-body-sm mt-1">Readiness, audit chains, queues and statutory status.</p>
                @if ($this->platform['readiness_url'])
                    <a href="{{ $this->platform['readiness_url'] }}" wire:navigate class="pos-btn pos-btn-secondary mt-4">Open readiness</a>
                @endif
            </section>
        </div>
    @else
        <div class="pos-home2">
            <div class="pos-home2-main">
                {{-- Hero: greeting, today, lens switch; original dusk illustration --}}
                <section class="pos-hero pos-enter" aria-labelledby="pos-hero-title">
                    <x-pos.hero-art />
                    <div class="pos-hero-content">
                        <div class="flex items-center gap-4">
                            <x-pos.avatar :name="$h['employee']?->display_name ?? auth()->user()->name" size="xl" class="pos-hero-avatar" />
                            <div class="min-w-0">
                                <h1 id="pos-hero-title" class="pos-hero-title">{{ $this->getTitle() }}</h1>
                                <p class="pos-hero-sub">{{ $this->getSubheading() }}</p>
                            </div>
                        </div>
                        @if (count($h['lenses']) > 1)
                            <div class="pos-lens mt-5" role="tablist" aria-label="View Home as">
                                @foreach ($h['lenses'] as $lens)
                                    <button type="button" role="tab" class="pos-lens-chip" aria-selected="{{ $h['lens'] === $lens ? 'true' : 'false' }}" wire:click="switchLens('{{ $lens }}')">{{ $lensLabels[$lens] ?? $lens }}</button>
                                @endforeach
                            </div>
                        @endif
                    </div>
                    <p class="pos-hero-quote" aria-hidden="true">Every decision,<br>with its context.</p>
                </section>

                {{-- First-login welcome: what is different here, in four lines, dismissed for good --}}
                @if ($this->showWelcome())
                    <section class="pos-welcome pos-enter-2" aria-labelledby="pos-welcome-title" x-data="{ shown: true }" x-show="shown">
                        <div class="min-w-0 flex-1">
                            <p class="pos-label">Welcome to PeopleOS</p>
                            <h2 id="pos-welcome-title" class="pos-h2 mt-1">Your work comes to you here.</h2>
                            <ul class="pos-welcome-list mt-3">
                                <li><x-pos.tile-icon tone="rose" icon="heroicon-o-sparkles" size="sm" /><span><b>What matters now</b> is ranked on Home, with the reason for each item.</span></li>
                                <li><x-pos.tile-icon tone="indigo" icon="heroicon-o-magnifying-glass" size="sm" /><span>Press <span class="pos-kbd">Ctrl K</span> to find anyone or start anything. <span class="pos-kbd">?</span> shows every shortcut.</span></li>
                                <li><x-pos.tile-icon tone="amber" icon="heroicon-o-check-badge" size="sm" /><span><b>My work</b> holds approvals and tasks, each with the context to decide.</span></li>
                                <li><x-pos.tile-icon tone="violet" icon="heroicon-o-chat-bubble-left-right" size="sm" /><span>The <b>assistant</b> explains, with sources. It never decides for you.</span></li>
                            </ul>
                        </div>
                        <div class="flex flex-col gap-2">
                            <button type="button" class="pos-btn pos-btn-primary pos-btn-sm" wire:click="dismissWelcome" x-on:click="shown = false">Got it</button>
                            <button type="button" class="pos-btn pos-btn-ghost pos-btn-sm" x-data x-on:click="$dispatch('pos-shortcuts')">See shortcuts</button>
                        </div>
                    </section>
                @endif

                {{-- KPI strip --}}
                @if (count($h['kpis']) > 0)
                    <section class="pos-kpis pos-enter-2" aria-label="At a glance">
                        @foreach ($h['kpis'] as $k)
                            @php($tag = ($k['url'] ?? null) ? 'a' : 'div')
                            <{{ $tag }} @if ($tag === 'a') href="{{ $k['url'] }}" wire:navigate @endif class="pos-kpi {{ $tag === 'a' ? 'pos-kpi-link' : '' }}" data-pos-kpi="{{ $k['key'] }}">
                                @if (isset($k['ring']))
                                    <div class="min-w-0 flex-1">
                                        <p class="pos-kpi-label">{{ $k['label'] }}</p>
                                        <p class="pos-kpi-value pos-num">{{ $k['value'] }}</p>
                                        <p class="pos-caption">of {{ $num($k['ring']['total']) }} days</p>
                                    </div>
                                    <x-pos.ring :value="$k['ring']['value']" :total="$k['ring']['total']" :label="$k['label']" :size="56" />
                                @else
                                    <x-pos.tile-icon :tone="$k['tone']" :icon="$k['icon']" />
                                    <div class="min-w-0">
                                        <p class="pos-kpi-value pos-num">{{ $k['value'] }}</p>
                                        <p class="pos-kpi-label">{{ $k['label'] }}</p>
                                    </div>
                                @endif
                            </{{ $tag }}>
                        @endforeach
                    </section>
                @endif

                @if ($h['me'] ?? null)
                    <div class="xl:hidden">@include('filament.pages.partials.home-today', ['me' => $h['me'], 'suffix' => 'main'])</div>
                @endif

                {{-- Next best actions --}}
                @if (count($h['next']) > 0)
                    <section class="pos-next pos-enter-2" aria-labelledby="pos-next-title">
                        <h2 id="pos-next-title" class="sr-only">What to do next</h2>
                        @foreach ($h['next'] as $n)
                            <article class="pos-next-card" wire:key="next-{{ $n['key'] }}" data-severity="{{ $n['severity'] }}">
                                <x-pos.tile-icon :tone="$n['tone']" :icon="$n['icon']" size="lg" />
                                <div class="min-w-0 flex-1">
                                    <h3 class="pos-h3 line-clamp-2">{{ $n['title'] }}</h3>
                                    <p class="pos-body-sm line-clamp-1 mt-0.5">{{ $n['domain'] }}@if ($n['detail']) · {{ $n['detail'] }}@endif</p>
                                    @if ($n['due'])
                                        <p class="pos-caption mt-1 {{ $n['due']->isPast() ? 'text-pos-danger' : '' }}">
                                            <x-filament::icon icon="heroicon-m-clock" class="inline size-3.5 -mt-0.5" />
                                            {{ $n['due']->isPast() ? 'Overdue '.$n['due']->diffForHumans(null, true) : 'Due '.$n['due']->diffForHumans() }}
                                        </p>
                                    @endif
                                    <div class="mt-3 flex flex-wrap gap-2">
                                        @if ($n['approval_id'])
                                            <button type="button" class="pos-btn pos-btn-primary pos-btn-sm" x-data x-on:click="$dispatch('pos-drawer-open', { type: 'approval', id: @js($n['approval_id']) })">{{ $n['verb'] }}</button>
                                        @else
                                            <a href="{{ $n['url'] }}" wire:navigate class="pos-btn pos-btn-primary pos-btn-sm">{{ $n['verb'] }}</a>
                                        @endif
                                        <button type="button" class="pos-btn pos-btn-secondary pos-btn-sm" wire:click="notNow(@js($n['key']))">Not now</button>
                                    </div>
                                </div>
                            </article>
                        @endforeach
                    </section>
                @elseif ($h['focus']['counts']['attention'] === 0 && $h['focus']['counts']['today'] === 0)
                    <section class="pos-card pos-calm pos-enter-2" aria-live="polite">
                        <svg class="pos-check" viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12.5l4.2 4.2L19 7" /></svg>
                        <p class="pos-body-sm">You are up to date. New requests, approvals and reminders will appear here first.</p>
                    </section>
                @endif

                {{-- Your day + journey / team pulse --}}
                @if ($h['day'] !== null || $h['journey'] || $h['pulse_team'])
                    <div class="pos-home2-row">
                        @if ($h['day'] !== null)
                            <section class="pos-card pos-enter-3" aria-labelledby="pos-day-title">
                                <header class="pos-card-head">
                                    <h2 id="pos-day-title" class="pos-h3">Your day</h2>
                                    <a href="{{ \App\Filament\Pages\MyWork::getUrl(['tab' => 'today']) }}" wire:navigate class="pos-link">View all <span aria-hidden="true">→</span></a>
                                </header>
                                @if ($h['day'] === [])
                                    <p class="pos-body-sm mt-3">Nothing scheduled in PeopleOS today. One-on-ones, training sessions and things due today appear here.</p>
                                @else
                                    <ol class="pos-day mt-3">
                                        @foreach ($h['day'] as $d)
                                            <li class="pos-day-row" data-tone="{{ $d['tone'] }}">
                                                <span class="pos-day-time pos-num">{{ $d['time'] }}</span>
                                                <span class="pos-day-dot" aria-hidden="true"></span>
                                                <div class="min-w-0 flex-1">
                                                    <p class="pos-body font-medium truncate">{{ $d['title'] }}</p>
                                                    @if ($d['detail'])<p class="pos-caption truncate">{{ $d['detail'] }}</p>@endif
                                                </div>
                                                @if (($d['approval_id'] ?? null))
                                                    <button type="button" class="pos-day-action" x-data x-on:click="$dispatch('pos-drawer-open', { type: 'approval', id: @js($d['approval_id']) })">{{ $d['action'] }}</button>
                                                @elseif ($d['action'] && $d['url'])
                                                    <a href="{{ $d['url'] }}" @if (str_starts_with($d['url'], url('/')) || str_starts_with($d['url'], '/')) wire:navigate @else target="_blank" rel="noopener" @endif class="pos-day-action" data-action="{{ $d['action'] }}">{{ $d['action'] }}</a>
                                                @endif
                                            </li>
                                        @endforeach
                                    </ol>
                                @endif
                            </section>
                        @endif

                        @if ($h['pulse_team'])
                            @php($tp = $h['pulse_team'])
                            <section class="pos-card pos-enter-3" aria-labelledby="pos-tp-title">
                                <header class="pos-card-head">
                                    <h2 id="pos-tp-title" class="pos-h3">Team pulse</h2>
                                    @if ($tp['url'])<a href="{{ $tp['url'] }}" wire:navigate class="pos-link">View team <span aria-hidden="true">→</span></a>@endif
                                </header>
                                <div class="pos-pulse mt-3">
                                    <div><p class="pos-pulse-value pos-num">{{ $tp['size'] }}</p><p class="pos-caption">Direct reports</p></div>
                                    <div data-tone="rose"><p class="pos-pulse-value pos-num">{{ $tp['attention'] }}</p><p class="pos-caption">Need attention</p></div>
                                    <div><p class="pos-pulse-value pos-num">{{ $tp['away'] }}</p><p class="pos-caption">On leave</p></div>
                                    <div data-tone="amber"><p class="pos-pulse-value pos-num">{{ $tp['reviews'] }}</p><p class="pos-caption">Reviews to write</p></div>
                                </div>
                                <ul class="pos-avatar-stack mt-4" aria-label="Team members">
                                    @foreach ($tp['people'] as $p)
                                        <li><button type="button" title="{{ $p['name'] }}{{ $p['away'] ? ' (on leave)' : '' }}" x-data x-on:click="$dispatch('pos-drawer-open', { type: 'person', id: {{ $p['id'] }} })" class="{{ $p['away'] ? 'is-away' : '' }}"><x-pos.avatar :name="$p['name']" size="md" /><span class="sr-only">{{ $p['name'] }}</span></button></li>
                                    @endforeach
                                    @if ($tp['more'] > 0)<li><span class="pos-avatar pos-avatar-md pos-avatar-more">+{{ $tp['more'] }}</span></li>@endif
                                </ul>
                            </section>
                        @elseif (($h['journey_nodes'] ?? []) !== [])
                            @php($nodes = $h['journey_nodes'])
                            <section class="pos-card pos-enter-3" aria-labelledby="pos-jr-title">
                                <header class="pos-card-head">
                                    <h2 id="pos-jr-title" class="pos-h3">Your journey</h2>
                                    @if (\App\Filament\Pages\MyCareer::canAccess())<a href="{{ \App\Filament\Pages\MyCareer::getUrl() }}" wire:navigate class="pos-link">Career <span aria-hidden="true">→</span></a>@endif
                                </header>
                                <ol class="pos-journey-mini mt-4">
                                    @foreach ($nodes as $node)
                                        <li data-state="{{ $node['state'] }}"><span class="pos-journey-mini-dot" aria-hidden="true"></span><span class="pos-body-sm font-medium text-pos-text">{{ $node['label'] }}</span><span class="pos-caption">{{ $node['sub'] }}</span></li>
                                    @endforeach
                                </ol>
                                <p class="pos-inline-callout mt-4"><x-filament::icon icon="heroicon-m-sparkles" class="size-4" /> Growth comes from goals, learning and conversations. Your career page shows where they lead.</p>
                            </section>
                        @endif
                    </div>
                @endif

                {{-- Role panels kept from the operational Home (HR, executive, payroll, admin) --}}
                @if (($h['operations'] ?? null) !== null)
                    <section class="pos-card pos-enter-3" aria-labelledby="pos-ops-title">
                        <header class="pos-card-head">
                            <div><p class="pos-label">People operations</p><h2 id="pos-ops-title" class="pos-h3 mt-1">{{ count($h['operations']) === 0 ? 'Nothing is at risk today' : count($h['operations']).' areas need a look' }}</h2></div>
                        </header>
                        @if (count($h['operations']) > 0)
                            <ul class="pos-list mt-3">
                                @foreach ($h['operations'] as $op)
                                    <li class="pos-list-row pos-attention" data-severity="{{ $op['severity'] }}">
                                        <span class="pos-metric pos-num w-14 shrink-0 text-center">{{ $op['count'] }}</span>
                                        <div class="min-w-0 flex-1"><p class="pos-body font-medium">{{ $op['title'] }}</p><p class="pos-caption">{{ $op['why'] }}</p></div>
                                        @if ($op['url'])<a href="{{ $op['url'] }}" wire:navigate class="pos-btn pos-btn-ghost pos-btn-sm">Open</a>@endif
                                    </li>
                                @endforeach
                            </ul>
                        @else
                            <p class="pos-body-sm mt-2">Joiners, probation, onboarding, SLAs and exits are on track.</p>
                        @endif
                    </section>
                @endif

                @if ($h['pulse'] ?? null)
                    <section class="pos-card pos-enter-3" aria-labelledby="pos-pulse-title">
                        <header class="pos-card-head">
                            <div><p class="pos-label">Workforce pulse</p><h2 id="pos-pulse-title" class="pos-h3 mt-1">Headcount over six months</h2></div>
                            @if (\App\Filament\Pages\WorkforceCommandCentre::canAccess())<a href="{{ \App\Filament\Pages\WorkforceCommandCentre::getUrl() }}" wire:navigate class="pos-link">Command center <span aria-hidden="true">→</span></a>@endif
                        </header>
                        @if (count($h['pulse']['trend']) > 1)
                            <x-pos.sparkline :values="$h['pulse']['trend']" :labels="$h['pulse']['labels']" label="Headcount, last six months" class="mt-4" />
                        @endif
                    </section>
                @endif

                @if ($h['payroll'] ?? null)
                    <section class="pos-card pos-enter-3" aria-labelledby="pos-payroll-title">
                        <header class="pos-card-head">
                            <div><p class="pos-label">Payroll</p><h2 id="pos-payroll-title" class="pos-h3 mt-1">{{ $h['payroll']['run']['label'] ?? 'No payroll run yet' }}</h2></div>
                            @if ($h['payroll']['url'])<a href="{{ $h['payroll']['url'] }}" wire:navigate class="pos-btn pos-btn-secondary pos-btn-sm">Control room</a>@endif
                        </header>
                        @if ($h['payroll']['run'])
                            <div class="pos-metrics mt-4">
                                <div class="pos-metric-cell"><p class="pos-caption">Status</p><p class="pos-h3">{{ $h['payroll']['run']['status'] }}</p></div>
                                <div class="pos-metric-cell"><p class="pos-caption">Employees</p><p class="pos-metric pos-num">{{ number_format($h['payroll']['run']['employees']) }}</p></div>
                                <div class="pos-metric-cell"><p class="pos-caption">Exceptions</p><p class="pos-metric pos-num {{ $h['payroll']['run']['exceptions'] > 0 ? 'text-pos-danger' : '' }}">{{ $h['payroll']['run']['exceptions'] }}</p></div>
                            </div>
                        @endif
                    </section>
                @endif

                @if ($h['platform'] ?? null)
                    <section class="pos-card pos-enter-3" aria-labelledby="pos-platform-title">
                        <p class="pos-label">Platform</p>
                        <h2 id="pos-platform-title" class="pos-h3 mt-1">Configuration and integrations</h2>
                        <div class="pos-metrics mt-4">
                            <div class="pos-metric-cell"><p class="pos-caption">Changes awaiting approval</p><p class="pos-metric pos-num">{{ $h['platform']['pending_config'] }}</p></div>
                            <div class="pos-metric-cell"><p class="pos-caption">Integration dead letters</p><p class="pos-metric pos-num {{ $h['platform']['dead_letters'] > 0 ? 'text-pos-danger' : '' }}">{{ $h['platform']['dead_letters'] }}</p></div>
                            <div class="pos-metric-cell"><p class="pos-caption">Failed jobs</p><p class="pos-metric pos-num {{ $h['platform']['failed_jobs'] > 0 ? 'text-pos-danger' : '' }}">{{ $h['platform']['failed_jobs'] }}</p></div>
                        </div>
                        <div class="mt-4 flex flex-wrap gap-2">
                            @isset($h['platform']['links']['readiness'])<a href="{{ $h['platform']['links']['readiness'] }}" wire:navigate class="pos-btn pos-btn-secondary pos-btn-sm">Readiness</a>@endisset
                            @isset($h['platform']['links']['config'])<a href="{{ $h['platform']['links']['config'] }}" wire:navigate class="pos-btn pos-btn-ghost pos-btn-sm">Change Centre</a>@endisset
                            @isset($h['platform']['links']['integrations'])<a href="{{ $h['platform']['links']['integrations'] }}" wire:navigate class="pos-btn pos-btn-ghost pos-btn-sm">Inbound events</a>@endisset
                        </div>
                    </section>
                @endif

                {{-- Insights + company pulse --}}
                @if ($h['insights'] || $h['company'] !== [])
                    <div class="pos-home2-row">
                        @if ($h['insights'])
                            @php($in = $h['insights'])
                            <section class="pos-card pos-enter-3" aria-labelledby="pos-ins-title">
                                <header class="pos-card-head">
                                    <h2 id="pos-ins-title" class="pos-h3">Key insights</h2>
                                    <span class="pos-chip">{{ $in['scope'] }}</span>
                                </header>
                                <div class="pos-insights mt-3">
                                    @if ($in['attendance'])
                                        @php($delta = $in['attendance']['previous'] !== null ? $in['attendance']['current'] - $in['attendance']['previous'] : null)
                                        <div class="pos-insight">
                                            <p class="pos-caption">Attendance</p>
                                            <p class="pos-kpi-value pos-num">{{ $in['attendance']['current'] }}%</p>
                                            @if ($delta !== null)<p class="pos-caption {{ $delta >= 0 ? 'text-pos-success' : 'text-pos-danger' }}">{{ $delta >= 0 ? '↑' : '↓' }} {{ abs($delta) }} pts vs last month</p>@endif
                                            <x-pos.bars :items="$in['attendance']['months']" label="Attendance by month" class="mt-3" />
                                        </div>
                                    @endif
                                    @if ($in['learning'])
                                        @php($l = $in['learning'])
                                        <div class="pos-insight">
                                            <p class="pos-caption">Learning completion</p>
                                            <p class="pos-kpi-value pos-num">{{ $l['rate'] }}%</p>
                                            <div class="mt-3 flex items-center gap-4">
                                                <x-pos.donut :segments="[['label' => 'Completed', 'value' => $l['completed']], ['label' => 'In progress', 'value' => $l['in_progress']], ['label' => 'Not started', 'value' => $l['not_started']]]" label="Learning" :size="84" />
                                                <ul class="pos-legend">
                                                    <li><span style="background: var(--pos-chart-1)"></span>Completed <b class="pos-num">{{ $l['completed'] }}</b></li>
                                                    <li><span style="background: var(--pos-chart-2)"></span>In progress <b class="pos-num">{{ $l['in_progress'] }}</b></li>
                                                    <li><span style="background: var(--pos-chart-3)"></span>Not started <b class="pos-num">{{ $l['not_started'] }}</b></li>
                                                </ul>
                                            </div>
                                        </div>
                                    @endif
                                </div>
                            </section>
                        @endif
                        @if ($h['company'] !== [])
                            <section class="pos-card pos-enter-3" aria-labelledby="pos-cp-title">
                                <header class="pos-card-head">
                                    <h2 id="pos-cp-title" class="pos-h3">Company pulse</h2>
                                    @if (\App\Filament\Pages\AnnouncementsFeed::canAccess())<a href="{{ \App\Filament\Pages\AnnouncementsFeed::getUrl() }}" wire:navigate class="pos-link">View all <span aria-hidden="true">→</span></a>@endif
                                </header>
                                <ul class="mt-3 space-y-1">
                                    @foreach ($h['company'] as $c)
                                        <li>
                                            <a @if ($c['url']) href="{{ $c['url'] }}" wire:navigate @endif class="pos-company-row">
                                                <x-pos.tile-icon :tone="$c['tone']" :icon="$c['icon']" size="sm" />
                                                <span class="min-w-0 flex-1"><span class="pos-body-sm font-medium text-pos-text block truncate">{{ $c['title'] }}</span><span class="pos-caption block truncate">{{ $c['detail'] }}</span></span>
                                                @if ($c['when'])<span class="pos-caption whitespace-nowrap">{{ $c['when'] }}</span>@endif
                                            </a>
                                        </li>
                                    @endforeach
                                </ul>
                            </section>
                        @endif
                    </div>
                @endif

                {{-- Quick actions (the launcher's top picks for this lens) --}}
                @if (count($h['actions']) > 0)
                    <section class="pos-card pos-enter-3" aria-labelledby="pos-actions-title">
                        <header class="pos-card-head">
                            <h2 id="pos-actions-title" class="pos-h3">Start something</h2>
                            <button type="button" class="pos-link" x-data x-on:click="$dispatch('pos-command-open', { mode: 'actions' })">All actions <span aria-hidden="true">→</span></button>
                        </header>
                        <div class="pos-action-grid pos-action-grid-wide mt-3">
                            @foreach ($h['actions'] as $a)
                                @php($mount = ['request_leave' => 'requestLeave', 'regularise' => 'regularise', 'ask_hr' => 'askHr'][$a['key']] ?? null)
                                @if ($mount)
                                    <button type="button" wire:click="mountAction('{{ $mount }}')" class="pos-action-tile" data-pos-action="{{ $a['key'] }}">
                                        <x-pos.tile-icon :tone="['request_leave' => 'sky', 'regularise' => 'teal', 'ask_hr' => 'violet'][$a['key']] ?? 'indigo'" :icon="$a['icon']" />
                                        <span class="pos-action-text"><span class="pos-body font-medium">{{ $a['label'] }}</span><span class="pos-caption">{{ $a['hint'] }}</span></span>
                                    </button>
                                @else
                                    <a href="{{ $a['url'] }}" wire:navigate class="pos-action-tile" data-pos-action="{{ $a['key'] }}">
                                        <x-pos.tile-icon :tone="['Approve' => 'amber', 'Review' => 'indigo', 'Create' => 'emerald', 'Send' => 'violet', 'Generate' => 'gold', 'Schedule' => 'teal', 'Request' => 'sky'][$a['verb']] ?? 'indigo'" :icon="$a['icon']" />
                                        <span class="pos-action-text"><span class="pos-body font-medium">{{ $a['label'] }}</span><span class="pos-caption">{{ $a['hint'] }}</span></span>
                                    </a>
                                @endif
                            @endforeach
                        </div>
                    </section>
                @endif
            </div>

            {{-- Right rail: assistant, today, what changed --}}
            <aside class="pos-home2-rail" aria-label="Assistant and updates">
                @if (\App\Filament\Pages\AssistantPage::canAccess())
                    <livewire:experience.ai-assistant :embedded="true" />
                @endif

                @if ($h['me'] ?? null)
                    <div class="hidden xl:block">@include('filament.pages.partials.home-today', ['me' => $h['me'], 'suffix' => 'rail'])</div>
                @endif

                @if ($h['feed'])
                    <livewire:experience.change-feed-panel lazy :compact="true" :limit="6" />
                @endif
            </aside>
        </div>

        {{-- Organisation banner --}}
        <section class="pos-banner pos-enter-3" aria-label="{{ $h['tenant'] ?? 'Organisation' }}">
            <div class="min-w-0">
                <p class="pos-banner-title">{{ $h['tenant'] ?? 'Your organisation' }}</p>
                <p class="pos-banner-sub">People, work and decisions in one place. Everything here respects who can see what.</p>
            </div>
            @if ($h['banner'] !== [])
                <dl class="pos-banner-stats">
                    @foreach ($h['banner'] as $b)<div><dt>{{ $b['label'] }}</dt><dd class="pos-num">{{ $b['value'] }}</dd></div>@endforeach
                </dl>
            @endif
        </section>
    @endif
</x-filament-panels::page>
