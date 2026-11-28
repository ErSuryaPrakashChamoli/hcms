<x-filament-panels::page>
    <div class="pos-toolbar">
        <x-pos.data-import-tabs current="documents" />
        <x-pos.stepper class="ms-auto" :steps="\App\Filament\Pages\EmployeeDocumentUpload::STEPS" :current="$this->step()" />
    </div>

    @php($batch = $this->current())
    <div class="pos-di" style="--pos-tone: var(--pos-hue-teal); --pos-tone-bg: var(--pos-hue-teal-bg);">
        @if ($batch === null)
            {{-- Step 1: upload the zips --}}
            <header class="pos-di-hero">
                <span class="pos-di-hero-icon" aria-hidden="true"><x-filament::icon icon="heroicon-o-folder-arrow-down" class="size-6" /></span>
                <div class="pos-di-hero-body">
                    <span class="pos-di-eyebrow">Workforce · Data import</span>
                    <h2 class="pos-di-title">Employee documents</h2>
                    <p class="pos-di-sub">Zip each employee's documents, name the zip with their code and name, and upload all the zips together. Each file is matched to a document type by its name: “Aadhar card.pdf” goes to Aadhaar.</p>
                </div>
                <div class="pos-di-hero-side">
                    @if (\App\Filament\Pages\DataImport::canAccess())
                        <a class="pos-btn pos-btn-ghost pos-btn-sm" href="{{ \App\Filament\Pages\DataImport::getUrl() }}" wire:navigate>All uploads</a>
                    @endif
                </div>
            </header>

            <div class="pos-di-prep">
                {{-- A form, so the file field's upload events (form-processing-started / -finished) reach the button: it waits for the upload. --}}
                <form class="pos-di-card pos-di-drop" aria-labelledby="edu-upload-title" wire:submit="uploadAndCheck"
                    x-data="{ processing: false }" x-on:form-processing-started="processing = true" x-on:form-processing-finished="processing = false">
                    <div class="pos-di-card-head">
                        <span class="pos-di-step" aria-hidden="true">1</span>
                        <div>
                            <h3 class="pos-di-card-title" id="edu-upload-title">Upload the zips</h3>
                            <p class="pos-di-card-sub">Many zips at once, or one zip with a folder or zip per employee. Nothing reaches an employee until you have checked the matches.</p>
                        </div>
                    </div>
                    {{ $this->form }}
                    <div class="pos-di-actions">
                        <div class="pos-di-actions-end">
                            <x-filament::button type="submit" size="lg" wire:target="uploadAndCheck" icon="heroicon-m-arrow-up-tray" x-bind:disabled="processing">
                                <span x-show="! processing">Upload and check</span><span x-show="processing" x-cloak>Waiting for the upload…</span>
                            </x-filament::button>
                        </div>
                    </div>
                </form>

                <aside class="pos-di-card pos-di-kit" aria-labelledby="edu-kit-title">
                    <div>
                        <h3 class="pos-di-card-title" id="edu-kit-title">How to name the zips and files</h3>
                        <p class="pos-di-card-sub">The zip's name finds the employee; each file's name finds its document type.</p>
                    </div>
                    <ul class="pos-edu-tree" aria-label="Example">
                        <li class="is-zip"><x-filament::icon icon="heroicon-m-archive-box" class="size-4" /><strong>EMP001_Asha Rao.zip</strong></li>
                        <li><span>Aadhar card.pdf</span><span class="pos-edu-goes">Aadhaar</span></li>
                        <li><span>PAN.pdf</span><span class="pos-edu-goes">PAN card</span></li>
                        <li><span>10th marksheet.jpg</span><span class="pos-edu-goes">10th marksheet</span></li>
                        <li><span>Passport till 2031-03-31.pdf</span><span class="pos-edu-goes">Passport + expiry</span></li>
                        <li class="is-zip"><x-filament::icon icon="heroicon-m-archive-box" class="size-4" /><strong>EMP002_Ravi Kumar.zip</strong></li>
                        <li><span>Experience letter.pdf · Relieving letter.pdf …</span></li>
                    </ul>
                    <button type="button" class="pos-di-get" data-kind="sample" wire:click="downloadSample">
                        <span class="pos-di-get-icon" aria-hidden="true"><x-filament::icon icon="heroicon-o-archive-box" class="size-5" /></span>
                        <span class="pos-di-get-body"><span class="pos-di-get-label">Download sample zip</span><span class="pos-di-get-meta">Two employees' zips, named as above</span></span>
                        <x-filament::icon icon="heroicon-m-arrow-down-tray" class="pos-di-get-go size-5" />
                    </button>
                    <button type="button" class="pos-di-get" data-kind="format" wire:click="downloadGuide">
                        <span class="pos-di-get-icon" aria-hidden="true"><x-filament::icon icon="heroicon-o-document-text" class="size-5" /></span>
                        <span class="pos-di-get-body"><span class="pos-di-get-label">Download names guide</span><span class="pos-di-get-meta">Every document type and the file names it is recognised by</span></span>
                        <x-filament::icon icon="heroicon-m-arrow-down-tray" class="pos-di-get-go size-5" />
                    </button>
                    <ul class="pos-di-tips">
                        <li><x-filament::icon icon="heroicon-m-check-circle" /><span>Name each zip or folder <strong>employee code_First Last name</strong> (EMP001_Asha_Rao), or with the first name only (EMP001_Asha). The code must be exactly the employee's and the name theirs as in PeopleOS, or none of its files is uploaded.</span></li>
                        <li><x-filament::icon icon="heroicon-m-check-circle" /><span>Name each file for what it is. Spelling slips (adhar, pasport) are understood; words like front, back and scan are ignored.</span></li>
                        <li><x-filament::icon icon="heroicon-m-check-circle" /><span>A date in the name is read too: “valid till”, “exp” or a future date is the expiry date, “issued” or a past date the issue date.</span></li>
                        <li><x-filament::icon icon="heroicon-m-check-circle" /><span>{{ implode(', ', (array) config('peopleos.documents.mimes')) }} files, up to {{ max(1, intdiv((int) config('peopleos.documents.max_kb'), 1024)) }} MB each.</span></li>
                    </ul>
                </aside>
            </div>
        @else
            {{-- Steps 2 and 3: check the matches, upload, results --}}
            @php($r = $this->review())
            <header class="pos-di-hero">
                <span class="pos-di-hero-icon" aria-hidden="true"><x-filament::icon icon="heroicon-o-folder-arrow-down" class="size-6" /></span>
                <div class="pos-di-hero-body">
                    <span class="pos-di-eyebrow">Employee documents upload #{{ $batch->id }}</span>
                    <h2 class="pos-di-title">{{ number_format($batch->file_count) }} {{ \Illuminate\Support\Str::plural('file', $batch->file_count) }} from {{ $batch->zip_count }} {{ \Illuminate\Support\Str::plural('zip', $batch->zip_count) }}</h2>
                    <p class="pos-di-sub">{{ $batch->zipNames() }} · uploaded by {{ $batch->creator?->name ?? '—' }}, {{ $batch->created_at?->format('d M Y, H:i') }} · {{ \App\Domain\Documents\Models\DocumentUploadBatch::EXISTING[$batch->option('existing', 'version')][0] ?? '' }} when one is on file</p>
                </div>
                <div class="pos-di-hero-side">
                    <span class="pos-di-chip" data-tone="tone"><x-filament::icon icon="heroicon-m-check-circle" class="size-4" />{{ \App\Filament\Pages\EmployeeDocumentUpload::statusLabel($batch) }}</span>
                    @if (! $batch->inReview() && ! $batch->isActive())
                        <a class="pos-btn pos-btn-ghost pos-btn-sm" href="{{ \App\Filament\Pages\EmployeeDocumentUpload::getUrl() }}" wire:navigate>Upload more zips</a>
                    @endif
                </div>
            </header>

            <div class="pos-edu" @if ($batch->isActive()) wire:poll.3s="refreshStatus" @endif>
                @if ($batch->inReview())
                    @if ($batch->ready === 0 && $batch->needs_type === 0)
                        <x-pos.step-banner tone="blocked" title="Nothing can be uploaded yet" sub="Every file is skipped or cannot be uploaded: each one says why. Include skipped files, or fix the zips and upload them again." />
                    @elseif ($r['unmatched_folders'] > 0)
                        <x-pos.step-banner tone="warning" title="{{ $r['unmatched_folders'] }} zip(s) or folder(s) do not match an employee" sub="The code and the name must both be an employee's (code_First_Last name). Their files are not uploaded: each says why. Rename them and upload them again." />
                    @elseif ($batch->needs_type > 0)
                        <x-pos.step-banner tone="warning" title="{{ $batch->needs_type }} file(s) need a document type" sub="Choose one with Change, or tick several and use Set document type. Files left without a type are not uploaded." />
                    @else
                        <x-pos.step-banner tone="clear" title="Every file is matched" sub="Look through the list, change anything that is not right, then upload." />
                    @endif
                @elseif ($batch->isActive())
                    @php($todo = $batch->ready + $batch->uploaded + $batch->failed)
                    @php($pct = $todo > 0 ? (int) floor(100 * ($batch->uploaded + $batch->failed) / $todo) : 0)
                    <x-pos.step-banner tone="pending" title="Uploading" sub="{{ number_format($batch->uploaded + $batch->failed) }} of {{ number_format($todo) }} files done. This page updates by itself; you can also leave and come back.">
                        <span class="pos-step-meter" style="--pct: {{ $pct }}; --tone: var(--pos-primary)" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $pct }}" aria-label="Upload progress"><span></span></span>
                    </x-pos.step-banner>
                @elseif ($batch->status === 'completed')
                    <x-pos.step-banner :tone="$batch->failed > 0 ? 'warning' : 'clear'"
                        :title="number_format($batch->uploaded).' document(s) uploaded'.($batch->failed > 0 ? ', '.number_format($batch->failed).' failed' : '')"
                        :sub="$batch->failed > 0 ? 'Each failed file says why. Fix them and upload those files again in a new zip.' : 'Each employee finds theirs under Documents, waiting for verification.'" />
                @elseif ($batch->status === 'failed')
                    <x-pos.step-banner tone="blocked" title="The upload stopped" :sub="$batch->error" />
                @else
                    <x-pos.step-banner tone="idle" title="Discarded" :sub="$batch->error ?? 'Nothing was uploaded, and the zips were removed.'" />
                @endif

                <dl class="pos-step-stats">
                    <div class="pos-step-stat"><dt>Employees</dt><dd>{{ number_format($batch->employee_count) }}</dd></div>
                    @if ($batch->inReview() || $batch->status === 'discarded')
                        <div class="pos-step-stat" data-tone="clear"><dt>Ready</dt><dd>{{ number_format($batch->ready) }}</dd></div>
                        <div class="pos-step-stat" data-tone="{{ $batch->needs_type > 0 ? 'warning' : 'clear' }}"><dt>Need a type</dt><dd>{{ number_format($batch->needs_type) }}</dd></div>
                    @else
                        <div class="pos-step-stat" data-tone="clear"><dt>Uploaded</dt><dd>{{ number_format($batch->uploaded) }}</dd></div>
                        <div class="pos-step-stat" data-tone="{{ $batch->failed > 0 ? 'blocked' : 'clear' }}"><dt>Failed</dt><dd>{{ number_format($batch->failed) }}</dd></div>
                    @endif
                    <div class="pos-step-stat"><dt>Skipped</dt><dd>{{ number_format($batch->skipped) }}</dd></div>
                    <div class="pos-step-stat" data-tone="{{ $batch->blocked > 0 ? 'blocked' : 'clear' }}"><dt>Cannot upload</dt><dd>{{ number_format($batch->blocked) }}</dd></div>
                </dl>

                @if ($batch->inReview() && $r['no_expiry'] > 0)
                    <div class="pos-di-note" data-tone="warning"><x-filament::icon icon="heroicon-m-calendar" /><span>{{ $r['no_expiry'] }} ready file(s) are of a type with an expiry date but have none. Add it with Change so expiry reminders work, or upload them without it.</span></div>
                @endif
                @if ($batch->inReview() && $r['new_versions'] > 0)
                    <div class="pos-di-note"><x-filament::icon icon="heroicon-m-information-circle" /><span>{{ $r['new_versions'] }} file(s) become the new version of a document the employee already has; the earlier one stays in its history.</span></div>
                @endif

                <section class="pos-di-card" aria-labelledby="edu-files-title">
                    <div class="pos-di-card-head">
                        <span class="pos-di-step" aria-hidden="true">{{ $batch->inReview() ? 2 : 3 }}</span>
                        <div>
                            <h3 class="pos-di-card-title" id="edu-files-title">Files, by employee</h3>
                            <p class="pos-di-card-sub">{{ $batch->inReview() ? 'Each file with the document type its name matched. Change, skip or include files before uploading.' : 'What happened to each file.' }}</p>
                        </div>
                    </div>
                    {{ $this->table }}

                    <div class="pos-di-actions">
                        <x-filament::button color="gray" icon="heroicon-m-document-arrow-down" wire:click="downloadResults">{{ $batch->inReview() ? 'Download the list' : 'Download results' }}</x-filament::button>
                        @if ($this->mayChange())
                            <x-filament::button color="gray" icon="heroicon-m-trash" wire:click="discardUpload" wire:confirm="Discard this upload? Nothing is uploaded, and the zips are removed.">Discard</x-filament::button>
                            @if ($batch->ready > 0)
                                <div class="pos-di-actions-end">
                                    <x-filament::button size="lg" color="success" icon="heroicon-m-arrow-up-tray" wire:click="startUpload" wire:target="startUpload"
                                        wire:confirm="Upload {{ $batch->ready }} document(s)? Each goes to its employee, waiting for verification.{{ $batch->needs_type > 0 ? ' '.$batch->needs_type.' file(s) without a document type are left out.' : '' }}">
                                        Upload {{ number_format($batch->ready) }} document(s)
                                    </x-filament::button>
                                </div>
                            @endif
                        @endif
                    </div>
                </section>
            </div>
        @endif

        @php($earlier = $this->previous())
        @if ($earlier !== [])
            <section class="pos-di-card" id="edu-history" aria-labelledby="edu-history-title">
                <div class="pos-di-card-head">
                    <span class="pos-di-step" aria-hidden="true"><x-filament::icon icon="heroicon-m-clock" class="size-4" /></span>
                    <div>
                        <h3 class="pos-di-card-title" id="edu-history-title">Previous document uploads</h3>
                        <p class="pos-di-card-sub">Each file's result stays with its upload.</p>
                    </div>
                </div>
                <div class="pos-step-table-wrap" tabindex="0" role="region" aria-label="Previous document uploads">
                    <table class="pos-step-table">
                        <thead><tr><th scope="col">Zips</th><th scope="col">Status</th><th scope="col">Files</th><th scope="col">Uploaded</th><th scope="col"><span class="sr-only">Open</span></th></tr></thead>
                        <tbody>
                            @foreach ($earlier as $upload)
                                <tr wire:key="edu-history-{{ $upload->id }}">
                                    <td class="is-strong">{{ $upload->zipNames(1) }}</td>
                                    <td>{{ \App\Filament\Pages\EmployeeDocumentUpload::statusLabel($upload) }}</td>
                                    <td class="is-quiet">{{ $upload->summary() }}</td>
                                    <td class="is-quiet">{{ $upload->created_at?->format('d M Y, H:i') }} · {{ $upload->creator?->name ?? '—' }}</td>
                                    <td class="is-end"><a class="pos-link" href="{{ \App\Filament\Pages\EmployeeDocumentUpload::getUrl(['batch' => $upload->id]) }}" wire:navigate>Open</a></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </section>
        @endif
    </div>
</x-filament-panels::page>
