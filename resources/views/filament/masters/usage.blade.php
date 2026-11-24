{{-- Master relationships R4: Usage tab (MasterUsage counts; other records are never named). --}}
<div class="grid gap-3" data-master-tab="usage">
    @if ($uses === [])
        <x-pos.state variant="caught-up" title="Nothing uses it." why="It can be deleted. Its history stays in the audit trail." />
    @else
        <ul class="grid gap-1 text-sm">
            @foreach ($uses as $use)
                <li>{{ $use['label'] }}</li>
            @endforeach
        </ul>
        <p class="text-xs text-gray-500">In use, so it cannot be deleted. {{ $active ? 'Make it inactive instead: it leaves the pickers, and the records that use it keep it.' : 'It is inactive: it is out of the pickers, and the records that use it keep it.' }}</p>
    @endif
</div>
