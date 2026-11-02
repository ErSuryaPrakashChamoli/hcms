<x-filament-panels::page>
    {{-- HROne parity PO-17 (§25.14 #5): the increment plans shared with this manager, their own team's lines only. --}}
    <p class="text-sm text-gray-500">Increment plans HR shared with you. You see only the people you manage, never your own line. While a plan is a draft you can propose an annual CTC for each of them, within the plan's grid and within what the plan gives your team; HR accepts or declines each proposal, and the plan is then reviewed and approved by other people.</p>

    {{ $this->table }}
</x-filament-panels::page>
