<x-filament-panels::page>
    <p class="text-sm text-gray-500">Approved expense advances waiting to be paid. Record the payment made outside PeopleOS with its reference; the employee then settles the advance with expense reports by the settle-by date their policy sets. Advances whose policy pays them with payroll are paid by the next payroll unless you dispense them here first; once a payroll being prepared includes one, that payroll pays it.</p>

    {{-- HROne parity XO-5: what is waiting to be paid out, and what was paid out. --}}
    <x-filament::tabs>
        @foreach ($this->tabsFor() as $key => $label)
            <x-filament::tabs.item tag="a" :href="$this->tabUrl($key)" :active="$this->tab() === $key" data-dispense-tab="{{ $key }}">
                {{ $label }}
            </x-filament::tabs.item>
        @endforeach
    </x-filament::tabs>

    {{ $this->table }}
</x-filament-panels::page>
