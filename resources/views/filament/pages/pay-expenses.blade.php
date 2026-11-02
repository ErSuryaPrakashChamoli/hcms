<x-filament-panels::page>
    <p class="text-sm text-gray-500">Approved expense reports with an amount still owed to the employee, after open advances were settled against them. Record the payment made outside PeopleOS with its reference (and, if you like, how it was paid), or pay it with payroll: the next payroll — or the payroll month you choose — repays it (non-taxable) and marks it paid when that payroll is finalized. A report a payroll being prepared already includes is paid by that payroll. Your own reports are paid by someone else.</p>

    {{-- HROne parity XO-4: one row per report, one row per employee, and what was paid. --}}
    <x-filament::tabs>
        @foreach ($this->tabsFor() as $key => $label)
            <x-filament::tabs.item :active="$this->tab() === $key" wire:click="setTab('{{ $key }}')" data-pay-tab="{{ $key }}">
                {{ $label }}
            </x-filament::tabs.item>
        @endforeach
    </x-filament::tabs>

    {{ $this->table }}
</x-filament-panels::page>
