<x-filament-panels::page>
    @php
        $run = $this->getRun();
        $findings = $run ? $this->getFindings() : [];
        $counts = collect($findings)->countBy('severity');
        $tone = ['high' => 'danger', 'medium' => 'warning', 'low' => 'info'];
        $icon = ['high' => 'heroicon-o-shield-exclamation', 'medium' => 'heroicon-o-exclamation-triangle', 'low' => 'heroicon-o-information-circle'];
        $cfg = fn (string $k) => config('peopleos.ai.payroll_audit.'.$k);
        // A finding's evidence: plain values as "label value" pairs; a run entry's exceptions as their own list (the
        // detector also joins them into the message, so the list replaces that sentence here).
        $evidence = function (array $f): array {
            $pairs = collect($f['evidence'] ?? [])->except('exceptions')->filter(fn ($v) => is_scalar($v) && $v !== '')
                ->map(fn ($v, $k) => ['label' => ucfirst(str_replace('_', ' ', (string) $k)), 'value' => is_float($v) ? number_format($v, 2) : (string) $v])->values()->all();
            $exceptions = collect($f['evidence']['exceptions'] ?? [])->filter(fn ($x) => is_array($x) && ($x['message'] ?? '') !== '')->values()->all();

            return [$pairs, $exceptions];
        };
    @endphp

    @if (! $run)
        <div class="pos-panel">
            <x-pos.state variant="empty" title="No calculated payroll run to audit yet." why="Once a run is calculated, its unusual entries appear here for a reviewer." />
        </div>
    @else
        <div class="pos-ws pos-audit">
            <section class="pos-panel pos-audit-summary" aria-labelledby="pos-audit-run">
                <div class="pos-audit-run">
                    <p class="pos-ws-eyebrow">Payroll run</p>
                    <h2 id="pos-audit-run" class="pos-section-title">{{ $run->period->label() }} · {{ $run->company->name }}</h2>
                    <p class="pos-meta">{{ number_format($run->total('employees')) }} employees · net {{ number_format($run->total('net'), 2) }} · {{ config('peopleos.payroll.run_statuses.'.$run->status) }}</p>
                </div>
                <ul class="pos-audit-counts" aria-label="Findings by severity">
                    @foreach (['high' => 'High', 'medium' => 'Medium', 'low' => 'Low'] as $sev => $label)
                        <li class="pos-audit-count" data-tone="{{ ($counts[$sev] ?? 0) > 0 ? $tone[$sev] : 'neutral' }}">
                            <span class="pos-audit-count-value">{{ $counts[$sev] ?? 0 }}</span>
                            <span class="pos-audit-count-label">{{ $label }}</span>
                        </li>
                    @endforeach
                </ul>
                <p class="pos-audit-rules">
                    <x-filament::icon icon="heroicon-m-adjustments-horizontal" class="size-4" />
                    <span>System-generated indicators for a reviewer. Thresholds: net change {{ $cfg('net_change_pct') }}%, deductions {{ $cfg('deduction_share_pct') }}% of gross, LOP {{ $cfg('lop_days') }} days, TDS jump {{ $cfg('tds_jump_pct') }}%, salary revision {{ $cfg('salary_revision_pct') }}%.</span>
                </p>
            </section>

            <x-pos.section title="Findings" :count="count($findings) ?: null" sub="Most severe first. Each names the employee, what looks unusual and the evidence behind it.">
                @if ($findings === [])
                    <div class="pos-panel">
                        <x-pos.state variant="caught-up" title="Nothing unusual against the configured thresholds." why="Every entry in this run is within the limits above." />
                    </div>
                @else
                    <ul class="pos-panel pos-stream pos-audit-list">
                        @foreach (collect($findings)->sortBy(fn ($f) => array_search($f['severity'], ['high', 'medium', 'low'], true))->values() as $f)
                            @php
                                [$pairs, $exceptions] = $evidence($f);
                                $sev = $f['severity'];
                            @endphp
                            <li class="pos-stream-row pos-audit-row" data-tone="{{ $tone[$sev] ?? 'neutral' }}">
                                <span class="pos-stream-icon" aria-hidden="true"><x-filament::icon :icon="$icon[$sev] ?? 'heroicon-o-flag'" class="size-5" /></span>
                                <div class="pos-stream-body">
                                    <p class="pos-stream-title">{{ $f['employee'] }}</p>
                                    <p class="pos-audit-message">{{ $exceptions !== [] ? count($exceptions).' blocking '.\Illuminate\Support\Str::plural('exception', count($exceptions)).' stop this entry from being paid.' : $f['message'] }}</p>
                                    @if ($exceptions !== [])
                                        <ul class="pos-audit-exceptions">
                                            @foreach ($exceptions as $x)
                                                <li>{{ $x['message'] }}</li>
                                            @endforeach
                                        </ul>
                                    @endif
                                    @if ($pairs !== [])
                                        <p class="pos-audit-evidence">
                                            @foreach ($pairs as $p)
                                                <span class="pos-audit-pair"><span>{{ $p['label'] }}</span> {{ $p['value'] }}</span>
                                            @endforeach
                                        </p>
                                    @endif
                                </div>
                                <div class="pos-stream-end pos-audit-tags">
                                    <span class="pos-audit-sev">{{ ucfirst($sev) }}</span>
                                    <span class="pos-audit-type">{{ ucfirst(str_replace('_', ' ', $f['type'])) }}</span>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-pos.section>
        </div>
    @endif
</x-filament-panels::page>
