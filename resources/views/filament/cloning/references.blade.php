{{-- Clone wizard: what the source points at, and what the copy does with each. --}}
<div class="pos-clone pos-body-sm">
    @if ($references === [])
        <p>It points at nothing else: there is nothing to decide here.</p>
    @else
        <p class="mb-2">Records shared by every company are reused as they are; nothing here is copied or changed.</p>
        <div class="overflow-x-auto" tabindex="0" role="region" aria-label="Dependencies">
            <table class="w-full">
                <thead><tr><th class="text-start">Setting</th><th class="text-start">Points at</th><th class="text-start">Kind</th><th class="text-start">The copy</th></tr></thead>
                <tbody>
                    @foreach ($references as $reference)
                        <tr>
                            <td>{{ $reference->label }}</td>
                            <td>{{ $reference->targetLabel ?? $reference->value }}@if ($reference->problem)<br><span class="text-warning-600">{{ ucfirst($reference->problem) }}</span>@endif</td>
                            <td>{{ $reference->kind->label() }}</td>
                            <td>{{ $reference->strategy->label() }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
