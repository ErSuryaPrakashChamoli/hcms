<x-filament-panels::page>
    <div class="grid gap-4 md:grid-cols-2">
        @foreach ($this->getPacks() as $p)
            <x-filament::section :heading="$p['name'] . ' (' . $p['code'] . ')'" :description="$p['currency'] . ' · ' . $p['locale'] . ' · ' . $p['timezone'] . ' · FY starts month ' . $p['financial_year_start_month'] . ' · ' . ($p['statutory_engine'] === 'india' ? 'full statutory engine' : 'generic statutory engine')" compact>
                <dl class="grid grid-cols-3 gap-y-1 text-sm">
                    <dt class="text-gray-500">Tax framework</dt><dd class="col-span-2">{{ $p['tax_framework'] }}</dd>
                    <dt class="text-gray-500">Social security</dt><dd class="col-span-2">{{ implode(', ', $p['social_security']) }}</dd>
                    <dt class="text-gray-500">Labour rules</dt><dd class="col-span-2">{{ $p['labour']['standard_weekly_hours'] }} h/week · notice {{ $p['labour']['default_notice_days'] }} d · min leave {{ $p['labour']['minimum_leave_days'] }} d · OT ×{{ $p['labour']['overtime_multiplier'] }}</dd>
                    <dt class="text-gray-500">Documents</dt><dd class="col-span-2">{{ implode(', ', $p['documents']) }}</dd>
                    <dt class="text-gray-500">Date / numbers</dt><dd class="col-span-2">{{ $p['date_format'] }} · {{ $p['number_format']['grouping'] }} grouping</dd>
                    <dt class="text-gray-500">Companies</dt><dd class="col-span-2">{{ $p['companies'] ? implode(', ', $p['companies']) : '—' }}</dd>
                </dl>
            </x-filament::section>
        @endforeach
    </div>
</x-filament-panels::page>
