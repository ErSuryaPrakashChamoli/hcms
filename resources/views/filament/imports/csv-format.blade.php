{{-- The upload format guide every import screen shows, built from the import service so it matches what the upload accepts. --}}
@php
    $code = 'rounded bg-gray-100 px-1.5 py-0.5 font-mono text-[0.8rem] [font-variant-ligatures:none] text-gray-900 dark:bg-white/10 dark:text-gray-100';
    $th = 'px-3 py-2 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400';
    $td = 'px-3 py-2 align-top text-sm text-gray-700 dark:text-gray-200';
    // The example is the file as a spreadsheet shows it: its own header row, not upper-cased, one cell per column.
    $sampleHead = 'whitespace-nowrap px-3 py-2 text-left text-xs font-semibold text-gray-500 dark:text-gray-400';
    $sampleCell = 'whitespace-nowrap px-3 py-1.5 font-mono text-[0.8rem] text-gray-900 dark:text-gray-100';
@endphp
<div class="space-y-4 text-sm text-gray-700 dark:text-gray-200">
    <section class="space-y-2">
        <h3 class="text-base font-semibold text-gray-950 dark:text-white">Columns</h3>
        <p>A CSV file whose first row is the header; columns may come in any order and you confirm the match on the next step. Save from Excel as <strong>CSV UTF-8 (comma delimited)</strong>.</p>
        <div class="overflow-x-auto rounded-lg ring-1 ring-gray-950/5 dark:ring-white/10" tabindex="0" role="region" aria-label="{{ $title }} columns">
            <table class="min-w-full divide-y divide-gray-200 dark:divide-white/5">
                <caption class="sr-only">{{ $title }} columns</caption>
                <thead><tr><th scope="col" class="{{ $th }}">Column</th><th scope="col" class="{{ $th }}">Required</th><th scope="col" class="{{ $th }}">What to enter</th><th scope="col" class="{{ $th }}">Example</th></tr></thead>
                <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                    @foreach ($columns as [$column, $required, $what, $example])
                        <tr>
                            <td class="{{ $td }} whitespace-nowrap"><code class="{{ $code }}">{{ $column }}</code></td>
                            <td class="{{ $td }} whitespace-nowrap">{{ $required }}</td>
                            <td class="{{ $td }}">{{ $what }}</td>
                            <td class="{{ $td }} whitespace-nowrap font-mono text-[0.8rem]">{{ $example }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>

    @if (! empty($codes))
        <section class="space-y-2">
            <h3 class="text-base font-semibold text-gray-950 dark:text-white">{{ $codesTitle ?? 'Day codes' }}</h3>
            <ul class="grid gap-x-6 gap-y-1 sm:grid-cols-2">
                @foreach ($codes as $dayCode => $meaning)
                    <li><code class="{{ $code }}">{{ $dayCode }}</code> {{ $meaning }}</li>
                @endforeach
            </ul>
        </section>
    @endif

    @if (! empty($modes))
        <section class="space-y-2">
            <h3 class="text-base font-semibold text-gray-950 dark:text-white">New or existing</h3>
            <p>When you upload, choose what the file is for. The same file format serves all three.</p>
            <ul class="list-disc space-y-1 pl-5">
                @foreach ($modes as [$label, $description])
                    <li><strong>{{ $label }}:</strong> {{ $description }}</li>
                @endforeach
            </ul>
        </section>
    @endif

    <section class="space-y-2">
        <h3 class="text-base font-semibold text-gray-950 dark:text-white">Example file</h3>
        <p>How the file looks in a spreadsheet, header row first. Download template gives you a file to start from.</p>
        <div class="overflow-x-auto rounded-lg bg-gray-50 ring-1 ring-gray-950/5 dark:bg-white/5 dark:ring-white/10" tabindex="0" role="region" aria-label="Example {{ $title }} file">
            <table class="min-w-full divide-y divide-gray-200 dark:divide-white/5">
                <caption class="sr-only">Example {{ $title }} file</caption>
                <thead><tr>
                    @foreach ($sampleRows[0] ?? [] as $cell)
                        <th scope="col" class="{{ $sampleHead }}">{{ $cell }}</th>
                    @endforeach
                </tr></thead>
                <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                    @foreach (array_slice($sampleRows, 1) as $row)
                        <tr>
                            @foreach ($row as $cell)
                                <td class="{{ $sampleCell }}">{{ $cell }}</td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>

    @if (! empty($notes))
        <ul class="list-disc space-y-1 pl-5">
            @foreach ($notes as $note)
                <li>{{ $note }}</li>
            @endforeach
        </ul>
    @endif
</div>
