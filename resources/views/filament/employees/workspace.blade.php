@php
    /** @var \App\Domain\Employment\Models\Employee $record */
    $record = $getRecord();
    $w = $getState() ?? [];
    $sections = collect($w['sections'] ?? []);
    $now = $w['now'] ?? [];
    $p = $record->currentPosition;
    $facts = fn (string $key) => $sections->get($key)['facts'] ?? null;
    $groups = [
        'growth' => ['performance', 'goals', 'learning', 'skills', 'career', 'talent', 'succession'],
        'rewards' => ['compensation', 'payroll'],
        'documents' => ['documents', 'letters', 'communication', 'engagement', 'exit', 'alumni'],
    ];
    $toneOf = ['lifecycle' => 'primary', 'onboarding' => 'success', 'exit' => 'danger', 'position' => 'accent', 'reporting' => 'info', 'compensation' => 'accent', 'performance' => 'primary', 'learning' => 'success'];
@endphp
<div class="pos-360-ws">
    {{--
        UX.15 closure P1-04: one coherent person workspace with progressive disclosure. NOW (always) holds the core:
        state and what happens next in one panel, the latest changes, intelligence and the snapshot. Journey, Work,
        Growth, Rewards, Documents and Records are views one step away (the section navigation switches them; every
        #hash link still works). Everything is rendered by the same gates as before; only what is shown at once changes.
    --}}
    @php($rel = $w['relationships'] ?? ['up' => [], 'down' => [], 'reports' => 0])
    @php($line = collect($rel['up'])->firstWhere('type', 'line'))
    <section id="now" class="pos-360-sec" aria-labelledby="now-title" x-data x-show="$store.pos360 ? $store.pos360.view === 'now' : true">
        <h2 id="now-title" class="sr-only">Now</h2>
        {{--
            UX.17: one order for every size. On phones and tablets: Now, then what this viewer is here for (the UX.16
            panel, which used to sit two screens down), intelligence and the snapshot, then Recently. On desktop the
            aside spans both rows beside Now and Recently, as before.
        --}}
        <div class="pos-ws-cols pos-360-now">
            <div class="pos-ws-main">
                {{-- Now and next: where this person is today and what happens next, in one place --}}
                <div class="pos-panel pos-panel-pad grid gap-4">
                    <div class="grid gap-1">
                        <p class="pos-ws-eyebrow">Now</p>
                        @if ($now['probation'] ?? null)
                            @php($pr = $now['probation'])
                            <p class="pos-section-title">{{ $pr['overdue'] ? 'Probation ended '.abs($pr['days']).' '.(abs($pr['days']) === 1 ? 'day' : 'days').' ago without a decision' : 'In probation · ends in '.$pr['days'].' '.($pr['days'] === 1 ? 'day' : 'days') }}</p>
                            <p class="pos-meta">Probation end date {{ $pr['ends']->format('j M Y') }}. Confirmation is recorded through a lifecycle change.</p>
                        @else
                            <p class="pos-section-title">{{ $now['state'] ?? 'No lifecycle state' }}@if ($now['confirmed'] ?? null) · confirmed {{ $now['confirmed']->format('j M Y') }}@endif</p>
                        @endif
                    </div>
                    @php($today = collect(['attendance', 'leave', 'service'])->filter(fn ($k) => $facts($k) !== null))
                    @if ($today->isNotEmpty())
                        {{-- UX.17: a compact grid of figures on phones, so the viewer panel comes sooner --}}
                        <div class="pos-figures pos-figures-compact">
                            @foreach ($today as $k)
                                @foreach (array_slice($facts($k), 0, 2, true) as $label => $value)
                                    <x-pos.figure :value="$value ?? '—'" :label="$label" />
                                @endforeach
                            @endforeach
                        </div>
                    @endif
                    <div class="pos-360-next" aria-labelledby="next-title">
                        <p id="next-title" class="pos-label">What’s next @if (count($w['next'] ?? []))<span class="pos-sec-count">{{ count($w['next']) }}</span>@endif</p>
                        @if (($w['next'] ?? []) === [])
                            <p class="pos-meta">Nothing scheduled ahead in PeopleOS. Probation, leave, onboarding steps, learning due dates and a last working day appear here when they apply and you can see them.</p>
                        @else
                            <div class="pos-tl">
                                @foreach ($w['next'] as $n)
                                    <div class="pos-tl-row" data-tone="{{ $n['tone'] }}">
                                        <span class="pos-tl-time">{{ $n['date']?->isToday() ? 'Today' : $n['date']?->format('D j M') }}</span>
                                        <span class="pos-tl-dot" aria-hidden="true"></span>
                                        <div class="pos-stream-body"><p class="pos-stream-title">{{ $n['title'] }}</p>@if ($n['detail'])<p class="pos-stream-meta">{{ $n['detail'] }}</p>@endif</div>
                                        <span></span>
                                    </div>
                                @endforeach
                            </div>
                        @endif
                    </div>
                </div>
            </div>
            <aside class="pos-ws-side" aria-label="People snapshot">
                {{-- UX.16: what this viewer is here for (self, manager, HR or administrator), from PersonWorkspace::viewer --}}
                @if ($v = ($w['viewer'] ?? null))
                    <x-pos.section :title="$v['title']">
                        <div class="pos-panel pos-panel-pad grid gap-3 pos-360-viewer" data-viewer="{{ $v['as'] }}">
                            <p class="pos-body pos-secondary">{{ $v['line'] }}</p>
                            @if ($v['facts'] !== [])
                                <dl class="pos-facts">
                                    @foreach ($v['facts'] as $fact)
                                        <div><dt>{{ $fact['label'] }}</dt><dd>{{ $fact['value'] }}</dd></div>
                                    @endforeach
                                </dl>
                            @endif
                            @if ($v['links'] !== [])
                                <div class="flex flex-wrap gap-2">
                                    @foreach ($v['links'] as $link)
                                        <a href="{{ $link['url'] }}" @if (! str_starts_with($link['url'], '#')) wire:navigate @endif class="pos-btn pos-btn-secondary pos-btn-sm">{{ $link['label'] }}</a>
                                    @endforeach
                                </div>
                            @endif
                        </div>
                    </x-pos.section>
                @endif
                <x-pos.intelligence :intel="method_exists($getLivewire(), 'intelligence') ? $getLivewire()->intelligence : null" />
                <x-pos.section title="People snapshot">
                    <x-slot:actions><a href="#work" class="pos-link">Work and relationships <span aria-hidden="true">→</span></a></x-slot:actions>
                    <dl class="pos-panel pos-panel-pad pos-facts">
                        <div><dt>Employee ID</dt><dd class="pos-num">{{ $record->employee_code }}</dd></div>
                        @if ($record->work_email)<div><dt>Work email</dt><dd><a class="pos-link" href="mailto:{{ $record->work_email }}">{{ $record->work_email }}</a></dd></div>@endif
                        @if ($record->work_phone)<div><dt>Work phone</dt><dd>{{ $record->work_phone }}</dd></div>@endif
                        @if ($record->joining_date)<div><dt>Joined</dt><dd>{{ $record->joining_date->format('j M Y') }}</dd></div>@endif
                        @if ($p?->effective_from)<div><dt>In role since</dt><dd>{{ $p->effective_from->format('j M Y') }}</dd></div>@endif
                        @if (($rel['reports'] ?? 0) > 0)<div><dt>Direct reports</dt><dd class="pos-num">{{ $rel['reports'] }}</dd></div>@endif
                        @if (count($rel['up']) > ($line ? 1 : 0))<div><dt>Also works with</dt><dd>{{ count($rel['up']) - ($line ? 1 : 0) }} more {{ count($rel['up']) - ($line ? 1 : 0) === 1 ? 'relationship' : 'relationships' }}</dd></div>@endif
                    </dl>
                </x-pos.section>
            </aside>
            <div class="pos-ws-main">
                {{-- The latest changes; the full story is one step away in Journey --}}
                <x-pos.section title="Recently" :count="count($w['changes'] ?? []) ?: null">
                    <x-slot:actions><a href="#journey" class="pos-link">Full journey <span aria-hidden="true">→</span></a></x-slot:actions>
                    @if (($w['changes'] ?? []) === [])
                        <x-pos.state variant="empty" size="inline" title="No recent changes you can see." />
                    @else
                        <div class="pos-panel pos-stream">
                            @foreach (array_slice($w['changes'], 0, 3) as $c)
                                <div class="pos-stream-row" data-tone="{{ $toneOf[$c['category']] ?? 'info' }}">
                                    <span class="pos-stream-mark" aria-hidden="true"></span>
                                    <div class="pos-stream-body"><p class="pos-stream-title">{{ $c['title'] }}</p><p class="pos-stream-meta">{{ $c['label'] }} · {{ $c['date']?->format('j M Y') }}</p></div>
                                    <span></span>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </x-pos.section>
            </div>
        </div>
    </section>

    {{-- JOURNEY: one person, one continuous journey --}}
    <section id="journey" class="pos-360-sec" aria-labelledby="journey-title" x-data x-cloak x-show="$store.pos360 ? $store.pos360.view === 'journey' : true">
        <x-pos.section title="Journey" id="journey-title" sub="Where this person is in their working life. Select a stage to see what happened in it.">
            <div class="pos-panel pos-panel-pad">
                <x-pos.journey :journey="$w['journey'] ?? []" />
            </div>
        </x-pos.section>
        <x-pos.section title="What changed" :count="count($w['changes'] ?? []) ?: null" sub="Recent events in this person’s working life, with the same visibility as their timeline.">
            @if (($w['changes'] ?? []) === [])
                <x-pos.state variant="empty" size="inline" title="No recent changes you can see." />
            @else
                <div class="pos-panel pos-stream">
                    @foreach ($w['changes'] as $c)
                        <div class="pos-stream-row" data-tone="{{ $toneOf[$c['category']] ?? 'info' }}">
                            <span class="pos-stream-mark" aria-hidden="true"></span>
                            <div class="pos-stream-body"><p class="pos-stream-title">{{ $c['title'] }}</p><p class="pos-stream-meta">{{ $c['label'] }} · {{ $c['date']?->format('j M Y') }}@if ($c['detail']) · {{ \Illuminate\Support\Str::limit($c['detail'], 120) }}@endif</p></div>
                            <span></span>
                        </div>
                    @endforeach
                </div>
            @endif
        </x-pos.section>
    </section>

    {{-- WORK: position, organisation and relationships of every type --}}
    <section id="work" class="pos-360-sec" aria-labelledby="work-title" x-data x-cloak x-show="$store.pos360 ? $store.pos360.view === 'work' : true">
        <h2 id="work-title" class="sr-only">Work</h2>
        <div class="pos-ws-cols">
            <x-pos.section title="Position">
                <div class="pos-panel pos-panel-pad grid gap-4">
                    <dl class="pos-fact-grid">
                        <div><dt>Designation</dt><dd>{{ $p?->designation?->name ?? '—' }}</dd></div>
                        <div><dt>Department</dt><dd>{{ $p?->department?->name ?? '—' }}</dd></div>
                        <div><dt>Location</dt><dd>{{ $p?->location?->name ?? '—' }}</dd></div>
                        <div><dt>Grade</dt><dd>{{ $p?->grade?->name ?? '—' }}</dd></div>
                        <div><dt>Employment type</dt><dd>{{ $p?->employmentType?->name ?? '—' }}</dd></div>
                        <div><dt>Work mode</dt><dd>{{ $p?->workMode?->name ?? '—' }}</dd></div>
                    </dl>
                    <div class="grid gap-1"><p class="pos-label">Organisation</p>
                        <p class="pos-body">{{ collect([$p?->company?->name, $p?->businessUnit?->name, $p?->division?->name, $p?->department?->name, $p?->team?->name])->filter()->implode(' › ') ?: '—' }}</p></div>
                </div>
            </x-pos.section>
            <x-pos.section title="Relationships" sub="Line, functional, dotted-line, project, mentor, buddy and HR partner relationships in effect today.">
                <div class="pos-panel pos-stream">
                    @forelse ($rel['up'] as $r)
                        <div class="pos-stream-row" data-tone="{{ $r['type'] === 'line' ? 'primary' : 'info' }}">
                            <span class="pos-stream-mark" aria-hidden="true"></span>
                            <x-pos.person :id="$r['id']" :name="$r['name']" size="sm" />
                            <span class="pos-stream-meta">{{ $r['label'] }}</span>
                        </div>
                    @empty
                        <div class="pos-stream-row"><span></span><span class="pos-stream-meta">No manager relationship in effect.</span><span></span></div>
                    @endforelse
                    @if ($rel['down'] !== [])
                        <div class="pos-stream-row pos-stream-divided"><span></span><span class="pos-label">Works with them · {{ $rel['reports'] }} direct {{ $rel['reports'] === 1 ? 'report' : 'reports' }}</span><span></span></div>
                        @foreach ($rel['down'] as $r)
                            <div class="pos-stream-row">
                                <span class="pos-stream-mark" aria-hidden="true"></span>
                                <x-pos.person :id="$r['id']" :name="$r['name']" size="sm" />
                                <span class="pos-stream-meta">{{ $r['type'] === 'line' ? 'Direct report' : $r['label'].' of' }}</span>
                            </div>
                        @endforeach
                    @endif
                </div>
            </x-pos.section>
        </div>
    </section>

    {{-- GROWTH · REWARDS · DOCUMENTS: one permission-aware summary per domain (the 360 overview) --}}
    @foreach (['growth' => ['Growth', 'Performance, goals, learning, skills, career and talent.'], 'rewards' => ['Rewards', 'Pay and compensation. Amounts stay in their audited records.'], 'documents' => ['Documents', 'Documents, letters, communications and transitions.']] as $id => [$title, $sub])
        @php($items = collect($groups[$id])->filter(fn ($k) => $sections->has($k))->map(fn ($k) => $sections[$k]))
        <section id="{{ $id }}" class="pos-360-sec" aria-labelledby="{{ $id }}-title" x-data x-cloak x-show="$store.pos360 ? $store.pos360.view === @js($id) : true">
            <x-pos.section :title="$title" :id="$id.'-title'" :sub="$sub">
                <p class="pos-ws-eyebrow -mt-1">360 overview</p>
                @if ($items->isEmpty())
                    <x-pos.state variant="denied" size="inline" title="Nothing here you can see." why="Each area follows its own access rules; your role does not include {{ mb_strtolower($title) }} for this person." />
                @else
                    <div class="pos-360-cards">
                        @foreach ($items as $section)
                            <div class="pos-panel pos-panel-pad">
                                <div class="flex items-baseline justify-between gap-2"><p class="pos-stream-title font-semibold">{{ $section['label'] }}</p><p class="pos-caption">{{ $section['owner'] }}</p></div>
                                <dl class="mt-2 grid gap-1">
                                    @foreach ($section['facts'] as $label => $value)
                                        <div class="flex justify-between gap-3 pos-body-sm"><dt>{{ $label }}</dt><dd class="text-end font-medium text-pos-text pos-num">{{ $value ?? '—' }}</dd></div>
                                    @endforeach
                                </dl>
                            </div>
                        @endforeach
                    </div>
                @endif
            </x-pos.section>
        </section>
    @endforeach

    {{-- RECORDS: the system-of-record detail, by area, below --}}
    <section id="records" class="pos-360-sec" aria-labelledby="records-title" x-data x-cloak x-show="$store.pos360 ? $store.pos360.view === 'records' : true">
        <x-pos.section title="Records" id="records-title" sub="Every detail as recorded, by area: all details, additional information, applicable policies and the governed records below. Changes go through the same governed actions and are audited." />
    </section>
</div>
