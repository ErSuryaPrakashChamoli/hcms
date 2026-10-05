@php
    /** @var \App\Domain\Employment\Models\Employee $employee */
    $p = $employee->currentPosition;
    $manager = $employee->currentManager?->manager;
    $state = $employee->lifecycle_state;
    $tone = match ($state?->value) { 'probation', 'onboarding', 'preboarding', 'pre_employee' => 'info', 'notice_period', 'suspended' => 'warning', 'exited', 'alumni' => 'neutral', default => 'success' };
    $life = $workspace['lifetime'] ?? null;
    $sectionsNav = ['now' => 'Now', 'journey' => 'Journey', 'work' => 'Work', 'growth' => 'Growth', 'rewards' => 'Rewards', 'documents' => 'Documents', 'records' => 'Records'];
@endphp
{{-- UX.15 Employee 360: a person workspace. One person, one lifetime record; actions grouped Message · Request · Action · More. --}}
<header class="pos-360-header pos-enter">
    <div class="pos-360-identity">
        <x-pos.avatar :name="$employee->person?->display_name" size="xl" />
        <div class="min-w-0 grid gap-1">
            <p class="pos-ws-eyebrow">{{ $employee->employee_code }}</p>
            <h1 class="pos-ws-title">{{ $employee->person?->display_name }}</h1>
            <p class="pos-lead">{{ collect([$p?->designation?->name, $p?->department?->name, $p?->location?->name])->filter()->implode(' · ') ?: 'No current position' }}</p>
            <div class="pos-360-ribbon">
                @if ($state)<x-pos.status :tone="$tone" :label="$state->getLabel()" />@endif
                @if ($life && $life['joined'])
                    <span class="pos-moment" title="A rehire continues the same record; nothing is duplicated.">
                        <x-filament::icon icon="heroicon-m-sparkles" class="size-3.5" />One lifetime record
                    </span>
                    <span class="pos-meta">Joined {{ $life['joined']->format('M Y') }}@if ($life['tenure']) · {{ $life['tenure'] }}@endif @if ($life['employments'] > 1) · {{ $life['employments'] }} periods of employment @endif</span>
                @endif
                @if ($manager)
                    {{-- UX.19: the label stays with the person when the line wraps --}}
                    <span class="pos-360-reports"><span class="pos-meta">Reports to</span> <x-pos.person :id="$manager->id" :name="$manager->person?->display_name" /></span>
                @endif
            </div>
        </div>
    </div>
    @php($summarise = isset(app(\App\Domain\Ai\Services\AiGateway::class)->assistantsFor(auth()->user())['hr']))
    @php($visible = collect($actions)->filter(fn ($a) => $a->isVisible())->isNotEmpty())
    {{-- UX.19: no empty row when the viewer has no action here (the person viewing their own record) --}}
    @if ($summarise || $visible)
    <div class="pos-360-actions">
        @if ($summarise)
            {{-- Contextual AI: a summary from the Employee 360, which applies every domain's own rule. --}}
            <button type="button" class="pos-btn pos-btn-ghost pos-btn-sm me-1" x-data
                x-on:click="$dispatch('pos-ai-open', { assistant: 'hr', prompt: @js('Summarise '.$employee->employee_code), context: [@js('Summarise '.$employee->employee_code), 'What changed this week?'] })">
                <span class="pos-ai-orb pos-ai-orb-sm" aria-hidden="true"></span> Summarise
            </button>
        @endif
        @if (count($actions))
            <x-filament::actions :actions="$actions" />
        @endif
    </div>
    @endif
</header>
<nav class="pos-sectnav pos-360-nav" aria-label="{{ $employee->person?->display_name }}" x-data
    x-init="$store.pos360?.fromHash(false)" x-on:hashchange.window="$store.pos360?.fromHash(true)">
    @foreach ($sectionsNav as $id => $label)
        <a href="#{{ $id }}" :aria-current="(($store.pos360?.view ?? 'now') === '{{ $id }}').toString()">{{ $label }}</a>
    @endforeach
</nav>
