{{-- Clone wizard: whom each copy applies to, before → after, and who that is today. --}}
<div class="pos-clone space-y-4 pos-body-sm">
    @if ($error)
        <p>{{ $error }}</p>
    @endif
    @foreach ($companies as $company)
        <section class="space-y-1" aria-label="{{ $company['name'] }}">
            <p class="font-medium">{{ $company['name'] }}</p>
            @if ($company['after'] === [])
                <p>No rule is copied: the copy applies to nobody until you choose who it applies to.</p>
            @else
                <ul class="list-disc ps-5">@foreach ($company['after'] as $rule)<li>{{ $rule }}</li>@endforeach</ul>
            @endif
            @if ($company['impact'])
                <p>{{ $company['impact'] }}</p>
            @endif
            @foreach ($company['notes'] as $note)
                <p class="text-gray-500">{{ $note }}</p>
            @endforeach
        </section>
    @endforeach
    <p class="text-gray-500">The copy's rules are saved inactive. Publishing the copy turns them on.</p>
</div>
