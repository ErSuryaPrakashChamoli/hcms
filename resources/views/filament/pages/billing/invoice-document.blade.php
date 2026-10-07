{{-- SaaS.7: an invoice as a structured document. Generic sections; a tax regime adds labelled rows (never a branch here). --}}
<div class="space-y-4 text-sm">
    <dl class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <div><dt class="text-gray-500 dark:text-gray-400">Number</dt><dd class="font-medium">{{ $doc['number'] ?? 'not numbered (draft)' }}</dd></div>
        <div><dt class="text-gray-500 dark:text-gray-400">Status</dt><dd>{{ $doc['status'] }}@if ($doc['overdue']) <x-filament::badge size="sm" color="danger">overdue</x-filament::badge>@endif</dd></div>
        <div><dt class="text-gray-500 dark:text-gray-400">Issue date</dt><dd>{{ $doc['issue_date'] ?? '—' }}</dd></div>
        <div><dt class="text-gray-500 dark:text-gray-400">Due date</dt><dd>{{ $doc['due_date'] ?? '—' }}</dd></div>
        <div><dt class="text-gray-500 dark:text-gray-400">Currency</dt><dd><code>{{ $doc['currency'] }}</code> (shown for {{ $doc['locale'] }})</dd></div>
        <div><dt class="text-gray-500 dark:text-gray-400">Service period</dt><dd>{{ $doc['period'] ?? '—' }}</dd></div>
        <div class="sm:col-span-2"><dt class="text-gray-500 dark:text-gray-400">Reference</dt><dd><code>{{ $doc['reference'] }}</code></dd></div>
    </dl>

    @if ($doc['supplier'])
        <div class="grid gap-4 sm:grid-cols-2">
            @foreach (['Supplier' => $doc['supplier'], 'Customer' => $doc['customer']] as $label => $party)
                <div><p class="text-gray-500 dark:text-gray-400">{{ $label }}</p><p class="font-medium">{{ $party['name'] }}</p>@foreach ($party['lines'] as $line)<p>{{ $line }}</p>@endforeach</div>
            @endforeach
        </div>
    @else
        <p class="text-gray-600 dark:text-gray-300">Supplier, customer and tax are fixed when the invoice is issued.</p>
    @endif

    <div class="overflow-x-auto" tabindex="0" role="region" aria-label="Invoice lines">
    <table class="w-full">
        <thead><tr class="text-left text-gray-500 dark:text-gray-400"><th scope="col" class="py-1 pe-4">#</th><th scope="col" class="pe-4">Description</th><th scope="col" class="pe-4 text-end">Quantity</th><th scope="col" class="pe-4 text-end">Unit</th><th scope="col" class="text-end">Amount</th></tr></thead>
        <tbody>
            @foreach ($doc['lines'] as $line)
                <tr class="border-t border-gray-100 dark:border-gray-800"><td class="py-1 pe-4">{{ $line['no'] }}</td><td class="pe-4">{{ $line['description'] }}@if ($line['period'])<span class="text-gray-500 dark:text-gray-400"> · {{ $line['period'] }}</span>@endif</td>
                    <td class="pe-4 text-end">{{ $line['quantity'] }}</td><td class="pe-4 text-end">{{ $line['unit'] }}</td><td class="text-end">{{ $line['amount'] }}</td></tr>
            @endforeach
        </tbody>
    </table>
    </div>

    @if ($doc['regime'])
        <div class="grid gap-4 lg:grid-cols-2">
            <div>
                <p class="text-gray-500 dark:text-gray-400">Tax: {{ $doc['regime'] }} · {{ $doc['treatment'] }}</p>
                @if ($doc['tax_summary'] === [])
                    <p>No tax charged under this treatment.</p>
                @else
                    <div class="overflow-x-auto" tabindex="0" role="region" aria-label="Tax summary">
                    <table class="w-full">
                        <thead><tr class="text-left text-gray-500 dark:text-gray-400"><th scope="col" class="py-1 pe-4">Tax</th><th scope="col" class="pe-4">Rate</th><th scope="col" class="pe-4 text-end">Taxable</th><th scope="col" class="text-end">Tax</th></tr></thead>
                        <tbody>@foreach ($doc['tax_summary'] as $row)<tr class="border-t border-gray-100 dark:border-gray-800"><td class="py-1 pe-4">{{ $row['type'] }}</td><td class="pe-4">{{ $row['rate'] }}</td><td class="pe-4 text-end">{{ $row['taxable'] }}</td><td class="text-end">{{ $row['tax'] }}</td></tr>@endforeach</tbody>
                    </table>
                    </div>
                @endif
                <p class="mt-1 text-gray-500 dark:text-gray-400">Rule: {{ $doc['rule'] }}</p>
            </div>
            @if ($doc['regime_rows'] !== [])
                <dl class="grid grid-cols-1 gap-2 sm:grid-cols-2">
                    @foreach ($doc['regime_rows'] as $row)<div><dt class="text-gray-500 dark:text-gray-400">{{ $row['label'] }}</dt><dd>{{ $row['value'] }}</dd></div>@endforeach
                </dl>
            @endif
        </div>
    @endif

    <dl class="ms-auto grid max-w-sm grid-cols-2 gap-1">
        <dt class="text-gray-500 dark:text-gray-400">Subtotal</dt><dd class="text-end">{{ $doc['totals']['subtotal'] }}</dd>
        <dt class="text-gray-500 dark:text-gray-400">Tax</dt><dd class="text-end">{{ $doc['totals']['tax'] ?? 'at issue' }}</dd>
        <dt class="font-medium">Total</dt><dd class="text-end font-medium">{{ $doc['totals']['total'] ?? 'at issue' }}</dd>
    </dl>
</div>
