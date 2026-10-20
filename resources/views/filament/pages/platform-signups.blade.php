<x-filament-panels::page>
    @php($counts = $this->counts())
    @php($selected = $this->selected())

    <x-filament::section heading="Can signup work today?" description="Configured on Commercial policies (offers, terms, privacy notice, review) and by the environment switch. Nothing here changes them.">
        <div class="overflow-x-auto" tabindex="0" role="region" aria-label="Signup readiness">
            <table class="w-full text-sm">
                <caption class="sr-only">Signup readiness</caption>
                <thead><tr class="text-left text-gray-500"><th scope="col" class="py-1 pe-4">Item</th><th scope="col" class="pe-4">State</th><th scope="col">Detail</th></tr></thead>
                <tbody>
                    @foreach ($this->readiness() as $row)
                        <tr class="border-t border-gray-100 align-top dark:border-gray-800">
                            <td class="py-1 pe-4">{{ $row['item'] }}</td>
                            <td class="pe-4"><x-filament::badge size="sm" :color="match ($row['state']) { 'configured', 'available', 'on' => 'success', 'off' => 'gray', default => 'warning' }">{{ $row['state'] }}</x-filament::badge></td>
                            <td class="[overflow-wrap:anywhere]">{{ $row['detail'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </x-filament::section>

    <x-filament::section heading="Applications">
        <div class="mb-3 flex flex-wrap gap-2 text-sm" role="group" aria-label="Filter by state">
            <x-filament::button size="xs" :color="$this->status === null ? 'primary' : 'gray'" wire:click="$set('status', null)">All ({{ array_sum($counts) }})</x-filament::button>
            @foreach (\App\Domain\Signup\Enums\SignupStatus::cases() as $s)
                @if (($counts[$s->value] ?? 0) > 0)
                    <x-filament::button size="xs" :color="$this->status === $s->value ? 'primary' : 'gray'" wire:click="$set('status', '{{ $s->value }}')">{{ $s->getLabel() }} ({{ $counts[$s->value] }})</x-filament::button>
                @endif
            @endforeach
        </div>
        @if ($this->rows()->isEmpty())
            <p class="text-sm text-gray-600 dark:text-gray-300">No applications.</p>
        @else
            <div class="overflow-x-auto" tabindex="0" role="region" aria-label="Signup applications">
                <table class="w-full text-sm">
                    <caption class="sr-only">Signup applications, newest first</caption>
                    <thead><tr class="text-left text-gray-500"><th scope="col" class="py-1 pe-4">Reference</th><th scope="col" class="pe-4">Organisation</th><th scope="col" class="pe-4">Country · plan</th><th scope="col" class="pe-4">State</th><th scope="col" class="pe-4">Review</th><th scope="col">Received</th></tr></thead>
                    <tbody>
                        @foreach ($this->rows() as $row)
                            <tr @class(['border-t border-gray-100 align-top dark:border-gray-800', 'bg-primary-50 dark:bg-white/5' => $selected && $selected->id === $row->id])>
                                <td class="py-1 pe-4"><button type="button" class="font-mono text-primary-600 underline dark:text-primary-400" wire:click="$set('application', {{ $row->id }})">{{ $row->reference }}</button></td>
                                <td class="pe-4">{{ $row->display_name ?? $row->company_name }}</td>
                                <td class="pe-4">{{ $row->country_code }} · {{ $row->plan_code }}</td>
                                <td class="pe-4"><x-filament::badge size="sm" :color="$row->status->getColor()">{{ $row->status->getLabel() }}</x-filament::badge>@if ($row->failure_code)<br><code class="text-xs">{{ $row->failure_code }}</code>@endif</td>
                                <td class="pe-4 text-xs">{{ implode(', ', $row->reviewReasons()) ?: '—' }}</td>
                                <td class="whitespace-nowrap">{{ $row->created_at?->format('Y-m-d H:i') }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>

    @if ($selected)
        @php($eligibility = $this->eligibility($selected))
        <x-filament::section :heading="'Application '.$selected->reference" :description="\App\Filament\Signup\SignupApplicationPage::explain($selected)">
            <dl class="grid gap-x-4 gap-y-1 text-sm sm:grid-cols-[14rem_1fr]">
                <dt class="text-gray-500">State</dt><dd><x-filament::badge size="sm" :color="$selected->status->getColor()">{{ $selected->status->getLabel() }}</x-filament::badge></dd>
                <dt class="text-gray-500">Contact</dt><dd>{{ $selected->contact_name ?? 'removed at activation' }}@if ($selected->email) · {{ $selected->email }}@endif</dd>
                <dt class="text-gray-500">Organisation</dt><dd>{{ $selected->legal_name ?? $selected->company_name }}@if ($selected->display_name && $selected->display_name !== $selected->legal_name) (shown as {{ $selected->display_name }})@endif</dd>
                <dt class="text-gray-500">Country · time zone</dt><dd>{{ $selected->country_code }}@if ($selected->timezone) · {{ $selected->timezone }}@endif</dd>
                <dt class="text-gray-500">Plan</dt><dd>{{ $selected->planVersion?->label() ?? $selected->plan_code }}@if ($selected->trial_days !== null) · {{ $selected->trial_days > 0 ? $selected->trial_days.'-day trial' : 'no self-service trial' }}@endif</dd>
                <dt class="text-gray-500">Employees planned</dt><dd>{{ $selected->intended_employees !== null ? number_format($selected->intended_employees) : '—' }}</dd>
                <dt class="text-gray-500">Accepted</dt><dd>@if ($selected->acknowledged_at) Terms {{ $selected->terms_version }}, Privacy Notice {{ $selected->privacy_version }}, authority confirmed, {{ $selected->acknowledged_at->format('Y-m-d H:i') }} UTC @else — @endif</dd>
                <dt class="text-gray-500">Review reasons</dt>
                <dd>@forelse ($selected->reviewReasons() as $code)<div><code class="text-xs">{{ $code }}</code> {{ \App\Domain\Signup\Services\SignupEligibility::REVIEW_LABELS[$code] ?? '' }}</div>@empty — @endforelse</dd>
                <dt class="text-gray-500">Decision</dt><dd>@if ($selected->decided_at){{ $selected->decider?->name ?? 'system' }}, {{ $selected->decided_at->format('Y-m-d H:i') }}: {{ $selected->decision_reason }}@else — @endif</dd>
                <dt class="text-gray-500">Set-up attempts</dt><dd>{{ $selected->attempts }}@if ($selected->failure_code) · last failure <code class="text-xs">{{ $selected->failure_code }}</code>: {{ $selected->failure_summary }}@endif @if ($selected->next_attempt_at) · next automatic attempt {{ $selected->next_attempt_at->format('Y-m-d H:i') }}@endif</dd>
                <dt class="text-gray-500">Tenant</dt><dd>@if ($selected->provisionedTenant){{ $selected->provisionedTenant->name }} ({{ $selected->provisionedTenant->slug }}, {{ $selected->provisionedTenant->status->getLabel() }})@else none @endif</dd>
                <dt class="text-gray-500">Activation e-mail</dt><dd>@if ($selected->invitation_sent_at) accepted by the mail transport {{ $selected->invitation_sent_at->format('Y-m-d H:i') }} (not proof of delivery)@elseif ($selected->provisioned_tenant_id) not sent ({{ $selected->invitation_failures }} refused) @else — @endif</dd>
                @if ($eligibility)
                    <dt class="text-gray-500">Eligibility now</dt><dd @class(['text-danger-700 dark:text-danger-400' => ! $eligibility['ok']])>@if ($eligibility['code'])<code class="text-xs">{{ $eligibility['code'] }}</code> @endif{{ $eligibility['detail'] }}</dd>
                @endif
            </dl>
            <div class="mt-4 flex flex-wrap gap-2">
                @if ($selected->status === \App\Domain\Signup\Enums\SignupStatus::ReviewPending){{ $this->approveAction }} {{ $this->rejectAction }}@endif
                @if ($selected->status === \App\Domain\Signup\Enums\SignupStatus::FailedRetryable){{ $this->retryAction }} {{ $this->holdAction }}@endif
                @if ($selected->status === \App\Domain\Signup\Enums\SignupStatus::FailedManualReview){{ $this->retryAction }} {{ $this->rejectAction }}@endif
                @if ($selected->status === \App\Domain\Signup\Enums\SignupStatus::ActivationPending){{ $this->resendAction }}@endif
                @if ($selected->status->cancellable()){{ $this->cancelAction }}@endif
            </div>
        </x-filament::section>

        <x-filament::section heading="Audit trail" description="Platform chain, newest first. Applicant steps are recorded without an IP address.">
            <div class="overflow-x-auto" tabindex="0" role="region" aria-label="Audit trail of the application">
                <table class="w-full text-sm">
                    <caption class="sr-only">Audit trail of the application</caption>
                    <thead><tr class="text-left text-gray-500"><th scope="col" class="py-1 pe-4">When</th><th scope="col" class="pe-4">Event</th><th scope="col" class="pe-4">By</th><th scope="col">Detail</th></tr></thead>
                    <tbody>
                        @foreach ($this->timeline($selected) as $event)
                            <tr class="border-t border-gray-100 align-top dark:border-gray-800">
                                <td class="whitespace-nowrap py-1 pe-4">{{ $event->created_at?->format('Y-m-d H:i:s') }}</td>
                                <td class="pe-4"><code class="text-xs">{{ $event->action->value }}</code></td>
                                <td class="pe-4">{{ $event->actor?->name ?? 'applicant or system' }}</td>
                                <td class="text-xs [overflow-wrap:anywhere]">{{ collect($event->metadata)->only(['from', 'to', 'kind', 'code', 'attempt', 'review_reasons'])->filter()->map(fn ($v, $k) => $k.': '.(is_array($v) ? implode(', ', $v) : $v))->implode(' · ') }}@if ($event->reason) — {{ $event->reason }}@endif</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-filament::section>
    @endif

</x-filament-panels::page>
