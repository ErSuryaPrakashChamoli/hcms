@php
    $code = 'rounded bg-gray-100 px-1.5 py-0.5 font-mono text-[0.8rem] [font-variant-ligatures:none] text-gray-900 dark:bg-white/10 dark:text-gray-100';
    $th = 'px-3 py-2 text-left text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400';
    $td = 'px-3 py-2 align-top text-sm text-gray-700 dark:text-gray-200';
    $tag = \App\Domain\Letters\Support\LetterVariables::tag(...);
@endphp
<div class="space-y-6 text-sm text-gray-700 dark:text-gray-200" x-data="{ q: '', copied: null }">
    <section class="space-y-2">
        <h3 class="text-base font-semibold text-gray-950 dark:text-white">How to use variables</h3>
        <ol class="list-decimal space-y-1.5 pl-5">
            <li><strong>Written here:</strong> in the letter editor, click the <strong>{ }</strong> button (or type <code class="{{ $code }}">&#123;&#123;</code>) and pick a variable. You can also type one, such as <code class="{{ $code }}">{{ $tag('employee.name') }}</code>.</li>
            <li><strong>Your own Word letter:</strong> choose <em>Upload a Word file</em> on the template and, in Word, type the variables where the values should go. Your letterhead, logo, fonts and layout are kept. Start from the <strong>sample Word template</strong> below if you like.</li>
            <li><strong>Salary annexure:</strong> put <code class="{{ $code }}">{{ $tag('salary.annexure') }}</code> on a line of its own for the whole table, monthly and yearly. To build your own table, use one variable per amount, such as <code class="{{ $code }}">{{ $tag('salary.basic.monthly') }}</code> and <code class="{{ $code }}">{{ $tag('salary.basic.annual') }}</code>.</li>
            <li><strong>Signature:</strong> put <code class="{{ $code }}">{{ $tag('signatory.signature') }}</code> where the signature goes, with <code class="{{ $code }}">{{ $tag('signatory.name') }}</code> and <code class="{{ $code }}">{{ $tag('signatory.designation') }}</code> below it. Set up signing authorities under <strong>Letters → Signing authorities</strong>.</li>
            <li>A variable with no value for an employee prints nothing. Any other name, such as <code class="{{ $code }}">{{ $tag('purpose') }}</code>, is filled in by whoever generates the letter.</li>
        </ol>
    </section>

    <div class="sticky top-0 z-10 bg-white py-2 dark:bg-gray-900">
        <input type="search" x-model="q" placeholder="Search variables, e.g. joining, basic, PAN" aria-label="Search variables"
            class="block w-full rounded-lg border-gray-300 text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:border-white/10 dark:bg-white/5 dark:text-white">
    </div>

    @foreach ($groups as $key => $group)
        @php($search = collect($group['variables'])->map(fn (array $about, string $variable) => strtolower($variable.' '.$about[0]))->values()->all())
        <section class="space-y-2" x-data="{ rows: @js($search) }" x-show="!q || rows.some(r => r.includes(q.toLowerCase()))">
            <h3 class="text-base font-semibold text-gray-950 dark:text-white">{{ $group['title'] }}</h3>
            <p class="text-gray-500 dark:text-gray-400">{{ $group['intro'] }}</p>
            <div class="overflow-x-auto rounded-lg ring-1 ring-gray-950/5 dark:ring-white/10">
                <table class="min-w-full divide-y divide-gray-200 dark:divide-white/5">
                    <thead><tr><th class="{{ $th }}">Variable</th><th class="{{ $th }}">What it prints</th><th class="{{ $th }}">Example</th></tr></thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                        @foreach ($group['variables'] as $variable => [$meaning, $example])
                            <tr data-search="{{ strtolower($variable.' '.$meaning) }}" x-show="!q || $el.dataset.search.includes(q.toLowerCase())">
                                <td class="{{ $td }} whitespace-nowrap">
                                    <button type="button" data-tag="{{ $tag($variable) }}" title="Copy"
                                        x-on:click="navigator.clipboard?.writeText($el.dataset.tag); copied = $el.dataset.tag"
                                        class="{{ $code }} cursor-copy hover:ring-1 hover:ring-primary-500">{{ $tag($variable) }}</button>
                                    <span x-show="copied === @js($tag($variable))" x-cloak class="ml-1 text-xs text-success-600 dark:text-success-400">Copied</span>
                                </td>
                                <td class="{{ $td }}">{{ $meaning }}</td>
                                <td class="{{ $td }} text-gray-500 dark:text-gray-400">{{ $example }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endforeach
</div>
