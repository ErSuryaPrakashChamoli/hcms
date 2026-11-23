{{-- Compare a copy with its source: as copied, the source now, and the copy now. Read-only: nothing is applied. --}}
<div class="pos-clone pos-body-sm space-y-2">
    <p>{{ $intro }}</p>
    <div class="overflow-x-auto" tabindex="0" role="region" aria-label="Compare with source">
        <table class="w-full">
            <thead><tr><th class="text-start">Detail</th><th class="text-start">Source when copied</th><th class="text-start">Source now</th><th class="text-start">This copy now</th></tr></thead>
            <tbody>
                @foreach ($rows as $row)
                    <tr @class(['bg-warning-50 dark:bg-warning-500/10' => $row['changed']])>
                        <td class="font-medium">{{ $row['label'] }}</td>
                        <td>{{ $row['then'] }}</td>
                        <td>{{ $row['source'] }}</td>
                        <td>{{ $row['copy'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    <p class="text-gray-500">Highlighted rows differ. Edit the copy yourself if it should follow a change: nothing is applied from here.</p>
</div>
