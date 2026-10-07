<x-filament-panels::page>
    @php($approval = $this->selected())
    @if ($approval)
        <x-filament::section heading="{{ $approval->action->label() }} · {{ $approval->reference }}">
            <dl class="grid grid-cols-1 gap-3 text-sm sm:grid-cols-2 lg:grid-cols-4">
                <div><dt class="text-gray-500 dark:text-gray-400">Status</dt><dd><x-filament::badge size="sm" :color="$approval->status->color()">{{ $approval->status->value }}</x-filament::badge>{{ $approval->executed_at ? ' · carried out '.$approval->executed_at->toDateTimeString() : '' }}</dd></div>
                <div><dt class="text-gray-500 dark:text-gray-400">Requested by (maker)</dt><dd>{{ $this->userName($approval->maker_id) }} · {{ $approval->requested_at->toDateTimeString() }}</dd></div>
                <div><dt class="text-gray-500 dark:text-gray-400">Decided by (checker)</dt><dd>{{ $this->userName($approval->checker_id) }}{{ $approval->decided_at ? ' · '.$approval->decided_at->toDateTimeString() : '' }}</dd></div>
                <div><dt class="text-gray-500 dark:text-gray-400">Correlation key</dt><dd><code class="break-all">{{ $approval->correlation_key }}</code></dd></div>
                <div class="sm:col-span-2"><dt class="text-gray-500 dark:text-gray-400">Maker's reason</dt><dd>{{ $approval->maker_reason }}</dd></div>
                <div class="sm:col-span-2"><dt class="text-gray-500 dark:text-gray-400">Checker's reason</dt><dd>{{ $approval->checker_reason ?? '—' }}</dd></div>
                @if ($approval->result)<div class="sm:col-span-2 lg:col-span-4"><dt class="text-gray-500 dark:text-gray-400">Result</dt><dd class="font-medium">{{ $approval->result }}</dd></div>@endif
            </dl>
            @if ($approval->status === \App\Domain\Billing\Enums\ApprovalStatus::Pending && $this->isMine($approval))
                <p class="mt-3 rounded-lg border border-warning-300 p-3 text-sm dark:border-warning-700" role="status">You requested this: another operator must approve it.</p>
            @endif
            <div class="mt-4 grid gap-4 lg:grid-cols-3 text-sm">
                @foreach (['What it does' => $approval->payload, 'Before' => $approval->before, 'After' => $approval->after] as $heading => $values)
                    <div>
                        <h3 class="mb-1 font-medium">{{ $heading }}</h3>
                        <dl class="space-y-0.5">
                            @foreach ((array) $values as $key => $value)
                                <div><dt class="inline text-gray-500 dark:text-gray-400">{{ str_replace('_', ' ', $key) }}:</dt> <dd class="inline">{{ is_array($value) ? json_encode($value) : (is_bool($value) ? ($value ? 'yes' : 'no') : $value) }}</dd></div>
                            @endforeach
                        </dl>
                    </div>
                @endforeach
            </div>
        </x-filament::section>
    @endif

    <x-filament::section heading="Requests" description="Maker-checker for price publication, credit notes (a full one cancels the invoice), refunds, invoice write-offs and payment exceptions. No amount thresholds: every request needs a second operator.">
        <div class="mb-3 flex flex-wrap items-end gap-4 text-sm">
            <label class="flex flex-col gap-1">
                <span class="text-gray-500 dark:text-gray-400">Status</span>
                <select wire:model.live="status" class="fi-input rounded-lg border-gray-300 dark:border-gray-700 dark:bg-gray-900">
                    <option value="">Any</option>
                    @foreach (\App\Domain\Billing\Enums\ApprovalStatus::cases() as $case)<option value="{{ $case->value }}">{{ $case->value }}</option>@endforeach
                </select>
            </label>
        </div>
        <div class="overflow-x-auto" tabindex="0" role="region" aria-label="Approval requests">
        <table class="w-full text-sm">
            <thead><tr class="text-left text-gray-500 dark:text-gray-400"><th scope="col" class="py-1 pe-4">Request</th><th scope="col" class="pe-4">Operation</th><th scope="col" class="pe-4">Concerning</th><th scope="col" class="pe-4">Maker</th><th scope="col">Status</th></tr></thead>
            <tbody>
                @forelse ($this->approvals() as $row)
                    <tr class="border-t border-gray-100 dark:border-gray-800">
                        <td class="py-1 pe-4"><a class="text-primary-600 underline dark:text-primary-400" href="{{ \App\Filament\Pages\PlatformApprovalsPage::getUrl(['approval' => $row->reference, 'status' => $this->status]) }}">{{ $row->requested_at->toDateString() }} · {{ substr($row->reference, -8) }}</a></td>
                        <td class="pe-4">{{ $row->action->label() }}</td>
                        <td class="pe-4">{{ $row->payload['invoice_number'] ?? $row->payload['credit_note_number'] ?? $row->payload['price'] ?? $row->subject_type }}{{ isset($row->payload['tenant']) ? ' · '.$row->payload['tenant'] : '' }}</td>
                        <td class="pe-4">{{ $this->userName($row->maker_id) }}</td>
                        <td><x-filament::badge size="sm" :color="$row->status->color()">{{ $row->status->value }}</x-filament::badge></td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="py-2 text-gray-500 dark:text-gray-400">No request.</td></tr>
                @endforelse
            </tbody>
        </table>
        </div>
    </x-filament::section>
</x-filament-panels::page>
