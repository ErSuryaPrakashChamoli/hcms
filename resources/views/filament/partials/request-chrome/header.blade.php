{{-- HROne parity RQ-2: whose request this is (the request form's header). --}}
<div class="flex items-center gap-3" data-request-header>
    <x-pos.avatar :name="$name" size="md" />
    <div class="min-w-0">
        <p class="pos-body-sm font-semibold">{{ $name }} <span class="pos-muted font-normal">({{ $code }})</span></p>
        <p class="pos-caption pos-muted">{{ implode(' · ', array_filter([$designation, $company])) ?: 'Employee' }}</p>
        @unless ($own)
            <p class="pos-caption">You are raising this request for {{ $name }}.</p>
        @endunless
    </div>
</div>
