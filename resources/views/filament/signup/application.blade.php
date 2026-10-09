{{-- SaaS.10: the applicant's registration at a glance. No token, no full address, no operator notes. --}}
@php($app = $application ?? null)
@if ($app)
    <div @if ($polling ?? false) wire:poll.10s @endif class="space-y-4 text-sm">
        <dl class="grid gap-x-4 gap-y-1 sm:grid-cols-[11rem_1fr]">
            <dt class="text-gray-500 dark:text-gray-400">Reference</dt><dd class="font-mono">{{ $app->reference }}</dd>
            <dt class="text-gray-500 dark:text-gray-400">Status</dt>
            <dd><x-filament::badge size="sm" :color="$app->status->getColor()">{{ $app->status->getLabel() }}</x-filament::badge></dd>
            <dt class="text-gray-500 dark:text-gray-400">Organisation</dt><dd>{{ $app->display_name ?? $app->company_name }}</dd>
            <dt class="text-gray-500 dark:text-gray-400">Country</dt><dd>{{ \Locale::getDisplayRegion('-'.$app->country_code, 'en') ?: $app->country_code }}</dd>
            <dt class="text-gray-500 dark:text-gray-400">Plan</dt>
            <dd>{{ $plan }}@if ($offer && $app->status === \App\Domain\Signup\Enums\SignupStatus::DetailsPending)<br><span class="text-xs text-gray-500 dark:text-gray-400">{{ \App\Filament\Signup\SignupStart::describe($offer) }}</span>@endif</dd>
            @if ($trial_until ?? null)
                <dt class="text-gray-500 dark:text-gray-400">Trial</dt><dd>until {{ $trial_until }} (no payment is taken during the trial)</dd>
            @endif
        </dl>
        <ol class="space-y-1" aria-label="Registration steps">
            @foreach ($steps as $step)
                <li class="flex items-center gap-2">
                    <span aria-hidden="true" @class(['inline-block h-2.5 w-2.5 rounded-full', 'bg-success-600' => $step['done'], 'border border-gray-400' => ! $step['done']])></span>
                    <span>{{ $step['label'] }}</span><span class="sr-only">{{ $step['done'] ? '(done)' : '(not yet)' }}</span>
                </li>
            @endforeach
        </ol>
    </div>
@endif
