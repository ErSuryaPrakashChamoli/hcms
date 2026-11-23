{{-- Clone wizard, step 1: what is being copied (configuration cloning). --}}
<div class="pos-clone space-y-3">
    <dl class="pos-body-sm grid grid-cols-1 gap-1 sm:grid-cols-2">
        @foreach ($rows as [$label, $value])
            <div><dt class="inline font-medium">{{ $label }}:</dt> <dd class="inline">{{ $value }}</dd></div>
        @endforeach
    </dl>
    <div class="pos-body-sm">
        <p class="font-medium">Applies to today</p>
        @if ($rules === [])
            <p>Nobody: it has no current applicability rules.</p>
        @else
            <ul class="list-disc ps-5">@foreach ($rules as $rule)<li>{{ $rule }}</li>@endforeach</ul>
        @endif
        @if (($notCopied ?? 0) > 0)
            <p class="text-gray-500">{{ $notCopied }} inactive or ended rule(s) are not copied.</p>
        @endif
    </div>
    @if ($copies > 0)
        <p class="pos-body-sm text-gray-500">Cloned {{ $copies }} time(s) before.</p>
    @endif
    <p class="pos-body-sm text-gray-500">
        Same rules for another company? One shared record is easier to keep up than two copies: extend this record's applicability instead.
        @if ($rulesUrl)
            <a class="underline" href="{{ $rulesUrl }}">Open its rules</a>
        @endif
    </p>
</div>
