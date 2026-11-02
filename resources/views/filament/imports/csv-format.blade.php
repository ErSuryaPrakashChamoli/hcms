{{-- The upload format guide every import screen shows, built from the import service so it matches what the upload accepts:
     the columns with Required / Optional badges, the codes or choices the upload takes, the example as a spreadsheet, and notes. --}}
@php
    $letter = function (int $i): string {
        $s = '';
        for ($n = $i + 1; $n > 0; $n = intdiv($n - 1, 26)) {
            $s = chr(65 + (($n - 1) % 26)).$s;
        }

        return $s;
    };
    $badge = fn (string $required): array => match (mb_strtolower(trim($required))) {
        'yes' => ['required', 'Required'],
        'no', '' => ['optional', 'Optional'],
        default => ['conditional', $required],
    };
    $width = max(array_map('count', $sampleRows ?: [[]]));
@endphp
<div class="pos-fmt-wrap">
    <div class="pos-fmt">
        <section class="pos-fmt-sec" aria-labelledby="fmt-columns-{{ md5($title) }}">
            <h3 class="pos-fmt-h" id="fmt-columns-{{ md5($title) }}">Columns <small>{{ count($columns) }}</small></h3>
            <p class="pos-fmt-lead">The first row of the file is the header. Columns may come in any order; they are matched by name and you can confirm the match. Save from Excel as <strong>CSV UTF-8 (comma delimited)</strong>.</p>
            <div class="pos-fmt-scroll" tabindex="0" role="region" aria-label="{{ $title }} columns">
                <table class="pos-fmt-table">
                    <caption class="sr-only">{{ $title }} columns</caption>
                    <thead><tr><th scope="col">Column</th><th scope="col">Required</th><th scope="col">What to enter</th><th scope="col">Example</th></tr></thead>
                    <tbody>
                        @foreach ($columns as [$column, $required, $what, $example])
                            @php([$kind, $label] = $badge((string) $required))
                            <tr>
                                <td><span class="pos-fmt-name">{{ $column }}</span></td>
                                <td><span class="pos-fmt-badge" data-kind="{{ $kind }}">{{ $label }}</span></td>
                                <td>{{ $what }}</td>
                                <td><span class="pos-fmt-example">{{ $example }}</span></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>

        @if (! empty($codes))
            <section class="pos-fmt-sec" aria-label="{{ $codesTitle ?? 'Day codes' }}">
                <h3 class="pos-fmt-h">{{ $codesTitle ?? 'Day codes' }}</h3>
                <div class="pos-fmt-codes">
                    @foreach ($codes as $dayCode => $meaning)
                        <div class="pos-fmt-code"><span class="pos-fmt-name">{{ $dayCode }}</span><span>{{ $meaning }}</span></div>
                    @endforeach
                </div>
            </section>
        @endif

        @if (! empty($modes))
            <section class="pos-fmt-sec" aria-label="New or existing">
                <h3 class="pos-fmt-h">New or existing</h3>
                <p class="pos-fmt-lead">When you upload, choose what the file is for. The same file format serves every choice.</p>
                <div class="pos-fmt-modes">
                    @foreach ($modes as [$label, $description])
                        <div class="pos-fmt-mode"><strong>{{ $label }}</strong>{{ $description }}</div>
                    @endforeach
                </div>
            </section>
        @endif

        <section class="pos-fmt-sec" aria-label="Example file">
            <h3 class="pos-fmt-h">Example file <small>as it looks in a spreadsheet</small></h3>
            <div class="pos-fmt-scroll" tabindex="0" role="region" aria-label="Example {{ $title }} file">
                <table class="pos-fmt-sheet">
                    <caption class="sr-only">Example {{ $title }} file</caption>
                    <thead>
                        <tr class="pos-fmt-letters" aria-hidden="true">
                            <td class="pos-fmt-gutter"></td>
                            @for ($i = 0; $i < $width; $i++)
                                <td>{{ $letter($i) }}</td>
                            @endfor
                        </tr>
                        {{-- Row 1 of the file is its header row. --}}
                        <tr data-header>
                            <td class="pos-fmt-gutter">1</td>
                            @for ($i = 0; $i < $width; $i++)
                                <th scope="col">{{ $sampleRows[0][$i] ?? '' }}</th>
                            @endfor
                        </tr>
                    </thead>
                    <tbody>
                        @foreach (array_slice($sampleRows, 1) as $n => $row)
                            <tr>
                                <td class="pos-fmt-gutter">{{ $n + 2 }}</td>
                                @for ($i = 0; $i < $width; $i++)
                                    <td>{{ $row[$i] ?? '' }}</td>
                                @endfor
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>

        @if (! empty($notes))
            <ul class="pos-fmt-notes" aria-label="Good to know">
                @foreach ($notes as $note)
                    <li><x-filament::icon icon="heroicon-m-information-circle" />{{ $note }}</li>
                @endforeach
            </ul>
        @endif
    </div>
</div>
