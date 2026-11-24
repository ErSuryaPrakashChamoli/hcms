{{-- "Company values…", Review: the one value that changes, for one company, from a date. --}}
<div class="pos-clone space-y-3">
    <x-pos.step-banner tone="clear" title="Only this company changes" sub="The shared record and every other company keep their values." />
    <dl class="pos-body-sm grid grid-cols-1 gap-1 sm:grid-cols-2">
        @foreach ($rows as [$label, $value])
            <div><dt class="inline font-medium">{{ $label }}:</dt> <dd class="inline">{{ $value }}</dd></div>
        @endforeach
    </dl>
</div>
