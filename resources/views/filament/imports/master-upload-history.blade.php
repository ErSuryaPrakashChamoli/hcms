{{-- Master uploads: this person's earlier workbooks (MasterImport::previous), newest first. --}}
@if ($uploads !== [])
    <section class="pos-di-card" id="mu-history" aria-labelledby="mu-history-title">
        <div class="pos-di-card-head">
            <span class="pos-di-step" aria-hidden="true"><x-filament::icon icon="heroicon-m-clock" class="size-4" /></span>
            <div>
                <h3 class="pos-di-card-title" id="mu-history-title">Previous master uploads</h3>
                <p class="pos-di-card-sub">Every worksheet's rows and results stay with its upload. All uploads are also in Recent uploads.</p>
            </div>
        </div>
        <div class="pos-step-table-wrap" tabindex="0" role="region" aria-label="Previous master uploads">
            <table class="pos-step-table">
                <thead><tr><th scope="col">Workbook</th><th scope="col">File</th><th scope="col">Status</th><th scope="col" class="is-num">Rows</th><th scope="col">Uploaded</th><th scope="col"><span class="sr-only">Open</span></th></tr></thead>
                <tbody>
                    @foreach ($uploads as $upload)
                        <tr wire:key="mu-history-{{ $upload->id }}">
                            <td class="is-strong">{{ \App\Domain\Employment\Imports\Workbooks\WorkbookCatalogue::find(\App\Domain\Employment\Imports\Workbooks\WorkbookCatalogue::keyOf((string) $upload->type))['label'] ?? $upload->type }}</td>
                            <td class="is-quiet">{{ $upload->original_name }}</td>
                            <td>{{ \App\Filament\Pages\MasterImport::statusLabel($upload) }}</td>
                            <td class="is-num">{{ number_format((int) $upload->row_count) }}</td>
                            <td class="is-quiet">{{ $upload->created_at?->format('d M Y, H:i') }} · {{ $upload->uploader?->name ?? '—' }}</td>
                            <td class="is-end"><a class="pos-link" href="{{ \App\Filament\Pages\MasterImport::getUrl(['upload' => $upload->id]) }}" wire:navigate>Open</a></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>
@endif
