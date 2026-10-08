<x-filament-panels::page>
    <x-filament::section heading="Exports" description="A package stays available for a limited time, then is deleted. Each download link works for a few minutes and only for you.">
        @if ($this->revealed)
            <div class="mb-3 rounded-lg border border-primary-300 p-3 text-sm dark:border-primary-700" role="status">
                Passphrase: <code class="select-all break-all text-base">{{ $this->revealed }}</code> — keep it apart from the file.
            </div>
        @endif
        @php($exports = $this->exports())
        @if ($exports->isEmpty())
            <p class="text-sm text-gray-600 dark:text-gray-300">No export yet.</p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <caption class="sr-only">Your organisation's exports</caption>
                    <thead><tr class="text-left text-gray-500"><th scope="col" class="py-1 pe-3">Requested</th><th scope="col" class="pe-3">Status</th><th scope="col" class="pe-3">Records / files</th><th scope="col" class="pe-3">Available until</th><th scope="col">Actions</th></tr></thead>
                    <tbody>
                        @foreach ($exports as $export)
                            <tr class="border-t border-gray-100 dark:border-gray-800">
                                <td class="py-1 pe-3">{{ $export->created_at->format('Y-m-d H:i') }}</td>
                                <td class="pe-3">{{ \App\Domain\Offboarding\Services\OffboardingDirectory::exportLabel($export) }}</td>
                                <td class="pe-3">{{ $export->record_count ?? '—' }} / {{ $export->file_count ?? '—' }}</td>
                                <td class="pe-3">{{ $export->expires_at?->format('Y-m-d') ?? '—' }}</td>
                                <td class="space-x-1 whitespace-nowrap">@if ($export->downloadable()){{ ($this->downloadAction)(['export' => $export->id]) }} {{ ($this->passphraseAction)(['export' => $export->id]) }}@endif</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>
</x-filament-panels::page>
