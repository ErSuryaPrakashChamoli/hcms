<section class="pos-di-card" aria-labelledby="cols-title-{{ $importId }}">
    <details @if ($open) open @endif>
        <summary class="pos-di-card-head" style="cursor: pointer; list-style: none">
            <div>
                <h3 class="pos-di-card-title" id="cols-title-{{ $importId }}">Columns in your file</h3>
                <p class="pos-di-card-sub">
                    @if ($unknown > 0)
                        {{ $unknown === 1 ? '1 column is' : $unknown.' columns are' }} not recognised: choose the field each one holds, or leave it out.
                    @elseif ($missing !== [])
                        A required field has no column yet: choose the column that holds it.
                    @else
                        Every column is matched to a field. Open to check or change how each column is read.
                    @endif
                </p>
            </div>
        </summary>

        @if ($problem)
            <div class="pos-di-note" data-tone="error"><x-filament::icon icon="heroicon-m-exclamation-circle" /><span>{{ $problem }}</span></div>
        @endif
        @if ($missing !== [])
            <div class="pos-di-note" data-tone="warning"><x-filament::icon icon="heroicon-m-exclamation-triangle" /><span>No column chosen for: {{ implode(', ', $missing) }}.</span></div>
        @endif

        <div class="pos-fmt-scroll" tabindex="0" role="region" aria-label="Columns in your file">
            <table class="pos-fmt-table">
                <caption class="sr-only">Each column of the file and the field it is read as</caption>
                <thead>
                    <tr>
                        <th scope="col">Column in your file</th>
                        <th scope="col">First value</th>
                        <th scope="col">Read as</th>
                        <th scope="col">Status</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($columns as $column)
                        <tr wire:key="col-{{ $importId }}-{{ $column['index'] }}">
                            <td><span class="pos-fmt-name">{{ $column['header'] }}</span></td>
                            <td>{{ $column['example'] !== '' ? \Illuminate\Support\Str::limit($column['example'], 40) : '—' }}</td>
                            <td style="min-width: 14rem">
                                @if ($editable)
                                    <x-filament::input.wrapper>
                                        <x-filament::input.select wire:model="mapping.{{ $column['index'] }}" aria-label="Field for the column {{ $column['header'] }}">
                                            <option value="">Leave this column out</option>
                                            @foreach ($fields as $field => $label)
                                                <option value="{{ $field }}">{{ $label }}</option>
                                            @endforeach
                                        </x-filament::input.select>
                                    </x-filament::input.wrapper>
                                @else
                                    {{ $column['field'] !== null ? ($labels[$column['field']] ?? $column['field']) : 'Left out' }}
                                @endif
                            </td>
                            <td>
                                @if ($column['state'] === 'matched')
                                    <x-filament::badge color="success">Matched</x-filament::badge>
                                @elseif ($column['state'] === 'left_out')
                                    <x-filament::badge color="gray">Left out</x-filament::badge>
                                @else
                                    <x-filament::badge color="danger">Not recognised</x-filament::badge>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        @if ($editable)
            <div class="pos-di-actions">
                <span class="pos-di-card-sub">Fields marked * are required. A column left out is not read.</span>
                <div class="pos-di-actions-end"><x-filament::button wire:click="save" icon="heroicon-m-check">Save and check again</x-filament::button></div>
            </div>
        @endif
    </details>
</section>
