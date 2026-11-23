{{-- Clone wizard, Review: what will be created, and what must be fixed or read first. --}}
<div class="pos-clone space-y-3">
    @if ($error)
        <x-pos.step-banner tone="blocked" title="This clone cannot be made" :sub="$error" />
    @elseif ($blockers !== [])
        <x-pos.step-banner tone="blocked" title="Fix these first" sub="Go back to the step named in each line.">
            <ul class="pos-body-sm list-disc ps-5">@foreach ($blockers as $conflict)<li>{{ $conflict->message }}</li>@endforeach</ul>
        </x-pos.step-banner>
    @elseif ($warnings !== [])
        <x-pos.step-banner tone="warning" title="Read before creating" sub="Nothing here stops the copy; tick below once you have read it.">
            <ul class="pos-body-sm list-disc ps-5">@foreach ($warnings as $conflict)<li>{{ $conflict->message }}</li>@endforeach</ul>
        </x-pos.step-banner>
    @else
        <x-pos.step-banner tone="clear" title="Ready to create" sub="Nothing to fix." />
    @endif
    @if ($copies !== [])
        <ul class="pos-body-sm list-disc ps-5">
            @foreach ($copies as $copy)
                <li>{{ $copy }}</li>
            @endforeach
        </ul>
        <p class="pos-body-sm text-gray-500">Each copy is created inactive. Nothing in the original changes, and later changes to either never reach the other.</p>
    @endif
</div>
