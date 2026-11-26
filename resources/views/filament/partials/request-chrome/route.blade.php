{{--
    HROne parity RQ-2: "Who approves this" (a read-only preview: nothing is started or sent until the form is submitted),
    as one outcome card (App\Filament\Support\Review\Outcome; owner 2026-11-26: "beautify and arrange it … across the
    entire HCM"): who decides it in one sentence (the manager who approves under it), each workflow that would or may
    start as a box with one line per level, then who is informed and the policy to read as ruled rows.
--}}
@php
    /** @var array $preview */
    $modes = ['single' => null, 'sequential' => 'one after another', 'parallel' => 'all of them', 'any' => 'any one of them'];
    $definite = count(array_filter($preview['routes'], fn (array $route) => ! $route['conditional']));
    $maybe = count($preview['routes']) - $definite;
    $outcome = \App\Filament\Support\Review\Outcome::make('Approval route')->icon('heroicon-o-arrows-right-left')->data('request-route');

    if ($definite > 0) {
        $outcome->headline($definite === 1 ? 'An approval workflow decides it.' : $definite.' approval workflows decide it.');
    } elseif (filled($preview['built_in'])) {
        // The module's own approvers; "otherwise" when a workflow below may take it instead.
        $outcome->headline(rtrim((string) $preview['built_in'], '.').'.', implode(' ', array_filter([
            $preview['manager'] ? 'Your manager, '.$preview['manager'].', approves these requests.' : null,
            $maybe > 0 ? 'Unless '.($maybe === 1 ? 'the workflow' : 'a workflow').' below takes it.' : null,
        ])));
    }

    foreach ($preview['routes'] as $route) {
        $levels = [];
        foreach ($route['steps'] as $i => $step) {
            $levels[] = 'Level '.($i + 1).': '.implode(', ', $step['approvers'])
                .(($modes[$step['mode']] ?? null) ? ' ('.$modes[$step['mode']].')' : '')
                .($step['conditional'] ? ' · only in some cases' : '')
                .($step['hours'] ? ' · usually within '.rtrim(rtrim(number_format($step['hours'], 1), '0'), '.').' hours' : '');
        }
        $outcome->problems($route['conditional'] ? 'pending' : 'idle',
            ($route['conditional'] ? 'May go to the workflow' : 'Approval workflow').' “'.$route['workflow'].'”',
            $levels,
            implode(' ', array_filter([
                $route['conditional'] ? 'It depends on the details of your request.' : null,
                $route['steps'] === [] ? 'No approval step: the workflow decides it on its own.' : null,
            ])));
    }

    if ($preview['informed'] !== []) {
        $outcome->fact('Informed', \App\Filament\Support\Review\Outcome::chips($preview['informed']));
    }
    if ($preview['policies'] !== []) {
        $outcome->fact('Read the policy', new \Illuminate\Support\HtmlString(collect($preview['policies'])
            ->map(fn (array $policy) => $policy['url']
                ? '<a href="'.e($policy['url']).'" target="_blank" rel="noopener" class="pos-link">'.e($policy['title']).'</a>'
                : e($policy['title']))
            ->implode('<br>')));
    }
    $shown = ! $outcome->isEmpty() || $definite > 0 || filled($preview['built_in']);
@endphp
@if ($shown){{ $outcome }}@endif
