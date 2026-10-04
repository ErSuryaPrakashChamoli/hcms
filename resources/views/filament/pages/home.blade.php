<x-filament-panels::page>
    @php
        $h = $this->home;
        $tenant = app(\App\Support\Tenancy\TenantContext::class)->has();
        $lensLabels = [
            'employee' => 'For you', 'manager' => 'Your team', 'hr' => 'People operations', 'hr_admin' => 'HR admin',
            'payroll' => 'Payroll', 'executive' => 'Company', 'system_admin' => 'Platform',
        ];
        $num = fn ($v) => rtrim(rtrim(number_format((float) $v, 1), '0'), '.');
        $kpi = fn (string $key) => collect($h['kpis'] ?? [])->firstWhere('key', $key);
    @endphp

    @if (! $tenant)
        {{-- Platform administrators outside a tenant --}}
        <div class="pos-ws-cols">
            <x-pos.section title="Tenants" :link="$this->platform['tenants_url']" link-label="Manage tenants">
                <div class="pos-panel pos-stream">
                    @forelse ($this->platform['tenants'] as $t)
                        @php($tStatus = $t->status instanceof \BackedEnum ? $t->status->value : (string) $t->status)
                        <div class="pos-stream-row" data-tone="{{ $tStatus === 'active' ? 'success' : 'warning' }}">
                            <x-pos.avatar :name="$t->name" size="sm" />
                            <div class="pos-stream-body"><p class="pos-stream-title">{{ $t->name }}</p><p class="pos-stream-meta">{{ $t->slug }}</p></div>
                            <x-pos.status :tone="$tStatus === 'active' ? 'success' : 'warning'" :label="ucfirst($tStatus)" />
                        </div>
                    @empty
                        <x-pos.state title="No tenants yet" why="Provision the first tenant to start." />
                    @endforelse
                </div>
            </x-pos.section>
            <x-pos.section title="Platform health">
                <div class="pos-panel pos-panel-pad grid gap-3">
                    <p class="pos-body pos-secondary">Readiness, audit chains, queues and statutory status.</p>
                    @if ($this->platform['readiness_url'])<a href="{{ $this->platform['readiness_url'] }}" wire:navigate class="pos-btn pos-btn-secondary pos-btn-sm justify-self-start">Open readiness</a>@endif
                </div>
            </x-pos.section>
        </div>
    @else
        <div class="pos-ws pos-home">
            {{-- Where am I, what matters, what can I do: the brief of the day comes from real counts only. --}}
            <header class="pos-ws-head">
                <div class="pos-ws-head-text">
                    <p class="pos-ws-eyebrow">{{ now()->format('l, j F') }}@if ($h['tenant']) · {{ $h['tenant'] }}@endif</p>
                    <h1 class="pos-ws-title pos-ws-title-display">{{ $this->getTitle() }}.</h1>
                    <p class="pos-ws-brief">{!! $this->briefHtml() !!}</p>
                </div>
                <div class="pos-ws-actions">
                    @if ($h['decision_count'] > 0 && \App\Filament\Pages\Approvals::canAccess())
                        <a href="{{ \App\Filament\Pages\Approvals::getUrl() }}" wire:navigate class="pos-btn pos-btn-primary">Review {{ $h['decision_count'] }}{{ ($h['decision_more'] ?? false) ? '+' : '' }} {{ $h['decision_count'] === 1 ? 'decision' : 'decisions' }}</a>
                    @elseif ($this->requestLeaveAction->isVisible() && $h['lens'] === 'employee')
                        <button type="button" wire:click="mountAction('requestLeave')" class="pos-btn pos-btn-primary" data-pos-action="request_leave_header">Request leave</button>
                    @endif
                    <button type="button" class="pos-btn pos-btn-secondary" x-data x-on:click="$dispatch('pos-command-open', { mode: 'actions' })">
                        <x-filament::icon icon="heroicon-m-plus" class="size-4" /> Start something
                    </button>
                </div>
            </header>

            @if (count($h['lenses']) > 1)
                <div class="pos-lens -mt-4" role="tablist" aria-label="View Home as">
                    <span class="pos-meta">View as</span>
                    @foreach ($h['lenses'] as $lens)
                        <button type="button" role="tab" class="pos-lens-chip" aria-selected="{{ $h['lens'] === $lens ? 'true' : 'false' }}" wire:click="switchLens('{{ $lens }}')">{{ $lensLabels[$lens] ?? $lens }}</button>
                    @endforeach
                </div>
            @endif

            {{-- First-login welcome: what is different here, once, then gone for good --}}
            @if ($this->showWelcome())
                <section class="pos-panel pos-panel-pad pos-welcome-note" aria-labelledby="pos-welcome-title" x-data="{ shown: true }" x-show="shown">
                    <span class="pos-ai-orb" aria-hidden="true"></span>
                    <div class="min-w-0 flex-1">
                        <h2 id="pos-welcome-title" class="pos-stream-title font-semibold">Welcome to PeopleOS. Your work comes to you here.</h2>
                        <p class="pos-stream-meta">What matters is ranked here with the reason for each item. Press <span class="pos-kbd">Ctrl K</span> to find anyone or start anything, and <span class="pos-kbd">?</span> for every shortcut. Hover a name to peek; click it for more.</p>
                    </div>
                    <div class="flex gap-2">
                        <button type="button" class="pos-btn pos-btn-ghost pos-btn-sm" x-data x-on:click="$dispatch('pos-shortcuts')">Shortcuts</button>
                        <button type="button" class="pos-btn pos-btn-secondary pos-btn-sm" wire:click="dismissWelcome" x-on:click="shown = false">Got it</button>
                    </div>
                </section>
            @endif

            <div class="pos-ws-cols">
                <div class="pos-ws-main">
                    {{-- Decisions waiting for you --}}
                    @if ($h['decision_count'] > 0 || in_array($h['lens'], ['manager', 'hr', 'hr_admin'], true))
                        <x-pos.section title="Decisions waiting for you" :count="$h['decision_count'] ? $h['decision_count'].(($h['decision_more'] ?? false) ? '+' : '') : null" :link="\App\Filament\Pages\Approvals::canAccess() ? \App\Filament\Pages\Approvals::getUrl() : null" link-label="Approval Center">
                            @if ($h['decisions']->isEmpty())
                                <x-pos.state variant="caught-up" size="inline" title="No decisions are waiting for you." why="Leave, attendance corrections, pay changes, letters and workflow steps that need you will appear here first." />
                            @else
                                <div class="pos-panel pos-stream">
                                    @foreach ($h['decisions'] as $item)
                                        @php($group = $item->group())
                                        <div class="pos-stream-row" data-tone="{{ $group === 'urgent' ? 'danger' : ($group === 'today' ? 'warning' : 'info') }}" wire:key="dec-{{ md5($item->id) }}" wire:transition>
                                            <span class="pos-stream-mark" aria-hidden="true"></span>
                                            <div class="pos-stream-body">
                                                <p class="pos-stream-title">{{ $item->title }}</p>
                                                <p class="pos-stream-meta flex flex-wrap items-center gap-x-2">
                                                    @if ($item->subject)<x-pos.person :id="$item->subjectEmployeeId" :name="$item->subject" />@endif
                                                    <span>{{ $item->typeLabel }}</span>
                                                    @if ($item->effectiveOn)<span>· from {{ $item->effectiveOn->format('D j M') }}</span>@elseif ($item->dueAt)<span>· due {{ $item->dueAt->diffForHumans() }}</span>@endif
                                                    @if ($group === 'urgent')<x-pos.status tone="danger" :label="$item->riskReason ?? 'Urgent'" />@endif
                                                </p>
                                            </div>
                                            <div class="pos-stream-end">
                                                <button type="button" class="pos-btn pos-btn-secondary pos-btn-sm" x-data x-on:click="$dispatch('pos-drawer-open', { type: 'approval', id: @js($item->id) })">Review</button>
                                            </div>
                                        </div>
                                    @endforeach
                                    @if ($h['decision_count'] > $h['decisions']->count())
                                        <div class="pos-stream-more"><a href="{{ \App\Filament\Pages\Approvals::getUrl() }}" wire:navigate class="pos-link">{{ $h['decision_count'] - $h['decisions']->count() }}{{ ($h['decision_more'] ?? false) ? '+' : '' }} more in the Approval Center</a></div>
                                    @endif
                                </div>
                            @endif
                        </x-pos.section>
                    @endif

                    {{-- Need attention: ranked, each with its reason and one verb --}}
                    @php($attention = collect($h['next'])->reject(fn ($n) => $n['approval_id']))
                    @if ($attention->isNotEmpty())
                        <x-pos.section title="Need attention" :count="$attention->count()" :link="\App\Filament\Pages\MyWork::getUrl()" link-label="My work">
                            <div class="pos-panel pos-stream">
                                @foreach ($attention as $n)
                                    <div class="pos-stream-row" data-tone="{{ $n['severity'] }}" wire:key="next-{{ md5($n['key']) }}" wire:transition>
                                        <span class="pos-stream-mark" aria-hidden="true"></span>
                                        <div class="pos-stream-body">
                                            <p class="pos-stream-title">{{ $n['title'] }}</p>
                                            <p class="pos-stream-meta">{{ $n['domain'] }}@if ($n['detail']) · {{ $n['detail'] }}@endif
                                                @if ($n['due']) · <span class="{{ $n['due']->isPast() ? 'text-pos-danger' : '' }}">{{ $n['due']->isPast() ? 'overdue '.$n['due']->diffForHumans(null, true) : 'due '.$n['due']->diffForHumans() }}</span>@endif</p>
                                        </div>
                                        <div class="pos-stream-end">
                                            <button type="button" class="pos-btn pos-btn-ghost pos-btn-sm" wire:click="notNow(@js($n['key']))">Not now</button>
                                            <a href="{{ $n['url'] }}" wire:navigate class="pos-btn pos-btn-secondary pos-btn-sm">{{ $n['verb'] }}</a>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </x-pos.section>
                    @endif

                    {{-- Your day: a timeline, with today's attendance as the first line (items already under Need attention are not repeated) --}}
                    @php($dayRows = $h['day'] === null ? null : collect($h['day'])->reject(fn ($d) => $attention->pluck('title')->contains($d['title']))->values()->all())
                    @if ($dayRows !== null)
                        <x-pos.section title="Your day" :link="\App\Filament\Pages\MyWork::getUrl(['tab' => 'today'])" link-label="View all">
                            <div class="pos-panel pos-panel-pad">
                                <div class="pos-tl">
                                    @if ($h['me'] ?? null)
                                        @php($me = $h['me'])
                                        <div class="pos-tl-row" data-now data-tone="{{ $me['checked_in'] ? 'success' : 'primary' }}" x-data="{ pulse: false }" x-on:pos-success.window="pulse = true; setTimeout(() => pulse = false, 800)" :class="pulse && 'pos-success-pulse'">
                                            <span class="pos-tl-time">Now</span>
                                            <span class="pos-tl-dot" aria-hidden="true"></span>
                                            <div class="pos-stream-body">
                                                <p class="pos-stream-title">
                                                    @if ($me['checked_in']) Checked in at {{ $me['first_in']?->format('H:i') }}
                                                    @elseif ($me['last_out']) Checked out at {{ $me['last_out']->format('H:i') }}
                                                    @else Not checked in yet @endif
                                                </p>
                                                <p class="pos-stream-meta">
                                                    @if ($me['status'])Attendance: {{ $me['status'] }}@endif
                                                    @if ($me['next_leave'])@if ($me['status']) · @endif Next leave: {{ $me['next_leave']['label'] }} ({{ $me['next_leave']['status'] }})@endif
                                                </p>
                                            </div>
                                            <div class="pos-stream-end">
                                                @if ($me['checked_in'])
                                                    <button type="button" wire:click="punch('out')" wire:loading.attr="disabled" class="pos-btn pos-btn-secondary pos-btn-sm">Check out</button>
                                                @else
                                                    <button type="button" wire:click="punch('in')" wire:loading.attr="disabled" class="pos-btn pos-btn-secondary pos-btn-sm">Check in</button>
                                                @endif
                                                @if ($me['payslip'] ?? null)
                                                    <a href="{{ $me['payslip']['url'] }}" wire:navigate class="pos-btn pos-btn-ghost pos-btn-sm" data-pos-action="payslip">Payslip</a>
                                                @endif
                                            </div>
                                        </div>
                                    @endif
                                    @forelse ($dayRows as $d)
                                        <div class="pos-tl-row" data-tone="{{ ['violet' => 'primary', 'teal' => 'info', 'sky' => 'info', 'amber' => 'warning', 'rose' => 'danger', 'emerald' => 'success'][$d['tone']] ?? 'primary' }}">
                                            <span class="pos-tl-time">{{ $d['time'] }}</span>
                                            <span class="pos-tl-dot" aria-hidden="true"></span>
                                            <div class="pos-stream-body">
                                                <p class="pos-stream-title">{{ $d['title'] }}</p>
                                                @if ($d['detail'])<p class="pos-stream-meta">{{ $d['detail'] }}</p>@endif
                                            </div>
                                            <div class="pos-stream-end">
                                                @if (($d['approval_id'] ?? null))
                                                    <button type="button" class="pos-btn pos-btn-ghost pos-btn-sm" x-data x-on:click="$dispatch('pos-drawer-open', { type: 'approval', id: @js($d['approval_id']) })">{{ $d['action'] }}</button>
                                                @elseif ($d['action'] && $d['url'])
                                                    <a href="{{ $d['url'] }}" @if (str_starts_with($d['url'], url('/')) || str_starts_with($d['url'], '/')) wire:navigate @else target="_blank" rel="noopener" @endif class="pos-btn pos-btn-ghost pos-btn-sm" data-action="{{ $d['action'] }}">{{ $d['action'] }}</a>
                                                @endif
                                            </div>
                                        </div>
                                    @empty
                                        @if (! ($h['me'] ?? null))
                                            <x-pos.state variant="empty" size="inline" title="Nothing scheduled in PeopleOS today." why="One-on-ones, training sessions, leave and things due today appear here." />
                                        @else
                                            <p class="pos-meta pos-tl-note">Nothing else is scheduled in PeopleOS today.</p>
                                        @endif
                                    @endforelse
                                </div>
                            </div>
                        </x-pos.section>
                    @endif

                    {{-- What changed since your last visit (business events, each opens in context) --}}
                    @if ($h['changes'] !== null)
                        @php($c = $h['changes'])
                        <x-pos.section title="What changed" :count="$c['count'] ?: null"
                            :sub="$c['count'] === 0 ? null : (($c['since'] ? 'Since your last visit on '.$c['since']->format('j M') : 'In the last seven days').': '.$c['summary'].'.')">
                            @if ($c['count'] === 0)
                                <x-pos.state variant="caught-up" size="inline" :title="$c['since'] ? 'Nothing changed since your last visit.' : 'Nothing changed in the last seven days.'" why="Joiners, moves, reporting changes, exits and announcements you can see appear here." />
                            @else
                                <div class="pos-panel pos-stream">
                                    @foreach ($c['items'] as $item)
                                        <button type="button" class="pos-stream-row" data-tone="{{ $item['tone'] === 'primary' ? 'info' : $item['tone'] }}" x-data x-on:click="$dispatch('pos-drawer-open', { type: 'change', id: @js($item['id']) })">
                                            <span class="pos-stream-icon" aria-hidden="true"><x-filament::icon :icon="$item['icon']" class="size-4" /></span>
                                            <span class="pos-stream-body">
                                                <span class="pos-stream-title">{{ $item['title'] }}</span>
                                                <span class="pos-stream-meta">{{ $item['label'] }}@if ($item['subject']) · {{ $item['subject'] }}@endif · {{ $item['at']->isToday() ? 'today' : $item['at']->diffForHumans() }}</span>
                                            </span>
                                            <span aria-hidden="true" class="pos-muted">→</span>
                                        </button>
                                    @endforeach
                                    @if ($c['count'] > $c['items']->count() && \App\Filament\Pages\ChangeIntelligencePage::canAccess())
                                        <div class="pos-stream-more"><a href="{{ \App\Filament\Pages\ChangeIntelligencePage::getUrl() }}" wire:navigate class="pos-link">All {{ $c['count'] }} changes</a></div>
                                    @endif
                                </div>
                            @endif
                        </x-pos.section>
                    @endif

                    {{-- People operations (HR): figures for the lens, then the reasons --}}
                    @if (($h['operations'] ?? null) !== null)
                        <x-pos.section title="People operations" :count="count($h['operations']) ?: null">
                            <div class="pos-panel">
                                <div class="pos-panel-pad pos-figures">
                                    @foreach (['attention', 'joining', 'on_leave', 'approvals', 'requests'] as $k)
                                        @if ($f = $kpi($k))<x-pos.figure :value="$f['value']" :label="$f['label']" :href="$f['url'] ?? null" />@endif
                                    @endforeach
                                </div>
                                @if (count($h['operations']) > 0)
                                    <div class="pos-stream pos-stream-divided">
                                        @foreach ($h['operations'] as $op)
                                            <div class="pos-stream-row" data-tone="{{ $op['severity'] }}">
                                                <span class="pos-figure-value w-14 text-center">{{ $op['count'] }}</span>
                                                <div class="pos-stream-body"><p class="pos-stream-title">{{ $op['title'] }}</p><p class="pos-stream-meta">{{ $op['why'] }}</p></div>
                                                @if ($op['url'])<a href="{{ $op['url'] }}" wire:navigate class="pos-btn pos-btn-ghost pos-btn-sm">Open</a>@else<span></span>@endif
                                            </div>
                                        @endforeach
                                    </div>
                                @else
                                    <x-pos.state variant="caught-up" size="inline" title="Nothing is at risk today." why="Joiners, probation, onboarding, SLAs and exits are on track." />
                                @endif
                            </div>
                        </x-pos.section>
                    @endif

                    {{-- Workforce pulse (executive) --}}
                    @if ($h['pulse'] ?? null)
                        <x-pos.section title="Workforce pulse" :link="\App\Filament\Pages\WorkforceCommandCentre::canAccess() ? \App\Filament\Pages\WorkforceCommandCentre::getUrl() : null" link-label="Open Workforce pulse">
                            <div class="pos-panel pos-panel-pad grid gap-4">
                                <div class="pos-figures">
                                    @foreach (['headcount', 'joiners', 'exits', 'attrition', 'on_leave'] as $k)
                                        @if ($f = $kpi($k))<x-pos.figure :value="$f['value']" :label="$f['label']" :href="$f['url'] ?? null" />@endif
                                    @endforeach
                                </div>
                                @if (count($h['pulse']['trend']) > 1)
                                    <x-pos.sparkline :values="$h['pulse']['trend']" :labels="$h['pulse']['labels']" label="Headcount, last six months" />
                                @endif
                            </div>
                        </x-pos.section>
                    @endif

                    {{-- Payroll --}}
                    @if ($h['payroll'] ?? null)
                        <x-pos.section title="Payroll" :link="$h['payroll']['url']" link-label="Control room">
                            <div class="pos-panel pos-panel-pad grid gap-3">
                                <p class="pos-section-title">{{ $h['payroll']['run']['label'] ?? 'No payroll run yet' }}</p>
                                @if ($h['payroll']['run'])
                                    <div class="pos-figures">
                                        <x-pos.figure :value="$h['payroll']['run']['status']" label="Status" />
                                        <x-pos.figure :value="number_format($h['payroll']['run']['employees'])" label="Employees" />
                                        <x-pos.figure :value="$h['payroll']['run']['exceptions']" label="Exceptions" :meaning="$h['payroll']['run']['exceptions'] > 0 ? 'bad' : null" :delta="$h['payroll']['run']['exceptions'] > 0 ? 'Resolve before sign-off' : null" />
                                    </div>
                                @else
                                    <p class="pos-meta">Open a run for the next pay period from the control room.</p>
                                @endif
                            </div>
                        </x-pos.section>
                    @endif

                    {{-- Platform (administrators) --}}
                    @if ($h['platform'] ?? null)
                        <x-pos.section title="Platform" :link="$h['platform']['links']['readiness'] ?? null" link-label="Readiness">
                            <div class="pos-panel pos-panel-pad pos-figures">
                                <x-pos.figure :value="$h['platform']['pending_config']" label="Changes awaiting approval" :href="$h['platform']['links']['config'] ?? null" />
                                <x-pos.figure :value="$h['platform']['dead_letters']" label="Integration dead letters" :href="$h['platform']['links']['integrations'] ?? null" />
                                <x-pos.figure :value="$h['platform']['failed_jobs']" label="Failed jobs" />
                            </div>
                        </x-pos.section>
                    @endif
                </div>

                <aside class="pos-ws-side" aria-label="Your people and momentum">
                    <x-pos.intelligence :intel="$this->intelligence" />
                    {{-- Team pulse (managers): who is in, who needs you --}}
                    @if ($h['pulse_team'])
                        @php($tp = $h['pulse_team'])
                        <x-pos.section title="Team pulse" :link="$tp['url']" link-label="Your team">
                            <div class="pos-panel pos-panel-pad grid gap-4">
                                <div class="pos-figures">
                                    @foreach (['team_in', 'team_away', 'approvals', 'attention'] as $k)
                                        @if ($f = $kpi($k))<x-pos.figure :value="$f['value']" :label="$f['label']" :href="$f['url'] ?? null" />@endif
                                    @endforeach
                                    @if (! $kpi('team_in'))<x-pos.figure :value="$tp['size']" label="Direct reports" />@endif
                                </div>
                                <ul class="pos-people-strip" aria-label="Your team">
                                    @foreach ($tp['people'] as $p)
                                        <li><x-pos.person :id="$p['id']" :name="$p['name']" :sub="$p['away'] ? 'away' : null" /></li>
                                    @endforeach
                                    @if ($tp['more'] > 0)<li class="pos-meta self-center">+{{ $tp['more'] }} more</li>@endif
                                </ul>
                                @if ($tp['reviews'] > 0)<p class="pos-meta">{{ $tp['reviews'] }} {{ $tp['reviews'] === 1 ? 'review' : 'reviews' }} to write.</p>@endif
                            </div>
                        </x-pos.section>
                    @endif

                    {{-- Your people (employees): the people you work with most --}}
                    @if (($h['circle'] ?? []) !== [])
                        <x-pos.section title="Your people">
                            <div class="pos-panel pos-stream">
                                @foreach ($h['circle'] as $p)
                                    <div class="pos-stream-row">
                                        <x-pos.person :id="$p['id']" :name="$p['name']" size="sm" />
                                        <span class="pos-stream-meta">{{ $p['role'] }}</span>
                                        <span></span>
                                    </div>
                                @endforeach
                            </div>
                        </x-pos.section>
                    @endif

                    {{-- Your momentum (employees): where you are and how things are moving --}}
                    @if ($h['lens'] === 'employee')
                        <x-pos.section title="Your momentum" :link="\App\Filament\Pages\MyCareer::canAccess() ? \App\Filament\Pages\MyCareer::getUrl() : null" link-label="Career">
                            <div class="pos-panel pos-panel-pad grid gap-4">
                                @if (($h['journey_nodes'] ?? []) !== [])
                                    <ol class="pos-journey-mini" aria-label="Your journey">
                                        @foreach ($h['journey_nodes'] as $node)
                                            <li data-state="{{ $node['state'] }}"><span class="pos-journey-mini-dot" aria-hidden="true"></span><span class="pos-meta font-medium text-pos-text">{{ $node['label'] }}</span><span class="pos-caption">{{ $node['sub'] }}</span></li>
                                        @endforeach
                                    </ol>
                                @endif
                                <div class="pos-figures">
                                    @if ($f = $kpi('balance'))
                                        <x-pos.figure :value="$f['value'].' days'" :label="$f['label'].' left'.(isset($f['ring']) ? ' of '.$num($f['ring']['total']) : '')" />
                                    @endif
                                    @foreach (['attention', 'waiting', 'learning', 'present'] as $k)
                                        @if ($f = $kpi($k))<x-pos.figure :value="$f['value']" :label="$f['label']" :href="$f['url'] ?? null" />@endif
                                    @endforeach
                                </div>
                            </div>
                        </x-pos.section>
                    @endif

                    {{-- Start something: the actions this person most often starts --}}
                    @if (count($h['actions']) > 0)
                        <x-pos.section title="Start something">
                            <div class="pos-panel pos-stream">
                                @foreach ($h['actions'] as $a)
                                    @php($mount = ['request_leave' => 'requestLeave', 'regularise' => 'regularise', 'ask_hr' => 'askHr'][$a['key']] ?? null)
                                    @if ($mount)
                                        <button type="button" wire:click="mountAction('{{ $mount }}')" class="pos-stream-row" data-pos-action="{{ $a['key'] }}">
                                            <span class="pos-stream-icon" aria-hidden="true"><x-filament::icon :icon="$a['icon']" class="size-4" /></span>
                                            <span class="pos-stream-body"><span class="pos-stream-title">{{ $a['label'] }}</span><span class="pos-stream-meta">{{ $a['hint'] }}</span></span>
                                            <span aria-hidden="true" class="pos-muted">→</span>
                                        </button>
                                    @elseif (str_starts_with($a['url'], '#pick:'))
                                        <button type="button" class="pos-stream-row" data-pos-action="{{ $a['key'] }}" x-data x-on:click="$dispatch('pos-command-open', { mode: 'people', pick: @js(substr($a['url'], 6)) })">
                                            <span class="pos-stream-icon" aria-hidden="true"><x-filament::icon :icon="$a['icon']" class="size-4" /></span>
                                            <span class="pos-stream-body"><span class="pos-stream-title">{{ $a['label'] }}</span><span class="pos-stream-meta">{{ $a['hint'] }}</span></span>
                                            <span aria-hidden="true" class="pos-muted">→</span>
                                        </button>
                                    @else
                                        <a href="{{ $a['url'] }}" wire:navigate class="pos-stream-row" data-pos-action="{{ $a['key'] }}">
                                            <span class="pos-stream-icon" aria-hidden="true"><x-filament::icon :icon="$a['icon']" class="size-4" /></span>
                                            <span class="pos-stream-body"><span class="pos-stream-title">{{ $a['label'] }}</span><span class="pos-stream-meta">{{ $a['hint'] }}</span></span>
                                            <span aria-hidden="true" class="pos-muted">→</span>
                                        </a>
                                    @endif
                                @endforeach
                                <div class="pos-stream-more"><button type="button" class="pos-link" x-data x-on:click="$dispatch('pos-command-open', { mode: 'actions' })">All actions</button></div>
                            </div>
                        </x-pos.section>
                    @endif
                </aside>
            </div>
        </div>
    @endif
</x-filament-panels::page>
