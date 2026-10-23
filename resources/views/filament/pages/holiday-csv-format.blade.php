@php
    $code = 'rounded bg-gray-100 px-1.5 py-0.5 font-mono text-[0.8rem] [font-variant-ligatures:none] text-gray-900 dark:bg-white/10 dark:text-gray-100';
    $th = 'px-3 py-2 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400';
    $td = 'px-3 py-2 align-top text-sm text-gray-700 dark:text-gray-200';
@endphp
<div class="space-y-4 text-sm text-gray-700 dark:text-gray-200">
    <section class="space-y-2">
        <h3 class="text-base font-semibold text-gray-950 dark:text-white">CSV format</h3>
        <p>Works for any country. One file holds one country (the one you choose in the form) and one year. The first row is the header; columns may come in any order. Save from Excel as <strong>CSV UTF-8 (comma delimited)</strong> so names in any language keep their characters.</p>
        <div class="overflow-x-auto rounded-lg ring-1 ring-gray-950/5 dark:ring-white/10" tabindex="0" role="region" aria-label="Holiday CSV columns">
            <table class="min-w-full divide-y divide-gray-200 dark:divide-white/5">
                <caption class="sr-only">Holiday CSV columns</caption>
                <thead><tr><th scope="col" class="{{ $th }}">Column</th><th scope="col" class="{{ $th }}">Required</th><th scope="col" class="{{ $th }}">What to enter</th><th scope="col" class="{{ $th }}">Example</th></tr></thead>
                <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                    @foreach ($format as $column => [$required, $what, $example])
                        <tr>
                            <td class="{{ $td }} whitespace-nowrap"><code class="{{ $code }}">{{ $column }}</code></td>
                            <td class="{{ $td }}">{{ $required ? 'Yes' : 'No' }}</td>
                            <td class="{{ $td }}">{{ $what }}</td>
                            <td class="{{ $td }} whitespace-nowrap font-mono text-[0.8rem]">{{ $example }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>

    <section class="space-y-2">
        <h3 class="text-base font-semibold text-gray-950 dark:text-white">Region codes</h3>
        <p>Use a code from the country's region list on <strong>Platform → Regions</strong>: the official ISO 3166-2 codes, pre-filled for every country, plus any the platform adds. The prefix is optional, and the region's name comes from the list. For example:</p>
        <ul class="grid gap-x-6 gap-y-1 sm:grid-cols-2">
            @foreach ($regions as $regionCode => $place)
                <li><code class="{{ $code }}">{{ $regionCode }}</code> {{ $place }}</li>
            @endforeach
        </ul>
    </section>

    <section class="space-y-2">
        <h3 class="text-base font-semibold text-gray-950 dark:text-white">Example file (India)</h3>
        <p>Rows with no region form the national list; rows with <code class="{{ $code }}">MH</code> or <code class="{{ $code }}">KA</code> form those states' lists. Tenants choosing Maharashtra see the national holidays plus Maharashtra's. For another country, replace the rows with its holidays.</p>
        <pre class="overflow-x-auto rounded-lg bg-gray-50 p-3 font-mono text-[0.8rem] leading-7 text-gray-900 ring-1 ring-gray-950/5 [font-variant-ligatures:none] dark:bg-white/5 dark:text-gray-100 dark:ring-white/10" tabindex="0" aria-label="Example holiday CSV">{{ $sample }}</pre>
    </section>

    <ul class="list-disc space-y-1 pl-5">
        <li>Each region in the file replaces that region's list for the year and returns it to draft until you publish it. Regions not in the file are left as they are.</li>
        <li>One invalid row refuses the whole file; every problem is listed by row number.</li>
        <li>A date written as <code class="{{ $code }}">Jan 26, 2027</code> contains a comma, so it goes in quotes; spreadsheets add them when they save a CSV.</li>
    </ul>
</div>
