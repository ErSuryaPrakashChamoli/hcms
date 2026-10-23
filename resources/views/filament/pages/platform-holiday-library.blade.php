<x-filament-panels::page>
    <x-filament::section heading="Holiday lists" description="Upload each country's holidays per year: national holidays plus each region's own. A list is offered to tenants once published; each tenant then picks the holidays its organisation observes into its own calendar. Later uploads never change a tenant's calendar.">
        @php($lists = $this->lists())
        @if ($lists->isEmpty())
            <p class="text-sm text-gray-600 dark:text-gray-300">No holiday list has been uploaded. Open Upload holiday list: it shows the CSV format and offers a template to download.</p>
        @else
            <div class="overflow-x-auto" tabindex="0" role="region" aria-label="Holiday lists">
                <table class="w-full text-sm">
                    <caption class="sr-only">Holiday lists</caption>
                    <thead><tr class="text-left text-gray-500"><th scope="col" class="py-1 pe-3">Country / region / year</th><th scope="col" class="pe-3">Holidays</th><th scope="col" class="pe-3">Status</th><th scope="col" class="pe-3">Uploaded</th><th scope="col">Actions</th></tr></thead>
                    <tbody>
                        @foreach ($lists as $list)
                            <tr class="border-t border-gray-100 align-top dark:border-gray-800">
                                <td class="py-1 pe-3">
                                    <span class="font-medium">{{ $list->label() }}</span>
                                    @if ($list->source)<br><span class="text-xs text-gray-500 [overflow-wrap:anywhere]">Source: {{ $list->source }}</span>@endif
                                </td>
                                <td class="pe-3">
                                    <details>
                                        <summary class="cursor-pointer">{{ $list->entries->count() }} {{ str('holiday')->plural($list->entries->count()) }}</summary>
                                        <ul class="mt-1 space-y-0.5 text-xs">
                                            @foreach ($list->entries as $entry)
                                                <li><span class="tabular-nums">{{ $entry->date->format('D, d M Y') }}</span> · {{ $entry->name }} <span class="text-gray-500">({{ $entry->type }}{{ $entry->is_half_day ? ', half day' : '' }})</span></li>
                                            @endforeach
                                        </ul>
                                    </details>
                                </td>
                                <td class="pe-3"><x-filament::badge :color="$this->statusColor($list)">{{ ucfirst($list->status->value) }}</x-filament::badge></td>
                                <td class="pe-3 text-xs">{{ $list->uploaded_at?->toDateString() }}@if ($list->uploader)<br><span class="text-gray-500">{{ $list->uploader->name }}</span>@endif</td>
                                <td class="space-x-1 whitespace-nowrap">
                                    @if ($list->status !== \App\Domain\Attendance\Enums\HolidayLibraryStatus::Published){{ ($this->publishAction)(['list' => $list->id]) }} {{ ($this->deleteAction)(['list' => $list->id]) }}@endif
                                    @if ($list->status === \App\Domain\Attendance\Enums\HolidayLibraryStatus::Published){{ ($this->withdrawAction)(['list' => $list->id]) }}@endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>
</x-filament-panels::page>
