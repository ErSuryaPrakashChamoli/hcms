<x-filament-panels::page>
    <div @if ($this->hasPendingPacks()) wire:poll.5s @endif>
        <x-filament::section>
            {{ $this->form }}
        </x-filament::section>

        @php($plan = $this->plan)
        @php($applicable = collect($plan)->where('applicable', true))
        @php($payroll = $this->payrollStatus())
        @php($gaps = $this->configurationGaps)
        <div class="pos-panel pos-panel-pad pos-figures mt-4">
            <div class="pos-figure"><span class="pos-figure-value">{{ $applicable->count() }}</span><span class="pos-figure-label">Reports due</span><span class="pos-figure-delta">{{ $this->period()->label() }}</span></div>
            <div class="pos-figure"><span class="pos-figure-value">{{ $applicable->where('target.type', 'establishment')->pluck('target.id')->unique()->count() }}</span><span class="pos-figure-label">Establishments</span><span class="pos-figure-delta">{{ $applicable->where('target.type', 'state')->pluck('target.id')->unique()->count() }} state(s) · {{ $applicable->where('target.type', 'legal_entity')->pluck('target.id')->unique()->count() }} TAN(s)</span></div>
            <div class="pos-figure"><span class="pos-figure-value" data-meaning="{{ collect($payroll)->contains('ok', false) ? 'warning' : '' }}">{{ collect($payroll)->where('ok', true)->count() }} / {{ count($payroll) }}</span><span class="pos-figure-label">Months with finalized payroll</span><span class="pos-figure-delta">{{ collect($payroll)->reject(fn ($p) => $p['ok'])->map(fn ($p) => $p['month'] . ': ' . $p['status'])->take(3)->implode(' · ') ?: 'All finalized' }}</span></div>
            <div class="pos-figure"><span class="pos-figure-value" data-meaning="{{ count($gaps) > 0 ? 'bad' : '' }}">{{ count($gaps) }}</span><span class="pos-figure-label">Configuration gaps</span><span class="pos-figure-delta">{{ $applicable->where('applicability', 'CONFIGURATION_REQUIRED')->count() }} report(s) affected</span></div>
            <div class="pos-figure"><span class="pos-figure-value">{{ $applicable->where('legal', 'LEGAL_SOURCE_VERIFIED')->count() }}</span><span class="pos-figure-label">Legal source verified</span><span class="pos-figure-delta">the rest need legal review</span></div>
        </div>

        <p class="mt-2 text-xs text-gray-500">These are registers, statements and working sheets prepared from PeopleOS data. None of them is a filing: returns and challans are filed by the employer or its authorised representative on the authority's portal.</p>

        @if (count($gaps) > 0)
            <x-filament::section heading="Configuration required" description="Facts PeopleOS needs and does not assume. Affected reports are marked; unaffected reports still run." class="mt-4">
                <div class="overflow-x-auto" tabindex="0" role="region" aria-label="Configuration required">
                    <table class="w-full text-sm">
                        <thead><tr class="text-left text-gray-500"><th class="py-1 px-2">Where</th><th class="px-2">Missing</th><th class="px-2">What it affects</th><th class="px-2">What to do</th></tr></thead>
                        <tbody>
                            @foreach ($gaps as $gap)
                                <tr class="border-t border-gray-100 dark:border-gray-800 align-top">
                                    <td class="py-1 px-2">{{ $gap['target'] }}</td>
                                    <td class="px-2 font-medium text-danger-600">{{ $gap['missing'] }}</td>
                                    <td class="px-2 text-gray-600 dark:text-gray-300">{{ $gap['affects'] }}</td>
                                    <td class="px-2">{{ $gap['action'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </x-filament::section>
        @endif

        @forelse ($this->groupedPlan() as $category => $items)
            <x-filament::section :heading="$category" class="mt-4" collapsible>
                <div class="overflow-x-auto" tabindex="0" role="region" aria-label="{{ $category }}">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="text-left text-gray-500">
                                <th class="py-1 px-2">Report</th><th class="px-2">Form / legal basis</th><th class="px-2">For</th><th class="px-2">Period</th><th class="px-2">Due</th><th class="px-2">Determination</th><th class="px-2"></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($items as $item)
                                <tr class="border-t border-gray-100 dark:border-gray-800 align-top">
                                    <td class="py-1 px-2 font-medium">{{ $item['definition']->title }}<div class="text-xs text-gray-500">{{ $item['definition']->frequencyLabel() }} · {{ $item['definition']->outputLabel() }}</div></td>
                                    <td class="px-2 text-gray-600 dark:text-gray-300">{{ $item['resolved']->form }}<div class="text-xs text-gray-500">{{ $item['resolved']->legalBasis }}</div>
                                        <div class="text-xs {{ in_array($item['legal'], ['LEGAL_SOURCE_VERIFIED', 'NOT_STATUTORY'], true) ? 'text-gray-500' : 'text-warning-600' }}">{{ $item['resolved']->confidenceLabel() }}{{ $item['effective'] ? ' · in force ' . $item['effective'] : '' }}{{ $item['resolved']->legalReview ? ' — ' . $item['resolved']->legalReview : '' }}</div>
                                        @foreach (array_slice($item['sources'], 0, 2) as $source)
                                            <div class="text-xs text-gray-500">{{ is_array($source) ? ($source[0] ?? '') : $source }}</div>
                                        @endforeach</td>
                                    <td class="px-2">{{ $item['target']['label'] }}</td>
                                    <td class="px-2">{{ collect($item['periods'])->map(fn ($p) => $p->frequency === 'monthly' ? $p->start->format('M Y') : $p->label())->implode(', ') }}</td>
                                    <td class="px-2">{{ $item['due'] }}</td>
                                    <td class="px-2">
                                        <x-filament::badge size="sm" :color="match ($item['determination']) { 'APPLICABLE' => 'success', 'NOT_APPLICABLE' => 'gray', 'MISSING_CONFIGURATION' => 'danger', default => 'warning' }">{{ \App\Domain\ComplianceReports\Services\ComplianceReports::DETERMINATIONS[$item['determination']] ?? $item['determination'] }}</x-filament::badge>
                                        @if ($item['reason'])<div class="text-xs text-gray-500">{{ $item['reason'] }}</div>@endif
                                    </td>
                                    <td class="px-2 whitespace-nowrap text-right">
                                        @if ($item['applicable'])
                                            {{ ($this->downloadReportAction)(['item' => $item['id']]) }}
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </x-filament::section>
        @empty
            <x-filament::section class="mt-4">
                <p class="text-sm text-gray-500">Nothing falls due: the company has no active establishment in your scope for this selection.</p>
            </x-filament::section>
        @endforelse

        <x-filament::section heading="Kept outside PeopleOS" description="Obligations of the period that are kept on the authority's portal, in physical registers or in other systems. They are listed in every pack's index too." class="mt-4" collapsible collapsed>
            <div class="overflow-x-auto" tabindex="0" role="region" aria-label="Checklist">
                <table class="w-full text-sm">
                    <thead><tr class="text-left text-gray-500"><th class="py-1 px-2">Obligation</th><th class="px-2">Where</th><th class="px-2">Frequency</th><th class="px-2">Form / legal basis</th><th class="px-2">Due</th></tr></thead>
                    <tbody>
                        @forelse ($this->checklist as $definition)
                            <tr class="border-t border-gray-100 dark:border-gray-800 align-top">
                                <td class="py-1 px-2 font-medium">{{ $definition->title }}@if ($definition->notes)<div class="text-xs text-gray-500">{{ implode(' ', $definition->notes) }}</div>@endif</td>
                                <td class="px-2">{{ $this->stateName($definition->state) }}</td>
                                <td class="px-2">{{ $definition->frequencyLabel() }}</td>
                                <td class="px-2 text-gray-600 dark:text-gray-300">{{ $definition->form }}<div class="text-xs text-gray-500">{{ $definition->legalBasis }}</div></td>
                                <td class="px-2">{{ $definition->dueLabel($this->period()) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="py-1 px-2 text-gray-500">Nothing else falls due in this period.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-filament::section>

        <x-filament::section heading="Packs" description="Everything due for a period in one ZIP: a workbook per report and target, an index with each report's status, the configuration gaps, the rule versions used and the checklist. A pack holds what its requester could see, so only they can download it, and only while their access is unchanged." class="mt-4">
            <div class="overflow-x-auto" tabindex="0" role="region" aria-label="Packs">
                <table class="w-full text-sm">
                    <thead><tr class="text-left text-gray-500"><th class="py-1 px-2">Period</th><th class="px-2">Company</th><th class="px-2">Status</th><th class="px-2">Reports</th><th class="px-2">Requested</th><th class="px-2">Review</th><th class="px-2"></th></tr></thead>
                    <tbody>
                        @forelse ($this->packs() as $pack)
                            <tr class="border-t border-gray-100 dark:border-gray-800 align-top">
                                <td class="py-1 px-2">{{ $pack->period()->label() }}</td>
                                <td class="px-2">{{ $pack->company?->name }}</td>
                                <td class="px-2">
                                    <x-filament::badge size="sm" :color="match ($pack->status) { 'completed' => 'success', 'failed' => 'danger', default => 'warning' }">{{ \App\Domain\ComplianceReports\Models\ComplianceReportPack::STATUSES[$pack->status] ?? $pack->status }}</x-filament::badge>
                                    @if ($pack->error)<div class="text-xs text-danger-600">{{ $pack->error }}</div>@endif
                                </td>
                                <td class="px-2">{{ $pack->status === 'completed' ? $pack->report_count . ' with records · ' . $pack->empty_count . ' empty' : '—' }}
                                    @if ($pack->status === 'completed' && ! empty($pack->metadata['readiness']))<div class="text-xs text-gray-500">{{ collect($pack->metadata['readiness'])->map(fn ($n, $r) => $n . ' ' . strtolower($this->readinessLabel($r)))->implode(' · ') }}</div>@endif
                                </td>
                                <td class="px-2">{{ $pack->requester?->name }}<div class="text-xs text-gray-500">{{ $pack->created_at?->format('d M Y H:i') }}{{ ($pack->options['unmasked'] ?? false) ? ' · full identifiers' : '' }}</div></td>
                                <td class="px-2">
                                    @if ($pack->reviewed_at)
                                        Reviewed by {{ $pack->reviewer?->name }}<div class="text-xs text-gray-500">{{ $pack->reviewed_at->format('d M Y') }} · {{ \Illuminate\Support\Str::limit($pack->review_note, 80) }}</div>
                                    @else
                                        <span class="text-xs text-gray-500">Not reviewed</span>
                                    @endif
                                </td>
                                <td class="px-2 whitespace-nowrap text-right">
                                    @if ($this->canDownloadPack($pack)){{ ($this->downloadPackAction)(['pack' => $pack->id]) }}@endif
                                    @if ($this->canRetryPack($pack)){{ ($this->retryPackAction)(['pack' => $pack->id]) }}@endif
                                    @if ($this->canReviewPack($pack)){{ ($this->reviewPackAction)(['pack' => $pack->id]) }}@endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="py-1 px-2 text-gray-500">No packs yet. Use "Generate all (ZIP)" to build every report of a period at once.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-filament::section>

        @php($reviewed = $this->reviewed())
        @if ($reviewed)
            <p class="mt-4 text-xs text-gray-500">Catalogue reviewed {{ $reviewed['on'] ?? '' }}. {{ $reviewed['position'] ?? '' }}</p>
        @endif
    </div>
</x-filament-panels::page>
