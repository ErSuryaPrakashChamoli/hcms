<x-filament-panels::page>
    @php($rows = $this->directory()->overview())
    @php($tenant = $this->selectedTenant())
    @if (! $this->deletionEnabled())
        <div class="rounded-lg border border-warning-300 bg-warning-50 p-3 text-sm text-warning-800 dark:border-warning-700 dark:bg-warning-950 dark:text-warning-200" role="status">
            Tenant deletion is <strong>switched off</strong> in this environment (PEOPLEOS_TENANT_DELETION_ENABLED). Offboarding, exports, legal holds and retention work; deletion plans can be reviewed but not recorded, approved or executed.
        </div>
    @endif

    <x-filament::section heading="Tenants">
        <div class="overflow-x-auto" tabindex="0" role="region" aria-label="Tenants and their exit state">
            <table class="w-full text-sm">
                <caption class="sr-only">Tenants and their exit state</caption>
                <thead><tr class="text-left text-gray-500"><th scope="col" class="py-1 pe-4">Tenant</th><th scope="col" class="pe-4">Status</th><th scope="col" class="pe-4">Offboarding</th><th scope="col" class="pe-4">Latest export</th><th scope="col" class="pe-4">Legal holds</th><th scope="col">Latest deletion</th></tr></thead>
                <tbody>
                    @foreach ($rows as $row)
                        <tr @class(['border-t border-gray-100 dark:border-gray-800', 'bg-primary-50 dark:bg-white/5' => $tenant && $tenant->id === $row['tenant']->id])>
                            <td class="py-1 pe-4"><button type="button" class="text-start text-primary-600 underline dark:text-primary-400" wire:click="$set('tenant', {{ $row['tenant']->id }})">{{ $row['tenant']->name }}</button></td>
                            <td class="pe-4"><x-filament::badge size="sm" :color="$row['tenant']->status->getColor()">{{ $row['tenant']->status->getLabel() }}</x-filament::badge></td>
                            <td class="pe-4">{{ $row['case'] ? ucfirst($row['case']->status->value) : '—' }}</td>
                            <td class="pe-4">{{ $row['export'] ? \App\Domain\Offboarding\Services\OffboardingDirectory::exportLabel($row['export']) : '—' }}</td>
                            <td class="pe-4">{{ $row['holds'] ?: '—' }}</td>
                            <td>{{ $row['deletion'] ? \App\Domain\Offboarding\Services\OffboardingDirectory::deletionLabel($row['deletion']) : '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </x-filament::section>

    @if ($tenant)
        @php($case = $this->openCase() ?? $this->lastCase())
        @php($plan = $this->plan())
        <x-filament::section :heading="$tenant->name.' — '.$tenant->status->getLabel()">
            @if ($case)
                <dl class="grid gap-x-4 gap-y-1 text-sm sm:grid-cols-[14rem_1fr]">
                    <dt class="text-gray-500">Offboarding</dt><dd>{{ $case->reference }} · {{ ucfirst($case->status->value) }}</dd>
                    <dt class="text-gray-500">Requested</dt><dd>{{ $case->requested_at?->format('Y-m-d H:i') }} — {{ $case->reason }}</dd>
                    <dt class="text-gray-500">Customer reference</dt><dd>{{ $case->customer_reference ?? '—' }}</dd>
                    <dt class="text-gray-500">End of service</dt><dd>{{ $case->service_end_date?->toDateString() ?? '—' }}</dd>
                    <dt class="text-gray-500">Closed</dt><dd>{{ $case->closed_at ? $case->closed_at->format('Y-m-d H:i').' (was '.$case->closed_from_status.')' : '—' }}</dd>
                    <dt class="text-gray-500">Export waived</dt><dd>{{ $case->export_waived_at ? $case->export_waived_at->format('Y-m-d').': '.$case->export_waiver_reason : '—' }}</dd>
                    <dt class="text-gray-500">Deletion started</dt><dd>{{ $case->deletion_started_at?->format('Y-m-d H:i') ?? 'No (the tenant can be reopened while no deletion is planned)' }}</dd>
                </dl>
            @else
                <p class="text-sm text-gray-600 dark:text-gray-300">No offboarding. Start one when the customer gives notice.</p>
            @endif
            @if ($case?->status === \App\Domain\Offboarding\Enums\OffboardingStatus::Requested && ($blockers = $this->closureBlockers()) !== [])
                <div class="mt-3 text-sm" role="note"><strong>Before closing:</strong>
                    <ul class="ms-5 list-disc">@foreach ($blockers as $b)<li>{{ $b['detail'] }}</li>@endforeach</ul>
                </div>
            @endif
        </x-filament::section>

        @if ($plan)
            <x-filament::section heading="Retention and deletion eligibility" :description="'Evaluated '.$plan['evaluated_on'].($plan['closed_at'] ? ', retention counted from the closure on '.\Illuminate\Support\Carbon::parse($plan['closed_at'])->toDateString() : '')">
                @if ($plan['gates'] !== [])
                    <div class="mb-3 text-sm" role="note"><strong>Not deletable:</strong>
                        <ul class="ms-5 list-disc">@foreach ($plan['gates'] as $g)<li><code>{{ $g['code'] }}</code> {{ $g['detail'] }}</li>@endforeach</ul>
                    </div>
                @endif
                <div class="overflow-x-auto" tabindex="0" role="region" aria-label="Data categories, their retention and what a deletion would do">
                    <table class="w-full text-sm">
                        <caption class="sr-only">Data categories, their retention and what a deletion would do</caption>
                        <thead><tr class="text-left text-gray-500"><th scope="col" class="py-1 pe-3">Category</th><th scope="col" class="pe-3">A deletion would</th><th scope="col" class="pe-3">Why</th><th scope="col" class="pe-3">Kept until</th><th scope="col" class="pe-3">Legal minimum</th><th scope="col" class="pe-3">Rows</th><th scope="col">Files</th></tr></thead>
                        <tbody>
                            @foreach ($plan['categories'] as $key => $c)
                                <tr class="border-t border-gray-100 align-top dark:border-gray-800">
                                    <td class="py-1 pe-3"><span class="font-medium">{{ $key }}</span><br><span class="text-xs text-gray-500">{{ $c['owner'] === 'markedge' ? 'Markedge\'s records' : ($c['owner'] === 'platform' ? 'platform' : 'customer data') }}</span></td>
                                    <td class="pe-3"><x-filament::badge size="sm" :color="match ($c['action']) { 'delete' => 'danger', 'anonymise' => 'warning', 'none' => 'gray', default => 'success' }">{{ match ($c['action']) { 'delete' => 'Delete', 'anonymise' => 'Anonymise', 'none' => 'Nothing to do', default => 'Keep' } }}</x-filament::badge></td>
                                    <td class="pe-3"><code class="text-xs">{{ $c['reason'] }}</code><br><span class="text-xs">{{ $c['detail'] }}@if ($c['protected_by'] !== []): {{ implode(', ', $c['protected_by']) }}@endif</span></td>
                                    <td class="whitespace-nowrap pe-3">{{ $c['retention']['until'] ?? '—' }}</td>
                                    <td class="pe-3 text-xs [overflow-wrap:anywhere]">{{ $c['retention']['legal_minimum'] ?? '—' }}</td>
                                    <td class="pe-3">{{ array_sum($c['tables']) }}</td>
                                    <td>{{ $c['files'] + $c['stored_files'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </x-filament::section>
        @endif

        <x-filament::section heading="Exports">
            @php($exports = $this->directory()->exports($tenant->id))
            @if ($this->revealed)
                <div class="mb-3 rounded-lg border border-primary-300 p-3 text-sm dark:border-primary-700" role="status">
                    Package passphrase (recorded in the audit trail; share it with the customer separately from the file): <code class="select-all break-all text-base">{{ $this->revealed }}</code>
                </div>
            @endif
            @if ($exports->isEmpty())
                <p class="text-sm text-gray-600 dark:text-gray-300">No export yet.</p>
            @else
                <div class="overflow-x-auto" tabindex="0" role="region" aria-label="Export packages">
                    <table class="w-full text-sm">
                        <caption class="sr-only">Export packages</caption>
                        <thead><tr class="text-left text-gray-500"><th scope="col" class="py-1 pe-3">Reference</th><th scope="col" class="pe-3">Status</th><th scope="col" class="pe-3">Records / files</th><th scope="col" class="pe-3">SHA-256</th><th scope="col" class="pe-3">Expires</th><th scope="col" class="pe-3">Delivered</th><th scope="col">Actions</th></tr></thead>
                        <tbody>
                            @foreach ($exports as $export)
                                <tr class="border-t border-gray-100 align-top dark:border-gray-800">
                                    <td class="py-1 pe-3 font-mono text-xs">{{ $export->reference }}<br><span class="font-sans text-gray-500">{{ $export->purpose }}</span></td>
                                    <td class="pe-3">{{ \App\Domain\Offboarding\Services\OffboardingDirectory::exportLabel($export) }}@if ($export->failure)<br><span class="text-xs text-danger-600 dark:text-danger-400">{{ $export->failure }}</span>@endif</td>
                                    <td class="pe-3">{{ $export->record_count ?? '—' }} / {{ $export->file_count ?? '—' }}</td>
                                    <td class="pe-3 font-mono text-xs">{{ $export->sha256 ? substr($export->sha256, 0, 16).'…' : '—' }}</td>
                                    <td class="pe-3">{{ $export->expires_at?->format('Y-m-d') ?? '—' }}</td>
                                    <td class="pe-3">{{ $export->delivered_at ? $export->delivered_at->format('Y-m-d').' ('.$export->delivery_note.')' : 'No' }}</td>
                                    <td class="space-x-1 whitespace-nowrap">
                                        @if ($this->exportReady($export)){{ ($this->exportDownloadAction)(['export' => $export->id]) }} {{ ($this->exportPassphraseAction)(['export' => $export->id]) }}@endif
                                        @if (! $export->delivered_at && in_array($export->status->value, ['ready', 'expired'], true)){{ ($this->exportDeliveredAction)(['export' => $export->id]) }}@endif
                                        @if ($this->exportFailed($export)){{ ($this->exportRetryAction)(['export' => $export->id]) }}@endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-filament::section>

        <x-filament::section heading="Legal holds">
            @php($holds = $this->directory()->holds($tenant->id))
            @if ($holds->isEmpty())
                <p class="text-sm text-gray-600 dark:text-gray-300">No legal hold.</p>
            @else
                <div class="overflow-x-auto" tabindex="0" role="region" aria-label="Legal holds">
                    <table class="w-full text-sm">
                        <caption class="sr-only">Legal holds</caption>
                        <thead><tr class="text-left text-gray-500"><th scope="col" class="py-1 pe-3">Hold</th><th scope="col" class="pe-3">Status</th><th scope="col" class="pe-3">Covers</th><th scope="col" class="pe-3">Reason</th><th scope="col">Actions</th></tr></thead>
                        <tbody>
                            @foreach ($holds as $hold)
                                <tr class="border-t border-gray-100 align-top dark:border-gray-800">
                                    <td class="py-1 pe-3 font-mono text-xs">{{ $hold->reference }}<br><span class="font-sans text-gray-500">{{ $hold->matter_reference }}</span></td>
                                    <td class="pe-3">{{ ucfirst(str_replace('_', ' ', $hold->status->value)) }}</td>
                                    <td class="pe-3">{{ $hold->scope === 'tenant' ? 'Whole tenant' : implode(', ', (array) $hold->categories) }}</td>
                                    <td class="pe-3">{{ $hold->reason }}@if ($hold->release_reason)<br><span class="text-xs text-gray-500">Release asked: {{ $hold->release_reason }}</span>@endif</td>
                                    <td class="space-x-1 whitespace-nowrap">
                                        @if ($hold->status->value === 'active'){{ ($this->holdReleaseRequestAction)(['hold' => $hold->id]) }}@endif
                                        @if ($this->canApproveRelease($hold)){{ ($this->holdReleaseApproveAction)(['hold' => $hold->id]) }} {{ ($this->holdReleaseRejectAction)(['hold' => $hold->id]) }}@endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-filament::section>

        <x-filament::section heading="Deletions">
            @php($deletions = $this->directory()->deletions($tenant->id))
            @if ($deletions->isEmpty())
                <p class="text-sm text-gray-600 dark:text-gray-300">No deletion.</p>
            @endif
            @foreach ($deletions as $deletion)
                <div class="mb-4 border-t border-gray-100 pt-3 text-sm dark:border-gray-800">
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="font-mono text-xs">{{ $deletion->reference }}</span>
                        <x-filament::badge size="sm" :color="match ($deletion->status->value) { 'completed' => 'success', 'failed', 'rejected' => 'danger', 'cancelled' => 'gray', default => 'warning' }">{{ \App\Domain\Offboarding\Services\OffboardingDirectory::deletionLabel($deletion) }}</x-filament::badge>
                        <span class="text-gray-500">plan {{ substr($deletion->plan_sha256, 0, 12) }}… · {{ implode(', ', $deletion->plan['eligible'] ?? []) }}</span>
                        @if ($this->canApprove($deletion)){{ ($this->deletionApproveAction)(['deletion' => $deletion->id]) }} {{ ($this->deletionRejectAction)(['deletion' => $deletion->id]) }}@endif
                        @if (in_array($deletion->status->value, ['planned', 'approved'], true)){{ ($this->deletionCancelAction)(['deletion' => $deletion->id]) }}@endif
                        @if ($deletion->status->value === 'failed'){{ ($this->deletionRetryAction)(['deletion' => $deletion->id]) }}@endif
                        @if ($deletion->certificate){{ ($this->certificateDownloadAction)(['deletion' => $deletion->id]) }}@endif
                    </div>
                    <div class="mt-1 text-gray-600 dark:text-gray-300">Planned by #{{ $deletion->requested_by }} {{ $deletion->requested_at?->format('Y-m-d H:i') }}: {{ $deletion->request_reason }}@if ($deletion->approved_by) · approved by #{{ $deletion->approved_by }} {{ $deletion->approved_at?->format('Y-m-d H:i') }}@endif @if ($deletion->failure)<br><span class="text-danger-600 dark:text-danger-400">{{ $deletion->failure }}</span>@endif</div>
                    @php($steps = $this->directory()->steps($deletion->id))
                    @if ($steps->isNotEmpty())
                        <details class="mt-2"><summary class="cursor-pointer">Steps ({{ $steps->where('status', 'done')->count() }} of {{ $steps->where('kind', '!=', 'external')->count() }} done) and external dependencies</summary>
                            <div class="overflow-x-auto" tabindex="0" role="region" aria-label="Deletion steps">
                                <table class="mt-2 w-full text-xs">
                                    <caption class="sr-only">Deletion steps</caption>
                                    <thead><tr class="text-left text-gray-500"><th scope="col" class="pe-2">#</th><th scope="col" class="pe-2">Category</th><th scope="col" class="pe-2">Step</th><th scope="col" class="pe-2">Target</th><th scope="col" class="pe-2">Status</th><th scope="col" class="pe-2">Done</th><th scope="col">Note</th></tr></thead>
                                    <tbody>
                                        @foreach ($steps as $step)
                                            <tr class="border-t border-gray-100 align-top dark:border-gray-800">
                                                <td class="pe-2">{{ $step->position }}</td><td class="pe-2">{{ $step->category }}</td><td class="pe-2">{{ $step->kind }}</td><td class="pe-2 font-mono">{{ $step->target }}</td>
                                                <td class="pe-2">{{ str_replace('_', ' ', $step->status) }}</td><td class="pe-2">{{ $step->processed_count }}{{ $step->planned_count !== null ? ' / '.$step->planned_count : '' }}</td>
                                                <td>{{ $step->detail['note'] ?? ($step->detail['reason'] ?? '') }}@if ($step->kind === 'external' && $deletion->status->value !== 'cancelled') {{ ($this->externalRecordAction)(['step' => $step->id]) }}@endif</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        </details>
                    @endif
                </div>
            @endforeach
        </x-filament::section>
    @endif
</x-filament-panels::page>
