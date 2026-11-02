<x-filament-panels::page>
    <p class="text-sm text-gray-500">One row per employee who owes money on dispensed expense advances. "Recover through payroll" deducts an amount in the payroll month you choose (only a month not yet finalized); until then the policy's automatic recovery of those advances waits. "Record recovery outside payroll" records money paid back by bank transfer, cheque or cash. A payroll being prepared that already recovers an advance keeps it. Your own advances are recovered by someone else.</p>

    {{-- HROne parity XO-6: unrecovered and recovered employees. --}}
    <x-filament::tabs>
        @foreach ($this->tabsFor() as $key => $label)
            <x-filament::tabs.item :active="$this->tab() === $key" wire:click="setTab('{{ $key }}')" data-recovery-tab="{{ $key }}">
                {{ $label }}
            </x-filament::tabs.item>
        @endforeach
    </x-filament::tabs>

    {{ $this->table }}
</x-filament-panels::page>
