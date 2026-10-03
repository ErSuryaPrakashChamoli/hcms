@php
    $sections = collect($getState() ?? [])->keyBy('key');
    $groups = [
        'Today' => ['hint' => 'Attendance, leave and open requests', 'keys' => ['attendance', 'leave', 'service']],
        'Growth' => ['hint' => 'Performance, goals, learning and career', 'keys' => ['performance', 'goals', 'learning', 'skills', 'career', 'talent', 'succession']],
        'Wellbeing' => ['hint' => 'Signals from data you can already see', 'keys' => ['engagement']],
        'Rewards' => ['hint' => 'Pay and compensation (amounts stay in their audited tabs)', 'keys' => ['compensation', 'payroll']],
        'Operations' => ['hint' => 'Documents, letters, communications and transitions', 'keys' => ['documents', 'letters', 'communication', 'exit', 'alumni']],
    ];
    // Wellbeing hints are derived only from sections the viewer was already given.
    $wellbeing = [];
    if ($sections->has('leave')) {
        $wellbeing['Leave taken this year (days)'] = $sections['leave']['facts']['Days taken this year'] ?? null;
    }
    if ($sections->has('attendance')) {
        $wellbeing['Overtime, last 30 days (hours)'] = $sections['attendance']['facts']['Overtime (hours)'] ?? null;
    }
@endphp
{{-- The organisation summary keeps its place for screen readers and search ("Organisation"); the snapshot shows the path. --}}
<div class="pos-360-groups">
    @foreach ($groups as $label => $group)
        @php
            $items = collect($group['keys'])->filter(fn ($k) => $sections->has($k))->map(fn ($k) => $sections[$k]);
            if ($label === 'Wellbeing' && $wellbeing !== []) {
                $items->push(['key' => 'wellbeing', 'label' => 'Workload and rest', 'owner' => 'Leave · Attendance', 'facts' => $wellbeing]);
            }
        @endphp
        @if ($items->isNotEmpty())
            <section class="pos-360-group" aria-labelledby="g360-{{ \Illuminate\Support\Str::slug($label) }}">
                <header class="mb-2">
                    <h3 id="g360-{{ \Illuminate\Support\Str::slug($label) }}" class="pos-label">{{ $label }}</h3>
                    <p class="pos-caption">{{ $group['hint'] }}</p>
                </header>
                <div class="pos-360-cards">
                    @foreach ($items as $section)
                        <div class="pos-360-card">
                            <div class="flex items-center justify-between gap-2">
                                <span class="pos-h3">{{ $section['label'] }}</span>
                                <span class="pos-caption">{{ $section['owner'] }}</span>
                            </div>
                            <dl class="mt-2 space-y-1">
                                @foreach ($section['facts'] as $factLabel => $value)
                                    <div class="flex justify-between gap-3 pos-body-sm"><dt>{{ $factLabel }}</dt><dd class="text-end font-medium text-pos-text pos-num">{{ $value ?? '—' }}</dd></div>
                                @endforeach
                            </dl>
                        </div>
                    @endforeach
                </div>
            </section>
        @endif
    @endforeach
    @if ($sections->has('organisation'))
        <p class="sr-only">Organisation: {{ $sections['organisation']['facts']['Path'] ?? '—' }}</p>
    @endif
</div>
