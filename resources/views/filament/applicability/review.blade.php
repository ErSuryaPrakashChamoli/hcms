{{-- "Applies to…", Review: what the change does before anything is written (configuration applicability, Part 8.3). --}}
<div class="pos-clone space-y-3">
    @if ($plan === null)
        <x-pos.step-banner tone="blocked" title="Choose where it applies" sub="Go back one step and pick All, Selected or Current entity only." />
    @else
        @if ($plan->blockers !== [])
            <x-pos.step-banner tone="blocked" title="This change cannot be made yet" sub="Fix these first; nothing has been changed.">
                <ul class="pos-body-sm list-disc ps-5">@foreach ($plan->blockers as $blocker)<li>{{ $blocker }}</li>@endforeach</ul>
            </x-pos.step-banner>
        @elseif ($plan->warnings !== [])
            <x-pos.step-banner tone="warning" title="Read before applying" sub="Nothing here stops the change.">
                <ul class="pos-body-sm list-disc ps-5">@foreach ($plan->warnings as $warning)<li>{{ $warning }}</li>@endforeach</ul>
            </x-pos.step-banner>
        @else
            <x-pos.step-banner tone="clear" title="Ready to apply" sub="Nothing to fix." />
        @endif
        <dl class="pos-body-sm grid grid-cols-1 gap-1 sm:grid-cols-2">
            <div><dt class="inline font-medium">Configuration:</dt> <dd class="inline">{{ $label }}</dd></div>
            <div><dt class="inline font-medium">Operation:</dt> <dd class="inline">Change where it applies, from {{ \Illuminate\Support\Carbon::parse($plan->effectiveFrom)->format('j M Y') }}</dd></div>
            <div><dt class="inline font-medium">Applies to now:</dt> <dd class="inline">{{ $plan->names($plan->before) }}</dd></div>
            <div><dt class="inline font-medium">Will apply to:</dt> <dd class="inline">{{ $plan->names($plan->after) }}{{ $plan->selection->includeFuture ? ', and companies created later' : '' }}</dd></div>
            <div><dt class="inline font-medium">Joining:</dt> <dd class="inline">{{ $plan->added === [] ? 'No company' : $plan->names($plan->added) }}</dd></div>
            <div><dt class="inline font-medium">Leaving:</dt> <dd class="inline">{{ $plan->removed === [] ? 'No company' : $plan->names($plan->removed) }}</dd></div>
            <div><dt class="inline font-medium">Shared configuration:</dt> <dd class="inline">{{ $plan->sharedBefore() || $plan->sharedAfter() ? 'Yes — one record used by several companies' : 'No — one company' }}</dd></div>
            <div><dt class="inline font-medium">Creates a copy:</dt> <dd class="inline">No — the same record; use Clone for an independent copy</dd></div>
        </dl>
        <p class="pos-body-sm text-gray-500">Everything this {{ $typeLabel }} points at, and everything that points at it, was checked for the companies above. Past transactions keep what applied to them; company values (overrides) are kept.</p>
    @endif
</div>
