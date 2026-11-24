<x-filament-panels::page>
    <div class="pos-toolbar">
        <x-pos.data-import-tabs current="import" />
        <x-pos.stepper class="ms-auto" :steps="\App\Filament\Pages\MasterImport::STEPS" :current="$this->step()" />
    </div>

    @if ($this->workbook === null)
        {{-- Step 1: choose the workbook --}}
        <x-pos.section title="Master uploads" :count="count($this->workbooks())">
            @include('filament.imports.master-upload-cards', ['workbooks' => $this->workbooks()])
        </x-pos.section>
        @include('filament.imports.master-upload-history', ['uploads' => $this->previous()])
    @else
        @php($wb = $this->describe($this->workbook))
        <div class="pos-di" style="{{ $this->tone() }}">
            <header class="pos-di-hero">
                <span class="pos-di-hero-icon" aria-hidden="true"><x-filament::icon :icon="$wb['icon']" class="size-6" /></span>
                <div class="pos-di-hero-body">
                    <span class="pos-di-eyebrow">Master upload</span>
                    <h2 class="pos-di-title">{{ $wb['label'] }}</h2>
                    <p class="pos-di-sub">{{ $wb['use'] }}</p>
                </div>
                <div class="pos-di-hero-side">
                    <span class="pos-di-chip" data-tone="tone"><x-filament::icon icon="heroicon-m-table-cells" class="size-4" />{{ count($wb['sheets']) }} worksheets</span>
                    @if ($this->upload === null)
                        <a class="pos-btn pos-btn-ghost pos-btn-sm" href="{{ \App\Filament\Pages\MasterImport::getUrl() }}" wire:navigate>Choose another</a>
                    @endif
                </div>
            </header>

            @if ($this->upload === null)
                {{-- Step 2: template and upload --}}
                <div class="pos-di-prep">
                    <form class="pos-di-card pos-di-drop" aria-labelledby="mu-upload-title" wire:submit="uploadAndCheck"
                        x-data="{ processing: false }" x-on:form-processing-started="processing = true" x-on:form-processing-finished="processing = false">
                        <div class="pos-di-card-head">
                            <span class="pos-di-step" aria-hidden="true">2</span>
                            <div>
                                <h3 class="pos-di-card-title" id="mu-upload-title">Upload your workbook</h3>
                                <p class="pos-di-card-sub">Fill in only the worksheets you need. Nothing changes until every row is checked and you approve it.</p>
                            </div>
                        </div>
                        {{ $this->form }}
                        <div class="pos-di-actions">
                            <a class="pos-btn pos-btn-ghost" href="{{ \App\Filament\Pages\DataImport::getUrl() }}" wire:navigate>Back to Data import</a>
                            <div class="pos-di-actions-end">
                                <x-filament::button type="submit" size="lg" wire:target="uploadAndCheck" icon="heroicon-m-arrow-up-tray" x-bind:disabled="processing">
                                    <span x-show="! processing">Upload and check</span><span x-show="processing" x-cloak>Waiting for the upload…</span>
                                </x-filament::button>
                            </div>
                        </div>
                    </form>

                    <aside class="pos-di-card pos-di-kit" aria-labelledby="mu-kit-title">
                        <div>
                            <h3 class="pos-di-card-title" id="mu-kit-title">Start from the template</h3>
                            <p class="pos-di-card-sub">One tab per worksheet, a Read me and a Columns guide. Required columns have a dark header; hover a header for what to enter.</p>
                        </div>
                        <button type="button" class="pos-di-get" data-kind="format" wire:click="downloadTemplate">
                            <span class="pos-di-get-icon" aria-hidden="true"><x-filament::icon icon="heroicon-o-document-text" class="size-5" /></span>
                            <span class="pos-di-get-body"><span class="pos-di-get-label">Download template</span><span class="pos-di-get-meta">Empty worksheets, ready to fill in</span></span>
                            <x-filament::icon icon="heroicon-m-arrow-down-tray" class="pos-di-get-go size-5" />
                        </button>
                        <button type="button" class="pos-di-get" data-kind="sample" wire:click="downloadSample">
                            <span class="pos-di-get-icon" aria-hidden="true"><x-filament::icon icon="heroicon-o-table-cells" class="size-5" /></span>
                            <span class="pos-di-get-body"><span class="pos-di-get-label">Download sample workbook</span><span class="pos-di-get-meta">The same with example rows</span></span>
                            <x-filament::icon icon="heroicon-m-arrow-down-tray" class="pos-di-get-go size-5" />
                        </button>
                        <ul class="pos-di-tips">
                            <li><x-filament::icon icon="heroicon-m-check-circle" />Point at other records by code (company, department, employee code).</li>
                            <li><x-filament::icon icon="heroicon-m-check-circle" />A code added in one worksheet can be used in later ones: PeopleOS works out the order.</li>
                            <li><x-filament::icon icon="heroicon-m-check-circle" />Dates as YYYY-MM-DD or DD-MM-YYYY; Excel dates are read as dates.</li>
                        </ul>
                    </aside>
                </div>

                <section class="pos-di-card" aria-labelledby="mu-order-title">
                    <div class="pos-di-card-head">
                        <span class="pos-di-step" aria-hidden="true"><x-filament::icon icon="heroicon-m-arrows-up-down" class="size-4" /></span>
                        <div>
                            <h3 class="pos-di-card-title" id="mu-order-title">What the workbook holds, in the order it runs</h3>
                            <p class="pos-di-card-sub">Worksheets of the same step do not depend on each other. Only worksheets you may upload are listed{{ count($wb['sheets']) < $wb['all'] ? ' ('.count($wb['sheets']).' of '.$wb['all'].')' : '' }}.</p>
                        </div>
                    </div>
                    <div class="pos-step-table-wrap" tabindex="0" role="region" aria-label="Worksheets in run order">
                        <table class="pos-step-table">
                            <thead><tr><th scope="col" class="is-num">Step</th><th scope="col">Worksheet</th><th scope="col">What it holds</th><th scope="col">Runs after</th></tr></thead>
                            <tbody>
                                @foreach ($wb['sheets'] as $sheet)
                                    <tr>
                                        <td class="is-num">{{ $sheet['stage'] }}</td>
                                        <td class="is-strong">{{ $sheet['title'] }}</td>
                                        <td class="is-quiet">{{ $sheet['about'] }}</td>
                                        <td class="is-quiet">{{ implode(', ', $sheet['after']) ?: '—' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </section>

                @include('filament.imports.master-upload-history', ['uploads' => $this->previous()])
            @else
                {{-- Steps 3 and 4: check, approve, import --}}
                @php($r = $this->review())
                @php($import = $r['import'])
                <div class="pos-mu" @if ($r['active']) wire:poll.3s="refreshStatus" @endif>
                    @if ($r['checking'])
                        <x-pos.step-wait icon="heroicon-o-arrow-path" title="Checking your workbook"
                            text="Each worksheet is read and every row is checked, in the order the worksheets run. This page updates by itself; you can also leave and come back from Recent uploads." />
                    @elseif ($import->status === 'failed' && $import->imported_at === null)
                        <x-pos.step-banner tone="blocked" title="The workbook could not be checked" :sub="$import->error">
                            <x-slot name="aside"><x-filament::button icon="heroicon-m-arrow-path" wire:click="uploadAgain">Upload a corrected workbook</x-filament::button></x-slot>
                        </x-pos.step-banner>
                    @else
                        @php($running = in_array($import->status, ['importing', 'imported', 'failed'], true))
                        @php($errorRows = (int) $import->error_count)
                        @if ($import->status === 'validated')
                            @if ($r['blocked'] !== [])
                                <x-pos.step-banner tone="blocked" title="{{ count($r['blocked']) }} worksheet(s) cannot be imported" sub="Fix them in the workbook and upload it again: {{ implode(', ', $r['blocked']) }}." />
                            @elseif ($r['review_rows'] > 0)
                                <x-pos.step-banner tone="warning" title="{{ $r['review_rows'] }} row(s) may be people already on file" sub="Decide each one on its worksheet's upload (Open), then come back to approve." />
                            @elseif ($errorRows > 0)
                                <x-pos.step-banner tone="warning" title="{{ $errorRows }} row(s) have errors and will not be imported" sub="Download the error workbook to fix them, or import the rest now and fix them in a later upload." />
                            @elseif ((int) $import->create_count + (int) $import->update_count + $r['waiting'] === 0)
                                <x-pos.step-banner tone="warning" title="Nothing to import" sub="Every row is already on file with these values, or outside what this workbook is for." />
                            @else
                                <x-pos.step-banner tone="clear" title="Every row checked out" sub="Approve it to import the worksheets in order." />
                            @endif
                        @elseif ($import->status === 'approved' && $r['can_start'])
                            <x-pos.step-banner tone="pending" title="Approved{{ $import->approver ? ' by '.$import->approver->name : '' }}" sub="Start the import when you are ready.">
                                <x-slot name="aside"><x-filament::button color="success" icon="heroicon-m-play" wire:click="startImport">Start import</x-filament::button></x-slot>
                            </x-pos.step-banner>
                        @elseif ($import->status === 'approved')
                            <x-pos.step-banner tone="pending" title="Approved{{ $import->approver ? ' by '.$import->approver->name : '' }}" sub="The person who uploaded it or approved it starts the import." />
                        @elseif ($import->status === 'importing')
                            <x-pos.step-banner tone="pending" title="Importing{{ $r['progress']['sheet'] ? ': '.$r['progress']['sheet'] : '' }}" sub="{{ number_format($r['progress']['done']) }} of {{ number_format($r['progress']['total']) }} rows done. This page updates by itself.">
                                <span class="pos-step-meter" style="--pct: {{ $r['progress']['percent'] }}; --tone: var(--pos-primary)" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $r['progress']['percent'] }}" aria-label="Import progress"><span></span></span>
                            </x-pos.step-banner>
                        @else
                            @php($notDone = ($r['outcomes']['partial'] ?? 0) + ($r['outcomes']['failed'] ?? 0))
                            <x-pos.step-banner :tone="$import->status === 'failed' ? 'blocked' : ($notDone > 0 || (int) $import->failure_count > 0 ? 'warning' : 'clear')"
                                :title="$import->status === 'failed' ? 'The import did not finish' : ($notDone > 0 ? 'Imported, with '.$notDone.' worksheet(s) partly done or failed' : ((int) $import->failure_count > 0 ? 'Imported, with '.$import->failure_count.' row(s) that failed' : 'Import finished'))"
                                :sub="$import->error ?? ($notDone > 0 ? 'Rows with errors, and rows that failed when their worksheet ran, stayed out; every other row went in. The error workbook lists each one with what to fix.' : 'Every worksheet ran in order; each row\'s result is kept with its worksheet.')" />
                        @endif

                        <dl class="pos-step-stats">
                            @if ($running)
                                <div class="pos-step-stat" data-tone="clear"><dt>Added</dt><dd>{{ number_format((int) $import->create_count) }}</dd></div>
                                <div class="pos-step-stat" data-tone="idle"><dt>Updated</dt><dd>{{ number_format((int) $import->update_count) }}</dd></div>
                                <div class="pos-step-stat"><dt>Skipped</dt><dd>{{ number_format((int) $import->skip_count) }}</dd></div>
                                <div class="pos-step-stat" data-tone="{{ (int) $import->failure_count > 0 ? 'blocked' : 'clear' }}"><dt>Failed when run</dt><dd>{{ number_format((int) $import->failure_count) }}</dd></div>
                                <div class="pos-step-stat" data-tone="{{ (int) $import->error_count > 0 ? 'warning' : 'clear' }}"><dt>Left out (errors)</dt><dd>{{ number_format((int) $import->error_count) }}</dd></div>
                            @else
                                <div class="pos-step-stat"><dt>Rows</dt><dd>{{ number_format((int) $import->row_count) }}</dd></div>
                                <div class="pos-step-stat" data-tone="clear"><dt>To add</dt><dd>{{ number_format((int) $import->create_count) }}</dd></div>
                                <div class="pos-step-stat" data-tone="idle"><dt>To update</dt><dd>{{ number_format((int) $import->update_count) }}</dd></div>
                                @if ($r['waiting'] > 0)<div class="pos-step-stat" data-tone="pending"><dt>Wait for earlier worksheets</dt><dd>{{ number_format($r['waiting']) }}</dd></div>@endif
                                <div class="pos-step-stat"><dt>Unchanged or skipped</dt><dd>{{ number_format((int) $import->skip_count) }}</dd></div>
                                <div class="pos-step-stat" data-tone="{{ $errorRows > 0 ? 'blocked' : 'clear' }}"><dt>With errors</dt><dd>{{ number_format($errorRows) }}</dd></div>
                            @endif
                        </dl>

                        @foreach ($r['warnings'] as $warning)
                            <div class="pos-di-note" data-tone="warning"><x-filament::icon icon="heroicon-m-information-circle" /><span>{{ $warning }}</span></div>
                        @endforeach
                        @if ($r['waiting_for'])
                            <div class="pos-di-note"><x-filament::icon icon="heroicon-m-user-group" /><span>{{ $r['waiting_for'] }} ({{ $r['approval'] }} approval: a workbook takes the strictest rule of the uploads it holds.)</span></div>
                        @endif

                        @if (! empty($r['references']['entries'] ?? []))
                            <section class="pos-di-card" aria-labelledby="mu-names-title">
                                <div class="pos-di-card-head">
                                    <span class="pos-di-step" aria-hidden="true"><x-filament::icon icon="heroicon-m-magnifying-glass" class="size-4" /></span>
                                    <div>
                                        <h3 class="pos-di-card-title" id="mu-names-title">Names used instead of codes</h3>
                                        <p class="pos-di-card-sub">Matches are listed for a glance. Choose what each unclear name means (several records, a near miss, or a name and code that disagree): nothing is ever picked for you, and the workbook is checked again after each choice.</p>
                                    </div>
                                </div>
                                @livewire(\App\Livewire\Resolution\ReferenceReview::class, ['report' => $r['references'], 'scope' => 'employee_imports:'.$import->id], key('refs-'.$import->id.'-'.$this->referencesRevision))
                            </section>
                        @endif

                        <section class="pos-di-card" aria-labelledby="mu-sheets-title">
                            <div class="pos-di-card-head">
                                <span class="pos-di-step" aria-hidden="true">{{ $running ? 4 : 3 }}</span>
                                <div>
                                    <h3 class="pos-di-card-title" id="mu-sheets-title">Worksheets, in the order they run</h3>
                                    <p class="pos-di-card-sub">{{ $import->original_name }} · uploaded by {{ $import->uploader?->name ?? '—' }} · {{ \App\Domain\Employment\Imports\Workbooks\WorkbookImports::MODES[$import->options['mode'] ?? 'both'][0] ?? '' }}</p>
                                </div>
                            </div>
                            @if ($running && $import->status !== 'importing')
                                <ul class="pos-mu-outcomes" aria-label="How the worksheets ended">
                                    @foreach (\App\Domain\Employment\Imports\Workbooks\WorkbookImports::OUTCOMES as $key => $label)
                                        @if (($r['outcomes'][$key] ?? 0) > 0)
                                            <li data-outcome="{{ $key }}"><strong>{{ $r['outcomes'][$key] }}</strong> {{ mb_strtolower($label) }}</li>
                                        @endif
                                    @endforeach
                                </ul>
                            @endif
                            <div class="pos-step-table-wrap" tabindex="0" role="region" aria-label="Worksheets">
                                <table class="pos-step-table">
                                    <thead>
                                        <tr>
                                            <th scope="col" class="is-num">Step</th><th scope="col">Worksheet</th><th scope="col" class="is-num">Rows</th>
                                            @if ($running)
                                                <th scope="col" class="is-num">Added</th><th scope="col" class="is-num">Updated</th><th scope="col" class="is-num">Skipped</th><th scope="col" class="is-num">Failed</th><th scope="col">Outcome</th>
                                            @else
                                                <th scope="col" class="is-num">Checked in full</th><th scope="col" class="is-num">Waiting</th><th scope="col" class="is-num">Blocked</th><th scope="col" class="is-num">Errors</th><th scope="col">What it will do</th>
                                            @endif
                                            <th scope="col"><span class="sr-only">Rows</span></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach ($r['sheets'] as $sheet)
                                            <tr wire:key="mu-sheet-{{ md5($sheet['title']) }}">
                                                <td class="is-num">{{ $sheet['stage'] }}</td>
                                                <td class="is-strong">{{ $sheet['title'] }}
                                                    @if ($sheet['after'] !== [])<span class="pos-mu-after">after {{ implode(', ', $sheet['after']) }}</span>@endif
                                                    @foreach ($sheet['waits_for'] as $provider => $n)
                                                        <span class="pos-mu-after">{{ $n['waiting'] > 0 ? 'waits for '.$provider.' ('.$n['waiting'].')' : '' }}{{ $n['waiting'] > 0 && $n['blocked'] > 0 ? ' · ' : '' }}{{ $n['blocked'] > 0 ? 'blocked by '.$provider.' ('.$n['blocked'].')' : '' }}</span>
                                                    @endforeach
                                                </td>
                                                <td class="is-num">{{ number_format((int) $sheet['rows']) }}</td>
                                                @if ($running)
                                                    <td class="is-num">{{ $sheet['create'] }}</td><td class="is-num">{{ $sheet['update'] }}</td><td class="is-num">{{ $sheet['skip'] }}</td>
                                                    <td class="is-num {{ $sheet['failed'] > 0 ? 'pos-mu-bad' : '' }}">{{ $sheet['failed'] }}</td>
                                                    <td class="is-quiet">
                                                        @if ($import->status === 'importing' && ($import->options['progress']['sheet'] ?? null) === $sheet['title'])Running · {{ $sheet['done'] }} of {{ $sheet['rows'] }}
                                                        @elseif ($import->status === 'importing' && $sheet['outcome'] === 'not_run')Waiting its turn
                                                        @else<span class="pos-mu-outcome" data-outcome="{{ $sheet['outcome'] }}">{{ \App\Domain\Employment\Imports\Workbooks\WorkbookImports::OUTCOMES[$sheet['outcome']] }}</span>{{ $sheet['problem'] ?? $sheet['error'] ? ' · '.($sheet['problem'] ?? $sheet['error']) : '' }}
                                                        @endif
                                                    </td>
                                                @else
                                                    <td class="is-num">{{ $sheet['checked'] }}</td><td class="is-num">{{ $sheet['waiting'] ?: '—' }}</td>
                                                    <td class="is-num {{ $sheet['blocked'] > 0 ? 'pos-mu-bad' : '' }}">{{ $sheet['blocked'] ?: '—' }}</td>
                                                    <td class="is-num {{ $sheet['other_errors'] > 0 ? 'pos-mu-bad' : '' }}">{{ $sheet['other_errors'] }}</td>
                                                    <td class="is-quiet">{{ $sheet['problem'] ?? ($sheet['review'] > 0 ? $sheet['review'].' row(s) to decide' : 'Adds '.$sheet['create'].' · updates '.$sheet['update'].' · skips '.$sheet['skip'].(($sheet['warnings'] ?? []) !== [] ? ' · '.implode(' ', $sheet['warnings']) : '')) }}</td>
                                                @endif
                                                <td class="is-end">
                                                    @if ($sheet['waiting'] + $sheet['errors'] + $sheet['failed'] > 0)<button type="button" class="pos-link" wire:click="showRows(@js($sheet['title']))">{{ $this->rowsSheet === $sheet['title'] ? 'Hide rows' : 'Rows' }}</button>@endif
                                                    @if ($sheet['url'])<a class="pos-link" href="{{ $sheet['url'] }}">Open</a>@endif
                                                </td>
                                            </tr>
                                            @if ($this->rowsSheet === $sheet['title'])
                                                <tr wire:key="mu-rows-{{ md5($sheet['title']) }}">
                                                    <td colspan="9">
                                                        <table class="pos-step-table pos-mu-rows">
                                                            <caption class="sr-only">Rows to look at in {{ $sheet['title'] }}</caption>
                                                            <thead><tr><th scope="col" class="is-num">Row</th><th scope="col">Status</th><th scope="col">What to know</th><th scope="col">Depends on</th></tr></thead>
                                                            <tbody>
                                                                @foreach ($this->sheetRows() as $row)
                                                                    <tr><td class="is-num">{{ $row['row'] }}</td><td>{{ $row['status'] }}</td><td>{{ $row['problem'] }}</td><td class="is-quiet">{{ $row['depends'] ?: '—' }}</td></tr>
                                                                @endforeach
                                                            </tbody>
                                                        </table>
                                                        <p class="pos-di-card-sub">The first 50; the error workbook lists every row.</p>
                                                    </td>
                                                </tr>
                                            @endif
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>

                            @if (! $running && ($r['waiting'] > 0 || array_sum(array_column($r['sheets'], 'blocked')) > 0))
                                <div class="pos-di-note"><x-filament::icon icon="heroicon-m-clock" /><span><strong>When the final check happens.</strong> Rows marked <em>Waiting</em> point at records an earlier worksheet adds: their required columns are checked now, and they get their complete check immediately before their own worksheet runs, once those records exist. A row that fails then is not imported and is listed in the error workbook; the other rows still go in. Rows marked <em>Blocked</em> depend on a row with errors in an earlier worksheet and are not imported until that row is fixed. Nothing in one worksheet changes the successful rows of another.</span></div>
                            @endif

                            @if ($r['can_approve'])
                                {{ $this->form }}
                            @endif

                            <div class="pos-di-actions">
                                @if (! $running && $import->status !== 'approved')
                                    <x-filament::button color="gray" icon="heroicon-m-arrow-path" wire:click="uploadAgain">Upload a corrected workbook</x-filament::button>
                                @endif
                                <x-filament::button color="gray" icon="heroicon-m-document-arrow-down" wire:click="downloadErrors">Download error workbook</x-filament::button>
                                @if ($running && $import->status !== 'importing')
                                    <a class="pos-btn pos-btn-ghost" href="{{ \App\Filament\Pages\MasterImport::getUrl(['workbook' => $this->workbook]) }}" wire:navigate>Upload another workbook</a>
                                @endif
                                @if ($r['can_approve'])
                                    @php($rowsToGo = (int) $import->create_count + (int) $import->update_count + $r['waiting'])
                                    <div class="pos-di-actions-end">
                                        <x-filament::button size="lg" color="success" icon="heroicon-m-play" wire:click="approve" wire:target="approve"
                                            wire:confirm="Approve and import this workbook? Up to {{ $rowsToGo }} row(s) will be written, worksheet by worksheet. Rows with errors are left out.">
                                            Approve and import {{ number_format($rowsToGo) }} row(s)
                                        </x-filament::button>
                                    </div>
                                @endif
                            </div>
                        </section>
                    @endif
                </div>
            @endif
        </div>
    @endif
</x-filament-panels::page>
