{{-- The format guide as an open block under an upload's fields (Data import step 3 and every upload dialog). Not a form
     section on purpose: record forms with two sections become step wizards, and the guide is reading matter, not a step. --}}
@if (! empty($format))
    <details class="pos-di-card pos-di-guide" open>
        <summary>
            <span class="pos-di-guide-icon" aria-hidden="true"><x-filament::icon icon="heroicon-o-book-open" class="size-5" /></span>
            <span>
                <span class="pos-di-card-title block">File format and example</span>
                <span class="pos-di-card-sub block">Every column, what to enter, and how the file looks.</span>
            </span>
            <x-filament::icon icon="heroicon-m-chevron-down" class="pos-di-chevron size-5" />
        </summary>
        @include('filament.imports.csv-format', $format)
    </details>
@endif
