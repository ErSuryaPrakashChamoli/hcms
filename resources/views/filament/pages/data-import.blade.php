<x-filament-panels::page>
    <div class="pos-toolbar">
        <x-pos.data-import-tabs current="import" />
        <x-pos.stepper class="ms-auto" :steps="\App\Filament\Pages\DataImport::STEPS" :current="$this->step - 1" />
    </div>

    @php($chosen = $this->current())
    @php($heads = \App\Domain\Employment\Imports\ImportCatalogue::HEADS)
    @if ($chosen !== null && \App\Domain\Employment\Imports\ImportCatalogue::notApplicable($chosen) !== null)
        <div class="pos-di" style="{{ $this->tone($chosen) }}">
            <header class="pos-di-hero">
                <span class="pos-di-hero-icon" aria-hidden="true"><x-filament::icon :icon="$heads[$chosen['head']][1]" class="size-6" /></span>
                <div class="pos-di-hero-body">
                    <span class="pos-di-eyebrow">{{ $heads[$chosen['head']][0] }}</span>
                    <h2 class="pos-di-title">{{ $chosen['label'] }}</h2>
                    <p class="pos-di-sub">{{ $chosen['about'] }}</p>
                </div>
            </header>
            <div class="pos-di-note" data-tone="warning"><x-filament::icon icon="heroicon-m-information-circle" /><span>{{ \App\Domain\Employment\Imports\ImportCatalogue::notApplicable($chosen) }}</span></div>
            <div><x-filament::button color="gray" icon="heroicon-m-arrow-left" wire:click="startOver">Choose another upload</x-filament::button></div>
        </div>
    @elseif ($this->step === 1)
        @php($searching = trim($search) !== '')
        <label class="pos-search pos-search-lg" style="max-width: none">
            <x-filament::icon icon="heroicon-m-magnifying-glass" class="size-5 pos-muted" />
            <span class="sr-only">Find an upload</span>
            <input type="search" wire:model.live.debounce.300ms="search" placeholder="branches · leave balance · bank · loans · goals · assets…" autocomplete="off" autofocus />
        </label>

        <x-pos.section :title="$searching ? 'Matches' : 'Select process'" :count="$this->processCount()">
            @php($groups = $this->browseGroups())
            @if ($groups === [])
                <x-pos.state variant="filtered" size="inline" title="Nothing matches “{{ trim($search) }}”." why="Try another word, or clear the search to see every upload. Only uploads you may run are shown." />
            @else
                <div wire:key="data-import-browse-{{ md5(trim($search)) }}">
                    <x-pos.browse label="Import processes" :remember="$searching ? null : 'data-import'" :groups="$groups" />
                </div>
            @endif
        </x-pos.section>
    @else
        @php($process = $chosen)
        @php($intent = $this->intentLabel($process))
        <div class="pos-di" style="{{ $this->tone($process) }}">
            <header class="pos-di-hero">
                <span class="pos-di-hero-icon" aria-hidden="true"><x-filament::icon :icon="$heads[$process['head']][1]" class="size-6" /></span>
                <div class="pos-di-hero-body">
                    <span class="pos-di-eyebrow">{{ $heads[$process['head']][0] }}</span>
                    <h2 class="pos-di-title">{{ $process['label'] }}</h2>
                    <p class="pos-di-sub">{{ $process['about'] }}</p>
                </div>
                <div class="pos-di-hero-side">
                    @if ($this->step > 2 && $intent)
                        <span class="pos-di-chip" data-tone="tone"><x-filament::icon icon="heroicon-m-check-circle" class="size-4" />{{ $intent }}</span>
                    @endif
                    @if ($this->step < 4)
                        <button type="button" class="pos-btn pos-btn-ghost pos-btn-sm" wire:click="startOver">Choose another upload</button>
                    @endif
                </div>
            </header>

            @if ($this->step === 2)
                <section class="pos-di-card pos-di-intent" aria-labelledby="di-intent-title">
                    <div class="pos-di-card-head">
                        <span class="pos-di-step" aria-hidden="true">2</span>
                        <div>
                            <h3 class="pos-di-card-title" id="di-intent-title">What is this file for?</h3>
                            <p class="pos-di-card-sub">Rows outside your choice are skipped and listed, never written.</p>
                        </div>
                    </div>
                    @if (isset($process['mode']))
                        <div class="pos-di-note"><x-filament::icon icon="heroicon-m-information-circle" /><span>{{ $process['mode'] === 'new' ? 'This file adds new records. Rows for records already on file are skipped.' : 'This file updates records already on file. Rows for records not on file are skipped.' }}</span></div>
                    @elseif (\App\Domain\Employment\Imports\ImportCatalogue::modes($process) === null)
                        <div class="pos-di-note"><x-filament::icon icon="heroicon-m-information-circle" /><span>This file adds records. A record already loaded is recognised and skipped.</span></div>
                    @endif
                    {{ $this->form }}
                    <div class="pos-di-actions">
                        <x-filament::button color="gray" icon="heroicon-m-arrow-left" wire:click="back">Back</x-filament::button>
                        <div class="pos-di-actions-end">
                            <x-filament::button wire:click="toPrepare" icon="heroicon-m-arrow-right" icon-position="after">Next: prepare your data</x-filament::button>
                        </div>
                    </div>
                </section>
            @elseif ($this->step === 3)
                @php($format = $this->formatData())
                @php($required = $this->requiredColumns($format))
                <div class="pos-di-prep">
                    {{-- A form, so the file field's upload events (form-processing-started / -finished) reach the button: it waits for the upload. --}}
                    <form class="pos-di-card pos-di-drop" aria-labelledby="di-upload-title" wire:submit="uploadAndCheck"
                        x-data="{ processing: false }" x-on:form-processing-started="processing = true" x-on:form-processing-finished="processing = false">
                        <div class="pos-di-card-head">
                            <span class="pos-di-step" aria-hidden="true">3</span>
                            <div>
                                <h3 class="pos-di-card-title" id="di-upload-title">Upload your file</h3>
                                <p class="pos-di-card-sub">Your filled-in CSV. Nothing changes until it is checked and you run it.</p>
                            </div>
                        </div>
                        {{ $this->form }}
                        <div class="pos-di-actions">
                            <x-filament::button color="gray" icon="heroicon-m-arrow-left" wire:click="back">Back</x-filament::button>
                            <div class="pos-di-actions-end">
                                <x-filament::button type="submit" size="lg" wire:target="uploadAndCheck" icon="heroicon-m-arrow-up-tray" x-bind:disabled="processing">
                                    <span x-show="! processing">Upload and check</span><span x-show="processing" x-cloak>Waiting for the upload…</span>
                                </x-filament::button>
                            </div>
                        </div>
                    </form>

                    <aside class="pos-di-card pos-di-kit" aria-labelledby="di-kit-title">
                        <div>
                            <h3 class="pos-di-card-title" id="di-kit-title">Start from a template</h3>
                            <p class="pos-di-card-sub">Made for this upload, so its columns are matched for you.</p>
                        </div>
                        <button type="button" class="pos-di-get" data-kind="sample" wire:click="downloadSample">
                            <span class="pos-di-get-icon" aria-hidden="true"><x-filament::icon icon="heroicon-o-table-cells" class="size-5" /></span>
                            <span class="pos-di-get-body"><span class="pos-di-get-label">Download sample file</span><span class="pos-di-get-meta">The header row with example rows</span></span>
                            <x-filament::icon icon="heroicon-m-arrow-down-tray" class="pos-di-get-go size-5" />
                        </button>
                        <button type="button" class="pos-di-get" data-kind="format" wire:click="downloadFormat">
                            <span class="pos-di-get-icon" aria-hidden="true"><x-filament::icon icon="heroicon-o-document-text" class="size-5" /></span>
                            <span class="pos-di-get-body"><span class="pos-di-get-label">Download CSV format</span><span class="pos-di-get-meta">The header row only, ready to fill in</span></span>
                            <x-filament::icon icon="heroicon-m-arrow-down-tray" class="pos-di-get-go size-5" />
                        </button>
                        @if ($required !== [])
                            <div class="grid gap-2">
                                <span class="pos-di-kit-label">Every row needs</span>
                                <div class="pos-di-cols">
                                    @foreach ($required as $column)
                                        <span class="pos-di-col">{{ $column }}</span>
                                    @endforeach
                                </div>
                            </div>
                        @endif
                        <ul class="pos-di-tips">
                            <li><x-filament::icon icon="heroicon-m-check-circle" />Save from Excel as CSV UTF-8 (comma delimited).</li>
                            <li><x-filament::icon icon="heroicon-m-check-circle" />Dates as YYYY-MM-DD or DD-MM-YYYY.</li>
                            <li><x-filament::icon icon="heroicon-m-check-circle" />Columns in any order; columns PeopleOS does not use are left out.</li>
                        </ul>
                    </aside>
                </div>

                @include('filament.imports.format-guide-block', ['format' => $format])
            @else
                @php($review = $this->review())
                @php($import = $review['import'])
                @if ($this->mappingError !== null)
                    <section class="pos-di-card" aria-labelledby="di-map-title">
                        <div class="pos-di-card-head">
                            <span class="pos-di-step" aria-hidden="true">4</span>
                            <div>
                                <h3 class="pos-di-card-title" id="di-map-title">Match the columns</h3>
                                <p class="pos-di-card-sub">{{ $import->original_name }} · choose the PeopleOS field for each column of your file; fields marked * are required.</p>
                            </div>
                        </div>
                        <div class="pos-di-note" data-tone="warning"><x-filament::icon icon="heroicon-m-exclamation-triangle" /><span>{{ $this->mappingError }}</span></div>
                        <div class="pos-di-map">
                            @foreach ($import->headers ?? [] as $i => $header)
                                <label class="pos-di-map-row">
                                    <span>{{ $header }}</span>
                                    <x-filament::input.wrapper>
                                        <x-filament::input.select wire:model="mapping.{{ $i }}">
                                            <option value="">Ignore column</option>
                                            @foreach ($review['fields'] as $field => $label)
                                                <option value="{{ $field }}">{{ $label }}</option>
                                            @endforeach
                                        </x-filament::input.select>
                                    </x-filament::input.wrapper>
                                </label>
                            @endforeach
                        </div>
                        <div class="pos-di-actions">
                            <x-filament::button color="gray" icon="heroicon-m-arrow-path" wire:click="reupload">Re-upload fixes</x-filament::button>
                            <div class="pos-di-actions-end"><x-filament::button wire:click="saveMapping" icon="heroicon-m-check">Save and check</x-filament::button></div>
                        </div>
                    </section>
                @elseif ($review['done'])
                    <section class="pos-di-card" aria-labelledby="di-done-title">
                        <div class="pos-di-done">
                            <span class="pos-di-done-icon" aria-hidden="true"><x-filament::icon icon="heroicon-o-check" /></span>
                            <h3 class="pos-di-card-title" id="di-done-title">Import finished</h3>
                            <p class="pos-di-card-sub">{{ $import->original_name }} · every row's result is kept with the upload.</p>
                        </div>
                        <div class="pos-di-stats">
                            @foreach ([['Added', $import->create_count, 'add'], ['Updated', $import->update_count, 'update'], ['Skipped', $import->skip_count, ''], ['Failed', $import->failure_count, 'error']] as [$label, $value, $tone])
                                <div class="pos-di-stat" @if ($tone && $value) data-tone="{{ $tone }}" @endif><span class="pos-di-stat-value">{{ $value }}</span><span class="pos-di-stat-label">{{ $label }}</span></div>
                            @endforeach
                        </div>
                        <div class="pos-di-actions">
                            @if ($review['url'])<x-filament::button tag="a" :href="$review['url']" color="gray" icon="heroicon-m-arrow-top-right-on-square">View import log</x-filament::button>@endif
                            <x-filament::button color="gray" icon="heroicon-m-document-arrow-down" wire:click="downloadResults">Download results</x-filament::button>
                            <div class="pos-di-actions-end"><x-filament::button wire:click="startOver" icon="heroicon-m-plus">Import another file</x-filament::button></div>
                        </div>
                    </section>
                @else
                    <section class="pos-di-card" aria-labelledby="di-review-title">
                        <div class="pos-di-card-head">
                            <span class="pos-di-step" aria-hidden="true">4</span>
                            <div>
                                <h3 class="pos-di-card-title" id="di-review-title">Review &amp; fix</h3>
                                <p class="pos-di-card-sub">{{ $import->original_name }} · {{ $import->row_count }} row(s) checked</p>
                            </div>
                        </div>
                        <div class="pos-di-stats">
                            @foreach ([['To add', $import->create_count, 'add'], ['To update', $import->update_count, 'update'], ['Skipped', $import->skip_count, ''], ['With errors', $import->error_count, 'error']] as [$label, $value, $tone])
                                <div class="pos-di-stat" @if ($tone && $value) data-tone="{{ $tone }}" @endif><span class="pos-di-stat-value">{{ $value }}</span><span class="pos-di-stat-label">{{ $label }}</span></div>
                            @endforeach
                            @if ($import->review_count > 0)
                                <div class="pos-di-stat" data-tone="decide"><span class="pos-di-stat-value">{{ $import->review_count }}</span><span class="pos-di-stat-label">To decide</span></div>
                            @endif
                        </div>

                        @if ($review['errors']->isNotEmpty())
                            <div class="pos-di-note" data-tone="error"><x-filament::icon icon="heroicon-m-exclamation-circle" /><span>There {{ $import->error_count === 1 ? 'is 1 row' : 'are '.$import->error_count.' rows' }} with errors. They are left out when the file runs: correct them in your file and choose <strong>Re-upload fixes</strong>, or run the rest.</span></div>
                            <div class="pos-fmt-scroll" tabindex="0" role="region" aria-label="Rows with errors">
                                <table class="pos-fmt-table">
                                    <caption class="sr-only">Rows with errors</caption>
                                    <thead><tr><th scope="col" style="width: 1%">Row</th><th scope="col">What to fix</th></tr></thead>
                                    <tbody>
                                        @foreach ($review['errors'] as $row)
                                            <tr><td><span class="pos-fmt-name">{{ $row->row_number }}</span></td><td>{{ implode(' ', $row->errors ?? []) }}</td></tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                            @if ($review['more_errors'] > 0)
                                <p class="pos-di-card-sub">{{ $review['more_errors'] }} more in the results file.</p>
                            @endif
                        @elseif ($import->create_count + $import->update_count > 0)
                            <div class="pos-di-note" data-tone="success"><x-filament::icon icon="heroicon-m-check-circle" /><span>Every row checked out. Run the import when you are ready.</span></div>
                        @endif

                        @if ($import->review_count > 0)
                            <div class="pos-di-note" data-tone="warning"><x-filament::icon icon="heroicon-m-users" /><span>{{ $import->review_count }} row(s) may be people already on file. Decide each one on the upload's own screen before running it.</span></div>
                        @elseif ($review['waiting'])
                            <div class="pos-di-note"><x-filament::icon icon="heroicon-m-clock" /><span>{{ $review['waiting'] }}. The upload waits in Recent uploads until then.</span></div>
                        @elseif ($import->create_count + $import->update_count === 0)
                            <div class="pos-di-note" data-tone="warning"><x-filament::icon icon="heroicon-m-information-circle" /><span>Nothing to import: no row would add or change anything.</span></div>
                        @endif

                        @if ($review['can_submit'])
                            {{ $this->form }}
                        @endif

                        <div class="pos-di-actions">
                            <x-filament::button color="gray" wire:click="reupload" icon="heroicon-m-arrow-path">Re-upload fixes</x-filament::button>
                            <x-filament::button color="gray" icon="heroicon-m-document-arrow-down" wire:click="downloadResults">Download results</x-filament::button>
                            @if ($review['url'] && ($import->review_count > 0 || $review['waiting']))
                                <x-filament::button tag="a" :href="$review['url']" color="gray" icon="heroicon-m-arrow-top-right-on-square">Open the upload</x-filament::button>
                            @endif
                            @if ($review['can_submit'])
                                <div class="pos-di-actions-end">
                                    <x-filament::button size="lg" color="success" icon="heroicon-m-play" wire:click="submit" wire:confirm="Run this import now? {{ $import->create_count + $import->update_count }} row(s) will be written.">
                                        Import {{ $import->create_count + $import->update_count }} row(s)
                                    </x-filament::button>
                                </div>
                            @endif
                        </div>
                    </section>
                @endif
            @endif
        </div>
    @endif
</x-filament-panels::page>
