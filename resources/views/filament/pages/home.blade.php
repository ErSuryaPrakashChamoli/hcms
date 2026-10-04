<x-filament-panels::page>
    @php
        $h = $this->home;
        $tenant = app(\App\Support\Tenancy\TenantContext::class)->has();
        $experienceLabels = \App\Domain\Experience\Services\RoleLens::EXPERIENCE_LABELS;
        $num = fn ($v) => rtrim(rtrim(number_format((float) $v, 1), '0'), '.');
        $kpi = fn (string $key) => collect($h['kpis'] ?? [])->firstWhere('key', $key);
    @endphp

    @if (! $tenant)
        {{-- Platform administrators outside a tenant --}}
        <div class="pos-ws-cols">
            <x-pos.section title="Tenants" :link="$this->platform['tenants_url']" link-label="Manage tenants">
                <div class="pos-panel pos-stream">
                    @forelse ($this->platform['tenants'] as $t)
                        @php($tStatus = $t->status instanceof \BackedEnum ? $t->status->value : (string) $t->status)
                        <div class="pos-stream-row" data-tone="{{ $tStatus === 'active' ? 'success' : 'warning' }}">
                            <x-pos.avatar :name="$t->name" size="sm" />
                            <div class="pos-stream-body"><p class="pos-stream-title">{{ $t->name }}</p><p class="pos-stream-meta">{{ $t->slug }}</p></div>
                            <x-pos.status :tone="$tStatus === 'active' ? 'success' : 'warning'" :label="ucfirst($tStatus)" />
                        </div>
                    @empty
                        <x-pos.state title="No tenants yet" why="Provision the first tenant to start." />
                    @endforelse
                </div>
            </x-pos.section>
            <x-pos.section title="Platform health">
                <div class="pos-panel pos-panel-pad grid gap-3">
                    <p class="pos-body pos-secondary">Readiness, audit chains, queues and statutory status.</p>
                    @if ($this->platform['readiness_url'])<a href="{{ $this->platform['readiness_url'] }}" wire:navigate class="pos-btn pos-btn-secondary pos-btn-sm justify-self-start">Open readiness</a>@endif
                </div>
            </x-pos.section>
        </div>
    @else
        <div class="pos-ws pos-home">
            {{-- Where am I, what matters, what can I do: the brief of the day comes from real counts only. --}}
            <header class="pos-ws-head">
                <div class="pos-ws-head-text">
                    <p class="pos-ws-eyebrow">{{ now()->format('l, j F') }}@if ($h['tenant']) · {{ $h['tenant'] }}@endif</p>
                    <h1 class="pos-ws-title pos-ws-title-display">{{ $this->getTitle() }}.</h1>
                    <p class="pos-ws-brief">{!! $this->briefHtml() !!}</p>
                </div>
                <div class="pos-ws-actions">
                    {{-- UX.16: the primary action is the role's own: the workforce story, governance, or decisions and leave --}}
                    @if ($h['experience'] === 'executive' && \App\Filament\Pages\WorkforceCommandCentre::canAccess())
                        <a href="{{ \App\Filament\Pages\WorkforceCommandCentre::getUrl() }}" wire:navigate class="pos-btn pos-btn-primary">Open Workforce pulse</a>
                    @elseif ($h['experience'] === 'admin' && \App\Filament\Pages\AdminCentre::canAccess())
                        <a href="{{ \App\Filament\Pages\AdminCentre::getUrl() }}" wire:navigate class="pos-btn pos-btn-primary">Open Admin Centre</a>
                    @elseif ($h['decision_count'] > 0 && \App\Filament\Pages\Approvals::canAccess())
                        <a href="{{ \App\Filament\Pages\Approvals::getUrl() }}" wire:navigate class="pos-btn pos-btn-primary">Review {{ $h['decision_count'] }}{{ ($h['decision_more'] ?? false) ? '+' : '' }} {{ $h['decision_count'] === 1 ? 'decision' : 'decisions' }}</a>
                    @elseif ($this->requestLeaveAction->isVisible() && $h['lens'] === 'employee')
                        <button type="button" wire:click="mountAction('requestLeave')" class="pos-btn pos-btn-primary" data-pos-action="request_leave_header">Request leave</button>
                    @endif
                    <button type="button" class="pos-btn pos-btn-secondary" x-data x-on:click="$dispatch('pos-command-open', { mode: 'actions' })">
                        <x-filament::icon icon="heroicon-m-plus" class="size-4" /> Start something
                    </button>
                </div>
            </header>

            {{-- UX.16: one chip per experience the person holds (HR admin and system admin are one Administration view) --}}
            @if (count($h['experiences']) > 1)
                <div class="pos-lens -mt-4" role="tablist" aria-label="View Home as">
                    <span class="pos-meta">View as</span>
                    @foreach ($h['experiences'] as $experience => $lens)
                        <button type="button" role="tab" class="pos-lens-chip" aria-selected="{{ $h['experience'] === $experience ? 'true' : 'false' }}" wire:click="switchLens('{{ $lens }}')">{{ $experienceLabels[$experience] ?? $experience }}</button>
                    @endforeach
                </div>
            @endif

            {{-- First-login welcome: what is different here, once, then gone for good --}}
            @if ($this->showWelcome())
                <section class="pos-panel pos-panel-pad pos-welcome-note" aria-labelledby="pos-welcome-title" x-data="{ shown: true }" x-show="shown">
                    <span class="pos-ai-orb" aria-hidden="true"></span>
                    {{-- flex basis from .pos-welcome-note (wraps the buttons below the text on phones) --}}
                    <div class="min-w-0">
                        <h2 id="pos-welcome-title" class="pos-stream-title font-semibold">Welcome to PeopleOS. Your work comes to you here.</h2>
                        <p class="pos-stream-meta">What matters is ranked here with the reason for each item. Press <span class="pos-kbd">Ctrl K</span> to find anyone or start anything, and <span class="pos-kbd">?</span> for every shortcut. Hover a name to peek; click it for more.</p>
                    </div>
                    <div class="flex gap-2">
                        <button type="button" class="pos-btn pos-btn-ghost pos-btn-sm" x-data x-on:click="$dispatch('pos-shortcuts')">Shortcuts</button>
                        <button type="button" class="pos-btn pos-btn-secondary pos-btn-sm" wire:click="dismissWelcome" x-on:click="shown = false">Got it</button>
                    </div>
                </section>
            @endif

            @php($attention = collect($h['next'])->reject(fn ($n) => $n['approval_id']))
            <div class="pos-ws-cols" data-experience="{{ $h['experience'] }}">
                <div class="pos-ws-main">
                    @foreach ($h['main'] as $section)
                        @include('filament.pages.home.'.$section, ['h' => $h, 'kpi' => $kpi, 'num' => $num, 'attention' => $attention])
                    @endforeach
                </div>

                <aside class="pos-ws-side" aria-label="{{ ['employee' => 'Your people and momentum', 'manager' => 'Your team'][$h['experience']] ?? 'Shortcuts' }}">
                    @foreach ($h['side'] as $section)
                        @if ($section === 'intelligence')
                            <x-pos.intelligence :intel="$this->intelligence" />
                        @else
                            @include('filament.pages.home.'.str_replace('_', '-', $section), ['h' => $h, 'kpi' => $kpi, 'num' => $num])
                        @endif
                    @endforeach
                </aside>
            </div>
        </div>
    @endif
</x-filament-panels::page>
