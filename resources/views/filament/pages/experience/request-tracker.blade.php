<x-filament-panels::page>
    @php
        $d = $this->details();
        $trail = $d['trail'];
        $discussion = $d['discussion'];
    @endphp
    <div class="pos-ws pos-request-tracker space-y-4">
        <section class="pos-panel pos-panel-pad" aria-label="The request">
            {!! $d['header'] !!}
            <div class="mt-3 flex flex-wrap items-center gap-2">
                <x-pos.status :tone="$d['status']->tone()" :label="$d['status_label']" />
                <span class="pos-body-sm font-semibold">{{ $d['title'] }}</span>
            </div>
            @if ($d['detail'])<p class="pos-body-sm pos-muted mt-1">{{ $d['detail'] }}</p>@endif
            @if ($trail['pending'] && $d['reminder'])<p class="pos-caption pos-muted mt-2">{{ $d['reminder'] }}</p>@endif
        </section>

        <section class="pos-panel pos-panel-pad" aria-labelledby="pos-request-trail">
            <h2 id="pos-request-trail" class="pos-section-title">Where it stands</h2>
            <div class="mt-2">
                @include('filament.partials.request-chrome.timeline', ['trail' => $trail, 'fallback' => $d['fallback']])
            </div>
        </section>

        @if ($discussion !== null)
            <section class="pos-panel pos-panel-pad" aria-labelledby="pos-request-discussion">
                <h2 id="pos-request-discussion" class="pos-section-title">Discussion</h2>
                <p class="pos-caption pos-muted">The approvers of this request read it too.</p>
                @if ($discussion['comments']->isEmpty())
                    <p class="pos-body-sm pos-muted mt-2">No messages yet.</p>
                @else
                    <ul class="mt-2 space-y-2" role="list">
                        @foreach ($discussion['comments'] as $comment)
                            <li>
                                <p class="pos-meta">{{ $comment->author?->name ?? 'Someone' }} · {{ $comment->created_at?->diffForHumans() }}</p>
                                <p class="text-sm whitespace-pre-line">{{ $comment->body }}</p>
                            </li>
                        @endforeach
                    </ul>
                @endif
                <form class="mt-3" wire:submit="post">
                    <label class="pos-label sr-only" for="pos-request-message">Message</label>
                    <textarea id="pos-request-message" wire:model="body" rows="2" maxlength="2000" class="pos-textarea" placeholder="Write a message the approvers will see" required></textarea>
                    @error('body')<p class="pos-caption text-pos-danger" role="alert">{{ $message }}</p>@enderror
                    <div class="mt-2"><button type="submit" class="pos-btn pos-btn-secondary pos-btn-sm" wire:loading.attr="disabled" wire:target="post">Post message</button></div>
                </form>
            </section>
        @endif

        @if ($d['policies'] !== [])
            <section class="pos-panel pos-panel-pad" aria-labelledby="pos-request-policy">
                <h2 id="pos-request-policy" class="pos-section-title">Read the policy</h2>
                <ul class="mt-2 space-y-1">
                    @foreach ($d['policies'] as $policy)
                        <li class="pos-body-sm">@if ($policy['url'])<a href="{{ $policy['url'] }}" class="pos-link">{{ $policy['title'] }}</a>@else{{ $policy['title'] }}@endif</li>
                    @endforeach
                </ul>
            </section>
        @endif
    </div>
</x-filament-panels::page>
