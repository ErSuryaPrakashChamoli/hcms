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
    {{-- NOW: where this person is today, and what happens next --}}
    <section id="now" class="pos-360-sec" aria-labelledby="now-title">
        <h2 id="now-title" class="sr-only">Now</h2>
        <div class="pos-ws-cols">
            <div class="pos-ws-main">
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
                        <div class="pos-figures">
                            @foreach ($today as $k)
                                @foreach (array_slice($facts($k), 0, 2, true) as $label => $value)
                                    <x-pos.figure :value="$value ?? '—'" :label="$label" />
                                @endforeach
                            @endforeach
                        </div>
                    @endif
                </div>

                <x-pos.section title="What’s next" :count="count($w['next'] ?? []) ?: null">
                    @if (($w['next'] ?? []) === [])
                        <x-pos.state variant="empty" size="inline" title="Nothing scheduled ahead in PeopleOS." why="Probation, leave, onboarding steps, learning due dates and a last working day appear here when they apply and you can see them." />
                    @else
                        <div class="pos-panel pos-panel-pad">
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
                        </div>
                    @endif
                </x-pos.section>
            </div>
            <aside class="pos-ws-side" aria-label="People snapshot">
                <x-pos.section title="People snapshot">
                    <dl class="pos-panel pos-panel-pad pos-facts">
                        <div><dt>Employee ID</dt><dd class="pos-num">{{ $record->employee_code }}</dd></div>
                        @if ($record->work_email)<div><dt>Work email</dt><dd><a class="pos-link" href="mailto:{{ $record->work_email }}">{{ $record->work_email }}</a></dd></div>@endif
                        @if ($record->work_phone)<div><dt>Work phone</dt><dd>{{ $record->work_phone }}</dd></div>@endif
                        @if ($record->joining_date)<div><dt>Joined</dt><dd>{{ $record->joining_date->format('j M Y') }}</dd></div>@endif
                        @if ($p?->effective_from)<div><dt>In role since</dt><dd>{{ $p->effective_from->format('j M Y') }}</dd></div>@endif
                    </dl>
                </x-pos.section>
            </aside>
        </div>
    </section>

    {{-- JOURNEY: one person, one continuous journey --}}
    <section id="journey" class="pos-360-sec" aria-labelledby="journey-title">
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
    <section id="work" class="pos-360-sec" aria-labelledby="work-title">
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
                @php($rel = $w['relationships'] ?? ['up' => [], 'down' => [], 'reports' => 0])
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
        <section id="{{ $id }}" class="pos-360-sec" aria-labelledby="{{ $id }}-title">
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
    <section id="records" class="pos-360-sec" aria-labelledby="records-title">
        <x-pos.section title="Records" id="records-title" sub="Every detail as recorded, by area. Changes go through the same governed actions and are audited." />
    </section>
</div>
