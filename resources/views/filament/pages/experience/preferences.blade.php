<x-filament-panels::page>
    @php($p = $this->prefs)
    <div class="grid max-w-3xl gap-[var(--pos-gap)]">
        <section class="pos-card" aria-labelledby="pref-density">
            <h2 id="pref-density" class="pos-h3">Density</h2>
            <p class="pos-body-sm mt-1">Comfortable gives content room to breathe. Compact fits more rows in tables and lists.</p>
            <div class="pos-segmented mt-3" role="group" aria-label="Density">
                <button type="button" wire:click="setDensity('comfortable')" aria-pressed="{{ $p['density'] === 'comfortable' ? 'true' : 'false' }}">Comfortable</button>
                <button type="button" wire:click="setDensity('compact')" aria-pressed="{{ $p['density'] === 'compact' ? 'true' : 'false' }}">Compact</button>
            </div>
        </section>

        @if (count($this->lenses) > 1)
            <section class="pos-card" aria-labelledby="pref-lens">
                <h2 id="pref-lens" class="pos-h3">Home opens as</h2>
                <p class="pos-body-sm mt-1">You work in more than one role. Choose what Home shows first; you can switch on Home at any time.</p>
                <div class="pos-chips mt-3" role="group" aria-label="Default lens">
                    <button type="button" class="pos-chip" wire:click="setLens(null)" aria-pressed="{{ $p['lens'] === null ? 'true' : 'false' }}">Automatic</button>
                    @foreach ($this->lenses as $key => $label)
                        <button type="button" class="pos-chip" wire:click="setLens('{{ $key }}')" aria-pressed="{{ $p['lens'] !== null && \App\Domain\Experience\Services\RoleLens::experienceOf($p['lens']) === \App\Domain\Experience\Services\RoleLens::experienceOf($key) ? 'true' : 'false' }}">{{ $label }}</button>
                    @endforeach
                </div>
            </section>
        @endif

        <section class="pos-card" aria-labelledby="pref-pins">
            <div class="pos-card-head">
                <h2 id="pref-pins" class="pos-h3">Pinned people</h2>
                <span class="pos-caption">Pin from any person preview</span>
            </div>
            @if ($this->pinned->isEmpty())
                <p class="pos-body-sm mt-2">No one is pinned. Pinned people appear in the side rail for one-click previews.</p>
            @else
                <ul class="pos-list mt-2">
                    @foreach ($this->pinned as $e)
                        <li class="pos-list-row">
                            <x-pos.avatar :name="$e->display_name" size="sm" />
                            <span class="flex-1 pos-body">{{ $e->display_name }}</span>
                            <button type="button" class="pos-btn pos-btn-ghost pos-btn-sm" wire:click="unpin({{ $e->id }})">Unpin</button>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>

        <section class="pos-card" aria-labelledby="pref-more">
            <h2 id="pref-more" class="pos-h3">Home and history</h2>
            <div class="mt-3 flex flex-wrap gap-2">
                <button type="button" class="pos-btn pos-btn-secondary pos-btn-sm" wire:click="restoreCards" @disabled(($p['home_hidden'] ?? []) === [])>Restore hidden Home cards</button>
                <button type="button" class="pos-btn pos-btn-secondary pos-btn-sm" wire:click="clearRecent" @disabled(($p['recent'] ?? []) === [])>Clear recent items</button>
                @if (\App\Filament\Pages\MyHr::canAccess())
                    <a href="{{ \App\Filament\Pages\MyHr::getUrl(['tab' => 'preferences']) }}" wire:navigate class="pos-btn pos-btn-ghost pos-btn-sm">Notification preferences</a>
                @endif
            </div>
            <p class="pos-caption mt-3">Light, dark or system theme: use the theme switcher in your avatar menu.</p>
        </section>
    </div>
</x-filament-panels::page>
