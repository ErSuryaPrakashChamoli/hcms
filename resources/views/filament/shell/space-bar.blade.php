<span id="pos-main" tabindex="-1" class="sr-only">Main content</span>
@auth
    @php
        $posNav = app(\App\Domain\Experience\Services\ExperienceNavigation::class);
        $posArea = $posNav->currentArea(auth()->user());
        $posCurrent = $posArea ? $posNav->currentModule(auth()->user()) : null;
    @endphp
    @if ($posArea)
        @php($posGroups = collect($posArea['modules'])->groupBy('group'))
        <nav class="pos-spacebar" aria-label="{{ $posArea['label'] }} modules">
            <span class="pos-spacebar-title">{{ $posArea['section'] }} <span aria-hidden="true">/</span> {{ $posArea['label'] }}</span>
            <div class="pos-spacebar-scroll">
                @foreach ($posGroups as $group => $modules)
                    @if ($posGroups->count() > 1)
                        <span class="pos-spacebar-group">{{ $group }}</span>
                    @endif
                    @foreach ($modules as $module)
                        <a href="{{ $module['url'] }}" wire:navigate class="pos-spacebar-link" @if ($posCurrent && $posCurrent['key'] === $module['key']) aria-current="page" @endif>{{ $module['label'] }}</a>
                    @endforeach
                @endforeach
            </div>
        </nav>
    @endif
@endauth
