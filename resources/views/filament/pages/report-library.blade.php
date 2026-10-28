<x-filament-panels::page>
    @php($categories = $this->categories())
    <div class="flex flex-wrap items-center gap-3">
        <div class="w-full sm:max-w-md">
            <x-filament::input.wrapper prefix-icon="heroicon-m-magnifying-glass">
                <x-filament::input type="search" wire:model.live.debounce.300ms="search" placeholder="Search reports — e.g. leave balance, PF, late coming" aria-label="Search reports" />
            </x-filament::input.wrapper>
        </div>
        <span class="text-sm text-gray-500">{{ $categories->flatten(1)->count() }} reports</span>
        <x-filament::link :href="\App\Filament\Resources\Reports\ReportResource::getUrl('index')" icon="heroicon-m-table-cells" class="ms-auto">My reports</x-filament::link>
    </div>

    @if ($categories->isNotEmpty())
        <nav class="flex flex-wrap gap-2" aria-label="Report categories">
            @foreach ($categories as $category => $templates)
                <a href="#{{ \Illuminate\Support\Str::slug($category) }}" class="text-sm text-primary-600 hover:underline">{{ $category }} ({{ count($templates) }})</a>
            @endforeach
        </nav>
    @endif

    @forelse ($categories as $category => $templates)
        <x-filament::section :heading="$category" id="{{ \Illuminate\Support\Str::slug($category) }}" collapsible>
            <div class="overflow-x-auto" tabindex="0" role="region" aria-label="{{ $category }}">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-gray-500"><th class="py-1 px-2">Report</th><th class="px-2">Built from</th><th class="px-2"></th></tr>
                    </thead>
                    <tbody>
                        @foreach ($templates as $template)
                            <tr class="border-t border-gray-100 dark:border-gray-800 align-top">
                                <td class="py-1 px-2"><span class="font-medium">{{ $template['name'] }}</span><div class="text-xs text-gray-500">{{ $template['description'] }}</div></td>
                                <td class="px-2 text-xs text-gray-500">{{ $this->datasetLabel($template['dataset']) }}</td>
                                <td class="px-2 whitespace-nowrap text-right">{{ ($this->useTemplateAction)(['template' => $template['key']]) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-filament::section>
    @empty
        <x-filament::section>
            <p class="text-sm text-gray-500">{{ $this->search !== '' ? 'No report matches "' . $this->search . '".' : 'No ready-made report is available with your permissions.' }}</p>
        </x-filament::section>
    @endforelse

    <p class="text-xs text-gray-500">Reports only show data you are allowed to see. Statutory identifiers and bank accounts are always masked; full values are in the compliance reports and the payroll bank advice.</p>
</x-filament-panels::page>
