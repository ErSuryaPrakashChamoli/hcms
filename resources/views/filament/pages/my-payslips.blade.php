<x-filament-panels::page>
    {{-- HROne parity MS-9: payslips of a financial year, a salary card and a tax card per month. --}}
    <div class="flex flex-wrap items-end gap-4">
        <label class="text-sm">
            <span class="text-gray-500">Financial year</span>
            <x-filament::input.wrapper>
                <x-filament::input.select wire:model.live="year">
                    @foreach ($this->years() as $fy)
                        <option value="{{ $fy }}">{{ $fy }}</option>
                    @endforeach
                </x-filament::input.select>
            </x-filament::input.wrapper>
        </label>
        <label class="text-sm">
            <span class="text-gray-500">Search</span>
            <x-filament::input.wrapper>
                <x-filament::input type="search" wire:model.live.debounce.400ms="search" placeholder="Month or payslip number" />
            </x-filament::input.wrapper>
        </label>
        @if ($url = $this->certificatesUrl())
            <x-filament::link :href="$url" size="sm">Tax certificates (Form 16)</x-filament::link>
        @endif
    </div>

    @if ($help = $this->passwordHelp())
        <p class="text-sm text-gray-600" data-password-help>{{ $help }}</p>
    @endif

    @php($payslips = $this->payslips())
    @if ($payslips->isEmpty())
        <x-filament::section>
            <p class="text-sm text-gray-500">No payslip for {{ $year }}{{ $search !== '' ? ' matches your search' : '' }}.</p>
        </x-filament::section>
    @else
        <div class="grid gap-4 md:grid-cols-3">
            @foreach ($payslips as $payslip)
                <x-filament::section compact data-payslip="{{ $payslip->id }}">
                    <div class="text-sm text-gray-500">Salary</div>
                    <div class="text-lg font-semibold">{{ \Illuminate\Support\Carbon::create((int) $payslip->get('period.year'), (int) $payslip->get('period.month'), 1)->format('M, Y') }}</div>
                    <div class="text-xs text-gray-500">Net {{ number_format((float) $payslip->get('totals.net'), 2) }}@if ($payslip->viewed_at === null) · <span class="text-primary-600">New</span>@endif</div>
                    <div class="mt-3 flex flex-wrap gap-3">
                        <x-filament::link :href="$this->salaryUrl($payslip)" size="sm">Open payslip</x-filament::link>
                        @if ($payslip->get('tax'))
                            {{ ($this->taxSlipAction)(['payslip' => $payslip->id]) }}
                        @endif
                        @if ($download = $this->downloadUrl($payslip))
                            <x-filament::link :href="$download" size="sm">Download</x-filament::link>
                        @endif
                    </div>
                </x-filament::section>
            @endforeach
        </div>
    @endif
</x-filament-panels::page>
