<?php

namespace App\Livewire\Imports;

use App\Domain\Employment\Imports\EmployeeImport;
use App\Domain\Employment\Imports\ImportGovernance;
use App\Domain\Employment\Imports\ImportTypes;
use App\Domain\Identity\Models\User;
use Filament\Notifications\Notification;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;
use RuntimeException;
use Throwable;

/**
 * "Columns in your file" (owner request 2026-11-26): after an upload, every column of the file with the field PeopleOS
 * read it as, an example value, and a choice of field. A column whose heading PeopleOS does not recognise is flagged
 * ("Not recognised") so the person can say which field it holds or leave it out, instead of it being dropped silently;
 * required fields no column gives are listed. Saving maps the file again (with the upload's own options: mode, month,
 * company …) and checks every row again; the host page refreshes on `upload-rechecked`.
 *
 * Only the person who may prepare the upload changes it (the upload's own right, and under maker-checker its uploader),
 * and only before it is approved; everyone else who can open the upload (an approver, say) sees the columns read-only.
 *
 *     @livewire(\App\Livewire\Imports\ColumnMatch::class, ['importId' => $import->id], key('cols-'.$import->id))
 */
class ColumnMatch extends Component
{
    #[Locked]
    public int $importId;

    /** Options passed to the upload's map() (the Data import page's intent); empty keeps the upload's own. */
    #[Locked]
    public array $options = [];

    /** @var array<string, string|null> column index => field (null: left out) */
    public array $mapping = [];

    public ?string $problem = null;

    /** @param  array<string, mixed>  $options */
    public function mount(int $importId, array $options = []): void
    {
        $this->importId = $importId;
        $this->options = $options;
        $import = $this->import();
        $this->mapping = collect($import->headers ?? [])->mapWithKeys(fn ($header, $i) => [(string) $i => $import->mapping[$header] ?? null])->all();
    }

    /** Map the file with the chosen fields and check every row again. */
    public function save(): void
    {
        $import = $this->import();
        if (! $this->editable($import)) {
            return;
        }
        $fields = $this->fields($import);
        $byHeader = [];
        foreach ($import->headers ?? [] as $i => $header) {
            $field = $this->mapping[(string) $i] ?? null;
            $byHeader[$header] = $field !== null && $field !== '' && isset($fields[$field]) ? $field : null;
        }
        $service = ImportTypes::service($import->type);
        try {
            $service->map($import, $byHeader, $this->options);
            $service->validate($import->refresh());
        } catch (RuntimeException $e) {
            $this->problem = $e->getMessage();

            return;
        } catch (Throwable $e) {
            report($e);
            $this->problem = 'The file could not be checked again. Try once more, or upload it again.';

            return;
        }
        $this->problem = null;
        Notification::make()->success()->title('Columns saved; the file was checked again')->send();
        $this->dispatch('upload-rechecked', importId: $this->importId);
    }

    public function render(): View
    {
        $import = $this->import();
        $fields = $this->fields($import);
        $service = ImportTypes::service($import->type);
        $headers = $import->headers ?? [];
        $recognised = method_exists($service, 'suggestMapping') ? $service->suggestMapping($headers) : [];
        $first = $import->rows()->orderBy('row_number')->value('data') ?? [];

        $columns = [];
        foreach ($headers as $i => $header) {
            $chosen = $this->mapping[(string) $i] ?? null;
            $columns[] = [
                'index' => (string) $i,
                'header' => (string) $header,
                'example' => is_scalar($first[$header] ?? null) ? (string) $first[$header] : '',
                'field' => $chosen,
                // Not recognised: PeopleOS could not tell which field the heading means, and no field is chosen for it.
                'state' => $chosen !== null && $chosen !== '' ? 'matched' : (isset($recognised[$header]) ? 'left_out' : 'unknown'),
            ];
        }
        $chosen = array_filter(array_values($this->mapping));
        $missing = array_values(array_map(fn (array $def) => $def[0], array_filter($fields, fn (array $def, string $field) => $def[1] && ! in_array($field, $chosen, true), ARRAY_FILTER_USE_BOTH)));
        $unknown = count(array_filter($columns, fn (array $c) => $c['state'] === 'unknown'));

        return view('livewire.imports.column-match', [
            'columns' => $columns,
            'fields' => array_map(fn (array $def) => $def[0].($def[1] ? ' *' : ''), $fields),
            'labels' => array_map(fn (array $def) => $def[0], $fields),
            'missing' => $missing,
            'unknown' => $unknown,
            'editable' => $this->editable($import),
            'open' => $unknown > 0 || $missing !== [] || $this->problem !== null,
        ]);
    }

    /** @return array<string, array{0: string, 1: bool}> */
    private function fields(EmployeeImport $import): array
    {
        return ImportTypes::service($import->type)::fields($import);
    }

    private function editable(EmployeeImport $import): bool
    {
        $user = $this->actor();

        // A worksheet's columns follow its workbook's template: they are changed by uploading the workbook again.
        return ! $import->isSheet() && in_array($import->status, ['inspected', 'mapped', 'validated'], true)
            && ImportTypes::allowed($user, (string) $import->type)
            && (! ImportGovernance::checkedByAnother((string) $import->type) || (int) $import->uploaded_by === (int) $user->getKey());
    }

    private function import(): EmployeeImport
    {
        // Tenant-scoped: an upload of another organisation is not found. Seeing the columns is part of seeing the upload (the
        // host page decides who may open it); changing them needs the upload's own right (editable()).
        $this->actor();

        return EmployeeImport::query()->findOrFail($this->importId);
    }

    private function actor(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
