<x-filament-panels::page>
    {{-- The salary structure builder in the step kit (theme.css .pos-step-*): the step rail on the left, the open step on the
         right, what stops the step said once at the top, and Back / Next naming the steps either side. --}}
    @php
        $steps = \App\Filament\Pages\SalaryStructureBuilder::STEPS;
        $current = $steps[$this->step] ?? $steps[0];
        $count = count($steps);
        $locked = $this->locked();
        $structure = $this->structure();
        $english = ($this->data['input_mode'] ?? 'list') === 'english';
        $money = fn ($value) => $value === null ? '—' : number_format((float) $value, 0);
    @endphp
    <div class="grid gap-6 lg:grid-cols-5">
        <nav aria-label="Salary structure steps">
            <x-filament::section compact>
                <ol class="pos-step-rail is-flush">
                    @foreach ($steps as $i => $s)
                        @php
                            $state = $i < $this->step ? 'done' : ($i === $this->step ? 'current' : 'waiting');
                            $open = $i <= $this->reached;
                        @endphp
                        <li data-state="{{ $state }}">
                            <button type="button" wire:click="goTo({{ $i }})" @disabled(! $open) class="pos-step-rail-btn" data-state="{{ $state }}"
                                @if ($i === $this->step) aria-current="step" @endif>
                                <span class="pos-step-rail-dot" aria-hidden="true">
                                    @if ($state === 'done')
                                        <x-filament::icon icon="heroicon-m-check" class="pos-step-rail-check" />
                                    @else
                                        {{ $i + 1 }}
                                    @endif
                                </span>
                                <span class="pos-step-rail-label">{{ $s['label'] }}</span>
                            </button>
                        </li>
                    @endforeach
                </ol>
            </x-filament::section>
        </nav>

        <div class="grid gap-6 lg:col-span-4">
            @if ($locked)
                <x-pos.step-banner tone="pending" title="Waiting for approval"
                    sub="A change to this structure is with an approver. You can look through it here, and change it again once it is approved or sent back.">
                    <x-slot:aside>
                        <a href="{{ \App\Filament\Resources\SalaryStructures\SalaryStructureResource::getUrl('edit', ['record' => $structure]) }}" wire:navigate class="pos-step-link">Open approvals<x-filament::icon icon="heroicon-m-arrow-up-right" class="pos-step-link-icon" /></a>
                    </x-slot:aside>
                </x-pos.step-banner>
            @endif

            <form wire:submit="{{ $this->isLast() ? 'saveDraft' : 'next' }}">
                <x-filament::section :heading="($this->step + 1).'. '.$current['label']" :description="$current['help']">
                    <div class="pos-step">
                        <p class="pos-step-count" style="--pos-step-pct: {{ (int) round(($this->step + 1) * 100 / max(1, $count)) }}">Step {{ $this->step + 1 }} of {{ $count }}</p>

                        @if ($this->problems !== [])
                            <x-pos.step-banner tone="blocked" title="Fix {{ count($this->problems) === 1 ? 'this' : 'these' }} to carry on">
                                <ul class="pos-step-reasons">@foreach ($this->problems as $problem)<li>{{ $problem }}</li>@endforeach</ul>
                            </x-pos.step-banner>
                        @endif

                        {{ $this->form }}

                        {{-- Components, plain English: the button and what each line was read as --}}
                        @if ($current['key'] === 'components' && $english)
                            <div class="pos-step-actions">
                                <x-filament::button type="button" wire:click="convert" icon="heroicon-m-sparkles" wire:target="convert">Turn into components</x-filament::button>
                                <span class="pos-step-note">You can fine-tune the result under “Pick from a list”.</span>
                            </div>
                            @if ($this->report !== [])
                                <ul class="pos-step-rules" aria-live="polite">
                                    @foreach ($this->report as $entry)
                                        @php
                                            [$tone, $icon] = match ($entry['status']) {
                                                'error' => ['blocked', 'heroicon-m-exclamation-circle'],
                                                'skipped' => ['na', 'heroicon-m-minus-circle'],
                                                'note' => ['warning', 'heroicon-m-information-circle'],
                                                'new' => ['pending', 'heroicon-m-plus-circle'],
                                                default => ['clear', 'heroicon-m-check-circle'],
                                            };
                                        @endphp
                                        <li data-tone="{{ $tone }}">
                                            <span class="pos-step-rule-icon" aria-hidden="true"><x-filament::icon :icon="$icon" class="pos-step-rule-svg" /></span>
                                            <span class="pos-step-rule-text">
                                                <strong>{{ $entry['line'] }}</strong>
                                                <span @class(['is-alert' => $entry['status'] === 'error'])>{{ $entry['status'] === 'error' ? $entry['text'] : ($entry['status'] === 'skipped' ? $entry['text'] : 'Read as: '.$entry['text']) }}</span>
                                            </span>
                                        </li>
                                    @endforeach
                                </ul>
                            @endif
                        @endif

                        {{-- Check: the breakup for the sample --}}
                        @if ($current['key'] === 'check')
                            @php $b = $this->breakup(); @endphp
                            @if ($b['problems'] !== [])
                                <x-pos.step-banner tone="blocked" title="The components need a fix first">
                                    <ul class="pos-step-reasons">@foreach ($b['problems'] as $problem)<li>{{ $problem }}</li>@endforeach</ul>
                                    <x-slot:aside><button type="button" class="pos-step-link" wire:click="goTo(2)">Back to components</button></x-slot:aside>
                                </x-pos.step-banner>
                            @else
                                @php $t = $b['totals']; @endphp
                                @if ($b['warnings'] === [])
                                    <x-pos.step-banner tone="clear" title="Adds up" :sub="($this->data['ctc_mode'] ?? 'ctc') === 'components' ? 'The CTC is added up from these amounts.' : 'The components add up to the CTC.'" />
                                @else
                                    <x-pos.step-banner tone="warning" title="Have a look">
                                        <ul class="pos-step-reasons">@foreach ($b['warnings'] as $warning)<li>{{ $warning }}</li>@endforeach</ul>
                                    </x-pos.step-banner>
                                @endif
                                <dl class="pos-step-stats">
                                    <div class="pos-step-stat is-key"><dt>CTC a month</dt><dd>{{ $money($t['ctc_monthly']) }}</dd></div>
                                    <div class="pos-step-stat" data-tone="idle"><dt>Gross pay a month</dt><dd>{{ $money($t['gross']) }}</dd></div>
                                    <div class="pos-step-stat" data-tone="na"><dt>Employer PF a month</dt><dd>{{ $money($t['pf_employer']) }}</dd></div>
                                </dl>
                                <div class="pos-step-table-wrap" tabindex="0">
                                    <table class="pos-step-table">
                                        <thead><tr><th>Component</th><th>Worked out as</th><th class="is-num">A month</th><th class="is-num">A year</th></tr></thead>
                                        <tbody>
                                            @foreach ($b['rows'] as $row)
                                                <tr>
                                                    <td>{{ $row['name'] }}@if ($row['type'] !== 'earning')<span class="pos-step-note"> {{ config('peopleos.payroll.component_types.'.$row['type'], $row['type']) }}</span>@endif @if ($row['nature'] === 'variable')<span class="pos-step-note"> variable</span>@endif</td>
                                                    <td>{{ $row['how'] }}@if ($row['missing'])<span class="pos-step-note"> — type a sample amount above</span>@endif</td>
                                                    <td class="is-num">{{ $money($row['monthly']) }}</td>
                                                    <td class="is-num">{{ $money($row['annual']) }}</td>
                                                </tr>
                                            @endforeach
                                            <tr>
                                                <td>Employer PF</td><td>Worked out by payroll</td>
                                                <td class="is-num">{{ $money($t['pf_employer']) }}</td><td class="is-num">{{ $money($t['pf_employer'] * 12) }}</td>
                                            </tr>
                                            <tr>
                                                <td><strong>Total (CTC)</strong></td><td></td>
                                                <td class="is-num"><strong>{{ $money($t['breakup']) }}</strong></td><td class="is-num"><strong>{{ $money($t['breakup'] * 12) }}</strong></td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                                <p class="pos-step-note">PF, ESI, professional tax and income tax come off the employee’s pay during payroll, and pay is reduced for unpaid days. This is a full month before those.</p>
                            @endif
                        @endif

                        {{-- Save: the structure read back, and what happens next --}}
                        @if ($current['key'] === 'save')
                            @php $s = $this->summary(); @endphp
                            @if ($s['problems'] !== [])
                                <x-pos.step-banner tone="blocked" title="The components need a fix first">
                                    <ul class="pos-step-reasons">@foreach ($s['problems'] as $problem)<li>{{ $problem }}</li>@endforeach</ul>
                                    <x-slot:aside><button type="button" class="pos-step-link" wire:click="goTo(2)">Back to components</button></x-slot:aside>
                                </x-pos.step-banner>
                            @endif
                            <div class="pos-step-review">
                                <section class="pos-step-group" aria-label="The structure">
                                    <div class="pos-step-group-head">
                                        <h3 class="pos-step-subhead">The structure</h3>
                                        <button type="button" class="pos-step-link" wire:click="goTo(0)">Edit<span class="fi-sr-only"> name and company</span><x-filament::icon icon="heroicon-m-pencil-square" class="pos-step-link-icon" /></button>
                                    </div>
                                    <dl class="pos-step-ledger">
                                        @foreach ($s['basics'] as [$label, $value])
                                            <div><dt>{{ $label }}</dt><dd>{{ $value }}</dd></div>
                                        @endforeach
                                    </dl>
                                </section>
                                <section class="pos-step-group" aria-label="Components">
                                    <div class="pos-step-group-head">
                                        <h3 class="pos-step-subhead">Components, in the order they are worked out</h3>
                                        <button type="button" class="pos-step-link" wire:click="goTo(2)">Edit<span class="fi-sr-only"> components</span><x-filament::icon icon="heroicon-m-pencil-square" class="pos-step-link-icon" /></button>
                                    </div>
                                    <ul class="pos-step-rules">
                                        @foreach ($s['sentences'] as $i => $line)
                                            <li data-tone="{{ $line['new'] ? 'pending' : 'idle' }}">
                                                <span class="pos-step-rule-icon" aria-hidden="true">{{ $i + 1 }}.</span>
                                                <span class="pos-step-rule-text"><strong>{{ $line['text'] }}</strong>@if ($line['new'])<span>New: added to your component list when you save.</span>@endif</span>
                                            </li>
                                        @endforeach
                                    </ul>
                                </section>
                                <section class="pos-step-group" aria-label="What happens next">
                                    <h3 class="pos-step-subhead">What happens next</h3>
                                    <ol class="pos-step-tasks">
                                        <li class="pos-step-task"><span class="pos-step-task-n">1</span><span class="pos-step-task-text"><strong>Send it for approval</strong><span>Someone with approval rights checks it. It cannot be the person who prepared it.</span></span></li>
                                        <li class="pos-step-task"><span class="pos-step-task-n">2</span><span class="pos-step-task-text"><strong>{{ $s['existing'] ? 'It applies from '.$s['date'] : 'Give it to employees' }}</strong><span>{{ $s['existing'] ? 'Employees already on this structure are paid by the new rules from that date.' : 'On each employee’s salary (or by salary upload) pick this structure and enter the CTC'.(($this->data['ctc_mode'] ?? 'ctc') === 'components' ? ' or the amounts' : '').'.' }}</span></span></li>
                                        <li class="pos-step-task"><span class="pos-step-task-n">3</span><span class="pos-step-task-text"><strong>Run payroll</strong><span>Payroll works out each component this way, then PF, ESI, PT and TDS.</span></span></li>
                                    </ol>
                                </section>
                            </div>
                        @endif

                        <div class="pos-step-foot">
                            <div>
                                @if ($this->step > 0)
                                    <x-filament::button color="gray" icon="heroicon-m-arrow-left" wire:click="previous" type="button">{{ $this->stepLabel($this->step - 1) }}</x-filament::button>
                                @endif
                            </div>
                            <div class="pos-step-foot-end">
                                <x-filament::link :href="\App\Filament\Resources\SalaryStructures\SalaryStructureResource::getUrl('index')" color="gray">Cancel</x-filament::link>
                                @if ($this->isLast())
                                    <x-filament::button type="submit" color="gray" icon="heroicon-m-document" :disabled="$locked">Save as draft</x-filament::button>
                                    <x-filament::button type="button" wire:click="sendForApproval" icon="heroicon-m-paper-airplane" :disabled="$locked">Send for approval</x-filament::button>
                                @else
                                    <x-filament::button type="submit" icon="heroicon-m-arrow-right" icon-position="after">Next: {{ $this->stepLabel($this->step + 1) }}</x-filament::button>
                                @endif
                            </div>
                        </div>
                    </div>
                </x-filament::section>
            </form>
        </div>
    </div>
</x-filament-panels::page>
