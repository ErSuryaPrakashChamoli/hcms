<x-filament-panels::page>
    {{-- HROne parity XO-6: the employee's advance ledger (HROne's "Advance Outstanding ledger"), read-only. --}}
    @php($summary = $this->summary())
    <div class="grid gap-3 text-sm md:grid-cols-3" data-ledger-summary>
        <div>
            <p class="text-gray-500">Outstanding now</p>
            <p class="text-lg font-semibold" data-outstanding>{{ number_format($summary['outstanding'], 2) }}</p>
        </div>
        <div>
            <p class="text-gray-500">Scheduled through payroll</p>
            <p class="text-lg font-semibold">{{ number_format($summary['scheduled'], 2) }}</p>
        </div>
        <div>
            <p class="text-gray-500">Recovered so far (payroll, final settlement, paid back)</p>
            <p class="text-lg font-semibold">{{ number_format($summary['recovered'], 2) }}</p>
        </div>
    </div>

    @php($schedules = $this->schedules())
    @if ($schedules->isNotEmpty())
        <x-filament::section heading="Recoveries through payroll" description="Scheduled from the Advance recovery desk. While one is open, the policy's automatic recovery of that advance waits.">
            <div class="overflow-x-auto" tabindex="0" role="region" aria-label="Recoveries through payroll">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-gray-500">
                            <th class="py-1 pe-3">Salary month</th>
                            <th class="py-1 pe-3">Advance</th>
                            <th class="py-1 pe-3">Amount</th>
                            <th class="py-1 pe-3">Status</th>
                            <th class="py-1 pe-3">Comments</th>
                            <th class="py-1 pe-3">Scheduled by</th>
                            <th class="py-1 pe-3"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($schedules as $schedule)
                            @php($missed = $this->isMissed($schedule))
                            <tr class="border-t border-gray-200 dark:border-gray-700" data-schedule="{{ $schedule->id }}">
                                <td class="py-1 pe-3">{{ $schedule->monthLabel() }}</td>
                                <td class="py-1 pe-3">{{ $schedule->advance?->number }}</td>
                                <td class="py-1 pe-3">{{ number_format((float) $schedule->amount, 2) }}@if ($schedule->status === 'recovered' && $schedule->recovered_amount !== null) <span class="text-xs text-gray-500">(recovered {{ number_format((float) $schedule->recovered_amount, 2) }} on {{ $schedule->recovered_on?->format('d M Y') }})</span>@endif</td>
                                <td class="py-1 pe-3">
                                    @if ($missed)
                                        <span class="font-medium text-danger-600">{{ $schedule->monthLabel() }} finalized without it: change or cancel it</span>
                                    @else
                                        {{ \App\Domain\Expense\Models\ExpenseAdvanceRecoverySchedule::STATUSES[$schedule->status] ?? $schedule->status }}@if (filled($schedule->cancel_reason)) <span class="text-xs text-gray-500">· {{ $schedule->cancel_reason }}</span>@endif
                                    @endif
                                </td>
                                <td class="py-1 pe-3">{{ $schedule->note ?? '—' }}</td>
                                <td class="py-1 pe-3">{{ $schedule->scheduler?->name ?? '—' }}</td>
                                <td class="py-1 pe-3">
                                    @if ($schedule->status === 'scheduled')
                                        <div class="flex flex-wrap gap-2">
                                            {{ ($this->rescheduleAction)(['schedule' => $schedule->id]) }}
                                            {{ ($this->cancelScheduleAction)(['schedule' => $schedule->id]) }}
                                        </div>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-filament::section>
    @endif

    <x-filament::section heading="Ledger" description="Opening balance, the movement (dispensed +, settled, refunded, written off or recovered −) and the closing balance.">
        @php($rows = $this->ledgerRows())
        @if ($rows === [])
            <p class="text-sm text-gray-500">No advance has been dispensed to this employee.</p>
        @else
            <div class="overflow-x-auto" tabindex="0" role="region" aria-label="Advance ledger">
                <table class="w-full text-sm" data-ledger>
                    <thead>
                        <tr class="text-left text-gray-500">
                            <th class="py-1 pe-3">Date</th>
                            <th class="py-1 pe-3">Advance</th>
                            <th class="py-1 pe-3">Reason</th>
                            <th class="py-1 pe-3">Reference</th>
                            <th class="py-1 pe-3 text-right">Opening outstanding</th>
                            <th class="py-1 pe-3 text-right">Credit / debit</th>
                            <th class="py-1 pe-3 text-right">Closing outstanding</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $row)
                            @php($url = $this->advanceUrl($row['advance_id']))
                            <tr class="border-t border-gray-200 dark:border-gray-700" data-ledger-row="{{ $loop->iteration }}">
                                <td class="py-1 pe-3">{{ \Carbon\CarbonImmutable::parse($row['date'])->format('d M Y') }}</td>
                                <td class="py-1 pe-3">@if ($url)<a class="text-primary-600 underline" href="{{ $url }}">{{ $row['advance'] }}</a>@else{{ $row['advance'] }}@endif</td>
                                <td class="py-1 pe-3">{{ $row['kind'] }}@if (filled($row['reason'])) <span class="text-xs text-gray-500">· {{ $row['reason'] }}</span>@endif</td>
                                <td class="py-1 pe-3">{{ $row['reference'] ?? '—' }}</td>
                                <td class="py-1 pe-3 text-right">{{ number_format($row['opening'], 2) }}</td>
                                <td class="py-1 pe-3 text-right">{{ ($row['change'] > 0 ? '+' : '').number_format($row['change'], 2) }}</td>
                                <td class="py-1 pe-3 text-right font-medium">{{ number_format($row['closing'], 2) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>
</x-filament-panels::page>
