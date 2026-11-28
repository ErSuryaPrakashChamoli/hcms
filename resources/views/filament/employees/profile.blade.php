{{--
    Employee 360 → Profile (owner, 2026-11-27): every detail of the person by area, read-only. The areas run down the
    left (one open at a time, as every PeopleOS browse); the open area shows its details, its lists and, for someone who
    may change them, what edits them. Key details that are not recorded are named at the top and in their area.
    $areas: ViewEmployee::profileAreas (EmployeeProfileDetails + edits).
--}}
@php($gaps = collect($areas)->filter(fn (array $a) => $a['missing'] !== []))
@php($gapCount = $gaps->sum(fn (array $a) => count($a['missing'])))
<x-pos.section title="Profile" id="profile-title" sub="Every detail of this person, by area. Choose an area on the left; Edit opens the form that changes it.">
    @if ($areas === [])
        <x-pos.state variant="denied" size="inline" title="Nothing here you can see." why="Each area follows its own access rules." />
    @else
        @if ($gapCount > 0)
            <div class="pos-prof-gaps" role="note">
                <x-filament::icon icon="heroicon-m-exclamation-triangle" class="pos-prof-gaps-icon size-5" />
                <div class="grid gap-2 min-w-0">
                    <p class="pos-body"><strong>{{ $gapCount }} key {{ $gapCount === 1 ? 'detail is' : 'details are' }} not recorded yet.</strong> Open an area to see which.</p>
                    <div class="pos-prof-chips">
                        @foreach ($gaps as $area)
                            <button type="button" class="pos-prof-chip" x-on:click="$dispatch('pos-profile-open', @js($area['key']))">
                                {{ $area['label'] }} <span class="pos-num">{{ count($area['missing']) }}</span>
                            </button>
                        @endforeach
                    </div>
                </div>
            </div>
        @else
            <p class="pos-prof-ok"><x-filament::icon icon="heroicon-m-check-circle" class="size-5" />Every key detail is recorded.</p>
        @endif

        <div class="pos-browse-wrap">
            <div class="pos-browse pos-prof" style="--pos-browse-rows: {{ count($areas) }}" x-data="posBrowse('employee-profile')" x-on:keydown="move($event)"
                x-on:pos-profile-open.window="open = $event.detail" role="group" aria-label="Profile areas">
                @foreach ($areas as $i => $area)
                    @php($id = 'pos-prof-'.$area['key'])
                    <button type="button" id="{{ $id }}-head" class="pos-browse-head" data-browse-key="{{ $area['key'] }}" aria-controls="{{ $id }}"
                        aria-expanded="{{ $i === 0 ? 'true' : 'false' }}" x-bind:aria-expanded="(open === @js($area['key'])).toString()" x-on:click="toggle(@js($area['key']))">
                        <span class="pos-browse-icon" aria-hidden="true"><x-filament::icon :icon="$area['icon']" class="size-4" /></span>
                        <span class="pos-browse-label">{{ $area['label'] }}</span>
                        @if ($area['missing'] !== [])
                            <span class="pos-prof-badge">{{ count($area['missing']) }}<span class="sr-only"> not recorded</span></span>
                        @endif
                        <x-filament::icon icon="heroicon-m-chevron-right" class="pos-browse-chevron size-4" />
                    </button>
                    <section id="{{ $id }}" class="pos-panel pos-browse-panel" aria-labelledby="{{ $id }}-title" x-show="open === @js($area['key'])" @if ($i > 0) x-cloak @endif>
                        <header class="pos-browse-panel-head pos-prof-head">
                            <span class="pos-browse-panel-icon" aria-hidden="true"><x-filament::icon :icon="$area['icon']" class="size-5" /></span>
                            <div class="min-w-0 flex-1">
                                <h3 id="{{ $id }}-title" class="pos-browse-panel-title">{{ $area['label'] }}</h3>
                                <p class="pos-browse-panel-sub">{{ $area['sub'] }}</p>
                            </div>
                            @if ($area['edits'] !== [] || $area['revealable'])
                                <div class="pos-prof-actions">
                                    @if ($area['revealable'])
                                        @if ($area['revealed'])
                                            <button type="button" class="pos-btn pos-btn-ghost pos-btn-sm" wire:click="hideProfileNumbers(@js($area['key']))">
                                                <x-filament::icon icon="heroicon-m-eye-slash" class="size-4" />Hide numbers
                                            </button>
                                        @else
                                            <button type="button" class="pos-btn pos-btn-ghost pos-btn-sm" wire:click="mountAction('revealProfile', @js(['area' => $area['key']]))">
                                                <x-filament::icon icon="heroicon-m-eye" class="size-4" />Show numbers
                                            </button>
                                        @endif
                                    @endif
                                    @foreach ($area['edits'] as $edit)
                                        @if (isset($edit['action']))
                                            <button type="button" class="pos-btn pos-btn-secondary pos-btn-sm" wire:click="mountAction(@js($edit['action']))">
                                                <x-filament::icon icon="heroicon-m-pencil-square" class="size-4" />{{ $edit['label'] }}
                                            </button>
                                        @else
                                            <a href="{{ $edit['url'] }}" class="pos-btn pos-btn-secondary pos-btn-sm">
                                                <x-filament::icon icon="heroicon-m-pencil-square" class="size-4" />{{ $edit['label'] }}
                                            </a>
                                        @endif
                                    @endforeach
                                </div>
                            @endif
                        </header>
                        <div class="pos-browse-panel-body">
                            @if ($area['revealable'])
                                <p class="pos-caption">{{ $area['revealed'] ? 'Full numbers are shown on this page because you gave a purpose; that is recorded in the audit trail.' : 'Numbers show their last four digits. Show numbers asks for a purpose and records it in the audit trail.' }}</p>
                            @endif
                            @if ($area['missing'] !== [])
                                <p class="pos-prof-missing-line"><x-filament::icon icon="heroicon-m-exclamation-circle" class="size-4" />Not recorded yet: {{ implode(', ', $area['missing']) }}.</p>
                            @endif
                            @if ($area['facts'] !== [])
                                <dl class="pos-fact-grid pos-prof-facts">
                                    @foreach ($area['facts'] as $fact)
                                        <div @if ($fact['key'] && $fact['value'] === null) data-missing @endif>
                                            <dt>{{ $fact['label'] }}</dt>
                                            <dd>@if ($fact['value'] !== null){{ $fact['value'] }}@elseif ($fact['key'])<span class="pos-prof-missing">Not recorded</span>@else<span class="pos-prof-blank">—</span>@endif</dd>
                                        </div>
                                    @endforeach
                                </dl>
                            @endif
                            @foreach ($area['lists'] as $list)
                                <div class="pos-prof-list">
                                    <h4 class="pos-browse-zone-title">{{ $list['title'] }} <span>{{ count($list['rows']) }}</span></h4>
                                    @if ($list['rows'] === [])
                                        <p class="pos-meta">{{ $list['empty'] }}</p>
                                    @else
                                        <div class="pos-prof-table-wrap">
                                            <table class="pos-prof-table">
                                                <thead><tr>@foreach ($list['columns'] as $column)<th scope="col">{{ $column }}</th>@endforeach</tr></thead>
                                                <tbody>
                                                    @foreach ($list['rows'] as $row)
                                                        <tr>@foreach ($row as $cell)<td>{{ $cell ?? '—' }}</td>@endforeach</tr>
                                                    @endforeach
                                                </tbody>
                                            </table>
                                        </div>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    </section>
                @endforeach
            </div>
        </div>
    @endif
</x-pos.section>
