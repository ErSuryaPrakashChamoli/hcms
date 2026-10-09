<x-filament-panels::page>
    @php($p = $this->progress())
    <x-filament::section heading="Where things stand" description="These are separate: an active account and a provisioned organisation do not mean set-up is complete, and a subscription does not switch on payroll.">
        <dl class="grid gap-x-4 gap-y-3 text-sm sm:grid-cols-[14rem_1fr]">
            @foreach ($p['states'] as $s)
                <dt class="font-medium">{{ $s['label'] }}</dt>
                <dd class="flex flex-wrap items-start gap-2"><x-filament::badge size="sm" :color="$s['tone']">{{ $s['state'] }}</x-filament::badge><span class="text-gray-600 dark:text-gray-300">{{ $s['detail'] }}</span></dd>
            @endforeach
        </dl>
    </x-filament::section>

    <x-filament::section :heading="'Set-up steps ('.$p['done'].' of '.$p['total'].' done)'">
        <ol class="space-y-3 text-sm" aria-label="Set-up steps">
            @foreach ($p['items'] as $item)
                <li class="flex items-start gap-3 border-t border-gray-100 pt-3 first:border-t-0 first:pt-0 dark:border-gray-800">
                    <span aria-hidden="true" @class(['mt-1 inline-block h-3 w-3 shrink-0 rounded-full', 'bg-success-600' => $item['done'], 'border-2 border-gray-400' => ! $item['done']])></span>
                    <div class="grow">
                        <p class="font-medium">{{ $item['title'] }} <span class="sr-only">{{ $item['done'] ? '(done)' : '(to do)' }}</span>@if ($item['done'])<x-filament::badge size="sm" color="success" class="ms-2 inline-flex">Done</x-filament::badge>@endif</p>
                        <p class="text-gray-600 dark:text-gray-300">{{ $item['why'] }}</p>
                        @if ($item['url'])
                            <a href="{{ $item['url'] }}" wire:navigate class="text-primary-600 underline dark:text-primary-400">{{ $item['done'] ? 'Review' : 'Start' }}<span class="sr-only"> {{ strtolower($item['title']) }}</span></a>
                        @elseif ($item['needs'])
                            <p class="text-gray-600 dark:text-gray-300">Needs the <strong>{{ $item['needs'] }}</strong> role: give it to the person who will do this (Users → their account → Roles).</p>
                        @endif
                    </div>
                </li>
            @endforeach
        </ol>
    </x-filament::section>
</x-filament-panels::page>
