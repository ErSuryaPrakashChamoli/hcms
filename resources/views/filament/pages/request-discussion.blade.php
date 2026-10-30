<x-filament-panels::page>
    @php($d = $this->details())
    <x-filament::section>
        <x-slot name="heading">{{ $d['kind'] }}</x-slot>
        @if ($d['label'])
            <x-slot name="description">{{ $d['label'] }}</x-slot>
        @endif

        @if ($d['return'] && $d['return']->isOpen())
            <div class="space-y-2">
                <p class="text-sm"><strong>Sent back by {{ $d['return']->returner?->name ?? 'an approver' }}</strong> · {{ $d['return']->returned_at?->diffForHumans() }}</p>
                <p class="text-sm whitespace-pre-line">{{ $d['return']->note }}</p>
            </div>
        @endif

        <div class="mt-4">
            @include('filament.workflow.discussion-thread', ['comments' => $d['comments']])
        </div>

        @if ($d['mayReply'])
            <form wire:submit="post" class="mt-4 space-y-2">
                <label for="pos-discussion-body" class="text-sm">Message</label>
                <textarea id="pos-discussion-body" wire:model="body" rows="3" maxlength="2000" class="pos-textarea w-full" required></textarea>
                @error('body')<p class="text-sm text-danger-600" role="alert">{{ $message }}</p>@enderror
                <x-filament::button type="submit" color="gray" wire:target="post">Post message</x-filament::button>
            </form>
        @endif
    </x-filament::section>

    @if ($d['mayResubmit'])
        <x-filament::section>
            <x-slot name="heading">Resubmit</x-slot>
            <x-slot name="description">Answer the approver and send the request back to them.</x-slot>
            <form wire:submit="resubmit" class="space-y-2">
                <label for="pos-resubmit-response" class="text-sm">Your answer (optional)</label>
                <textarea id="pos-resubmit-response" wire:model="response" rows="3" maxlength="2000" class="pos-textarea w-full"></textarea>
                <x-filament::button type="submit" color="success" wire:target="resubmit">Resubmit</x-filament::button>
            </form>
        </x-filament::section>
    @endif
</x-filament-panels::page>
