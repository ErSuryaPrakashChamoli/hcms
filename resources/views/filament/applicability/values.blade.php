{{-- "Company values…": a company's values of a shared record, field by field (entity-specific overrides, Part 6). --}}
<div class="pos-clone space-y-2">
    @if ($company === null)
        <p class="pos-body-sm text-gray-500">Choose a company to see its values.</p>
    @elseif ($rows === [])
        <p class="pos-body-sm text-gray-500">This record has no values a company can set for itself.</p>
    @else
        <p class="pos-body-sm"><span class="font-medium">{{ $company }}</span> uses {{ $overridden }} value(s) of its own; every other value is inherited from the shared record.</p>
        <div class="max-h-80 overflow-y-auto rounded-lg border border-gray-200 dark:border-white/10">
            <table class="pos-body-sm w-full text-start">
                <thead class="bg-gray-50 dark:bg-white/5"><tr><th class="px-3 py-2 text-start">Field</th><th class="px-3 py-2 text-start">Shared value</th><th class="px-3 py-2 text-start">{{ $company }}</th></tr></thead>
                <tbody>
                    @foreach ($rows as $row)
                        <tr class="border-t border-gray-100 dark:border-white/5">
                            <td class="px-3 py-1.5">{{ $row['label'] }}</td>
                            <td class="px-3 py-1.5">{{ $row['shared_display'] }}</td>
                            <td class="px-3 py-1.5">
                                @if ($row['overridden'])
                                    <span class="font-medium">{{ $row['effective_display'] }}</span> <span class="text-warning-600">· own value</span>
                                @else
                                    <span class="text-gray-500">Inherited</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
