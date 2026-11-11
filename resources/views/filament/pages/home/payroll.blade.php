{{--
    Payroll: the latest run as one card. Its period and status, where it stands on the way from draft to paid (the run
    statuses in config, in order), and its headline figures. Net pay exists once the run is calculated.
--}}
@if ($h['payroll'] ?? null)
    @php
        $run = $h['payroll']['run'];
        $stages = config('peopleos.payroll.run_statuses', []);
        $at = $run ? array_search($run['stage'] ?? '', array_keys($stages), true) : false;
        $last = count($stages) - 1;
        $tone = ['draft' => 'neutral', 'calculated' => 'info', 'validated' => 'info', 'approved' => 'info', 'finalized' => 'success', 'paid' => 'success'][$run['stage'] ?? ''] ?? 'neutral';
    @endphp
    <x-pos.section title="Payroll" :link="$h['payroll']['url']" link-label="Control room">
        <div class="pos-panel pos-home-card">
            @if ($run)
                <div class="pos-home-pay-head">
                    <span class="pos-home-pay-mark" aria-hidden="true"><x-filament::icon icon="heroicon-o-banknotes" class="size-6" /></span>
                    <div class="pos-home-pay-text">
                        <p class="pos-home-kicker">Latest pay run</p>
                        <p class="pos-home-pay-title">{{ $run['label'] }}</p>
                    </div>
                    <x-pos.status :tone="$tone" :label="$run['status']" />
                    @if ($h['payroll']['url'] && $at !== false && $at < $last)
                        <a href="{{ $h['payroll']['url'] }}" wire:navigate class="pos-btn pos-btn-primary pos-btn-sm pos-home-pay-go">Continue the run <x-filament::icon icon="heroicon-m-arrow-right" class="size-4" /></a>
                    @endif
                </div>

                @if ($at !== false)
                    <ol class="pos-home-steps" aria-label="Where this run stands">
                        @foreach (array_values($stages) as $i => $label)
                            @php($state = $i < $at || ($i === $at && $at === $last) ? 'done' : ($i === $at ? 'current' : 'next'))
                            <li class="pos-home-step" data-state="{{ $state }}" @if ($i === $at) aria-current="step" @endif>
                                <span class="pos-home-step-dot" aria-hidden="true">@if ($state === 'done')<x-filament::icon icon="heroicon-m-check" class="size-3.5" />@endif</span>
                                <span class="pos-home-step-label">{{ $label }}@if ($state === 'done')<span class="sr-only"> (done)</span>@endif</span>
                            </li>
                        @endforeach
                    </ol>
                @endif

                <div class="pos-home-tiles">
                    @include('filament.pages.home.tile', ['f' => ['value' => number_format($run['employees']), 'label' => 'Employees in the run', 'icon' => 'heroicon-o-users', 'hue' => 'iris']])
                    @include('filament.pages.home.tile', ['f' => ['value' => (float) ($run['net'] ?? 0) > 0 ? number_format((float) $run['net'], 2) : '—', 'label' => 'Net pay',
                        'note' => (float) ($run['net'] ?? 0) > 0 ? null : 'Shown once the run is calculated', 'icon' => 'heroicon-o-banknotes', 'hue' => 'green']])
                    @include('filament.pages.home.tile', ['f' => ['value' => number_format($run['exceptions']), 'label' => 'Exceptions', 'icon' => 'heroicon-o-exclamation-triangle', 'hue' => $run['exceptions'] > 0 ? 'coral' : 'slate',
                        'meaning' => $run['exceptions'] > 0 ? 'bad' : null, 'note' => $run['exceptions'] > 0 ? 'Resolve before sign-off' : 'None to resolve']])
                </div>
            @else
                <x-pos.state size="inline" icon="heroicon-o-banknotes" title="No payroll run yet." why="Open a run for the next pay period from the control room." />
            @endif
        </div>
    </x-pos.section>
@endif
