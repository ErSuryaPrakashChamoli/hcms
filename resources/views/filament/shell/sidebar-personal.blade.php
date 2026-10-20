@auth
    @php
        $posUser = auth()->user();
        $posPrefs = app(\App\Domain\Experience\Services\ExperiencePreferences::class)->for($posUser);
        $posPinned = collect();
        if (($posPrefs['pinned_people'] ?? []) !== [] && app(\App\Support\Tenancy\TenantContext::class)->has()) {
            // Pins are re-checked against the viewer's visibility on every render.
            $posPinned = app(\App\Domain\Experience\Services\PeopleVisibility::class)->query($posUser)
                ->with('person')->whereKey($posPrefs['pinned_people'])->limit(8)->get();
        }
        $posRecent = collect($posPrefs['recent'] ?? [])->take(5);
    @endphp
    @if ($posPinned->isNotEmpty() || $posRecent->isNotEmpty())
        <div class="pos-rail" x-show="$store.sidebar.isOpen" x-cloak>
            @if ($posPinned->isNotEmpty())
                <p class="pos-rail-label">Pinned people</p>
                <ul class="pos-rail-list">
                    @foreach ($posPinned as $pin)
                        <li>
                            <button type="button" class="pos-rail-item" x-data x-on:click="$dispatch('pos-drawer-open', { type: 'person', id: {{ $pin->id }} })">
                                <x-pos.avatar :name="$pin->display_name" size="xs" />
                                <span class="truncate">{{ $pin->display_name }}</span>
                            </button>
                        </li>
                    @endforeach
                </ul>
            @endif
            @if ($posRecent->isNotEmpty())
                <p class="pos-rail-label">Recent</p>
                <ul class="pos-rail-list">
                    @foreach ($posRecent as $recent)
                        <li>
                            <a href="{{ $recent['url'] }}" wire:navigate class="pos-rail-item">
                                <span class="pos-rail-dot" aria-hidden="true"></span>
                                <span class="truncate">{{ $recent['label'] }}</span>
                            </a>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    @endif
@endauth
