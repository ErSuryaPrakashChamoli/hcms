<li wire:key="chart-{{ $node->id }}">
    <div @class(['pos-card', 'is-inactive' => $node->status === \App\Domain\Organisation\Enums\ActiveStatus::Inactive])>
        <span class="pos-type">{{ $node->typeLabel() }}</span>
        <span class="pos-name">{{ $node->nodeable?->name ?? '—' }}</span>
        <span class="pos-code" style="display:block">{{ $node->nodeable?->code }}</span>
    </div>
    @if ($node->children->isNotEmpty())
        <ul>
            @foreach ($node->children as $child)
                @include('filament.pages.partials.organisation-chart-node', ['node' => $child])
            @endforeach
        </ul>
    @endif
</li>
