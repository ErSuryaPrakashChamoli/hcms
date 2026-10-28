<x-filament-panels::page>
    <form wire:submit="save">
        {{ $this->form }}
        <div class="mt-4"><x-filament::button type="submit">Save pay cycle</x-filament::button></div>
    </form>

    <x-filament::section heading="What the coming months cover" description="With the start day chosen above, before you save. Months that already have a payroll run keep their dates, and the month after them starts the day after they end, so no day is paid twice or left out.">
        <div class="grid gap-4 md:grid-cols-2">
            @foreach ($this->preview() as $row)
                <div class="overflow-x-auto" tabindex="0" role="region" aria-label="{{ $row['company'] }}">
                    <p class="mb-1 text-sm font-medium">{{ $row['company'] }}</p>
                    <table class="w-full text-sm">
                        <thead><tr class="text-left text-gray-500"><th class="py-1 px-2">Month</th><th class="px-2">Covers</th><th class="px-2">Days</th><th class="px-2"></th></tr></thead>
                        <tbody>
                            @foreach ($row['months'] as $month)
                                <tr class="border-t border-gray-100 dark:border-gray-800 align-top">
                                    <td class="py-1 px-2 font-medium">{{ $month['label'] }}</td>
                                    <td class="px-2">{{ $month['start']->format('j M Y') }} – {{ $month['end']->format('j M Y') }}</td>
                                    <td class="px-2">{{ $month['days'] }}</td>
                                    <td class="px-2 text-xs {{ $month['fixed'] ? 'text-gray-500' : 'text-warning-600' }}">{{ $month['note'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endforeach
        </div>
    </x-filament::section>
</x-filament-panels::page>
