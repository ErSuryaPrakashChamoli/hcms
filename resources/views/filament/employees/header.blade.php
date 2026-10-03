@php
    /** @var \App\Domain\Employment\Models\Employee $employee */
    $p = $employee->currentPosition;
    $manager = $employee->currentManager?->manager;
    $state = $employee->lifecycle_state;
    $tone = match ($state?->value) { 'probation', 'onboarding', 'preboarding', 'pre_employee' => 'info', 'notice_period', 'suspended' => 'warning', 'exited', 'alumni' => 'neutral', default => 'success' };
@endphp
<header class="pos-360-header pos-enter">
    <div class="pos-360-identity">
        <x-pos.avatar :name="$employee->person?->display_name" size="xl" />
        <div class="min-w-0">
            <p class="pos-label">{{ $employee->employee_code }}</p>
            <h1 class="fi-header-heading">{{ $employee->person?->display_name }}</h1>
            <p class="pos-body-sm mt-1">{{ collect([$p?->designation?->name, $p?->department?->name, $p?->location?->name])->filter()->implode(' · ') ?: 'No current position' }}</p>
            <div class="mt-3 flex flex-wrap items-center gap-2">
                @if ($state)<x-pos.status :tone="$tone" :label="$state->getLabel()" />@endif
                @if ($employee->joining_date && $state?->isEmployed())
                    <span class="pos-caption">{{ $employee->joining_date->diffForHumans(now(), ['parts' => 2, 'syntax' => \Carbon\CarbonInterface::DIFF_ABSOLUTE]) }} with us</span>
                @endif
                @if ($manager)
                    <span class="pos-caption" aria-hidden="true">·</span>
                    <span class="pos-caption">Reports to</span>
                    <button type="button" class="pos-person-inline" x-data x-on:click="$dispatch('pos-drawer-open', { type: 'person', id: {{ $manager->id }} })">
                        <x-pos.avatar :name="$manager->person?->display_name" size="xs" />{{ $manager->person?->display_name }}
                    </button>
                @endif
            </div>
        </div>
    </div>
    @if (count($actions))
        <div class="pos-360-actions">
            <x-filament::actions :actions="$actions" />
        </div>
    @endif
</header>
