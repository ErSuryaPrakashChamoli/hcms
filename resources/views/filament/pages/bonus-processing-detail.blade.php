{{-- HROne parity PO-22: how one employee's bonus was computed (BonusProcessing::detailRows). --}}
<dl class="grid grid-cols-1 gap-x-6 gap-y-3 text-sm sm:grid-cols-2" data-bonus-detail>
    @foreach ($rows as $label => $value)
        <div>
            <dt class="text-gray-500">{{ $label }}</dt>
            <dd class="font-medium text-gray-950 dark:text-white">{{ $value }}</dd>
        </div>
    @endforeach
</dl>
