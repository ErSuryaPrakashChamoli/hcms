<?php

namespace App\Filament\Support;

use App\Domain\Documents\Models\DocumentType;
use App\Domain\Documents\Services\Documents;
use App\Domain\Employment\Models\Employee;
use App\Support\Storage\StagedUpload;
use Closure;
use Filament\Forms\Components\FileUpload;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * A copy of an identifier uploaded beside it (owner, 2026-11-27: "give the option to upload these docs as well for record
 * purpose"): a government ID's copy on the Government IDs tab, the PAN card and Aadhaar on "Edit statutory details". The
 * copy is filed with the employee's documents as the document type linked to the field (DocumentType::forField(); pending
 * until HR verifies it), before the identifier is saved, so a type "required with" its field is satisfied; both are one
 * transaction, and a stored file is removed again when the identifier is refused.
 */
final class DocumentCopies
{
    /** The upload field for one copy (shown only to people who may upload documents). */
    public static function upload(string $name, string $label, string|Closure $help): FileUpload
    {
        return FileUpload::make($name)->label($label)->disk(StagedUpload::disk())->directory('tmp-uploads')->maxSize(config('peopleos.documents.max_kb'))
            ->acceptedFileTypes(['application/pdf', 'image/jpeg', 'image/png'])
            ->helperText($help)
            ->visible(fn () => auth()->user()?->can('document.upload') ?? false);
    }

    /** The document type a field's copy is filed as: the type linked to the field, else the type with the fallback code. */
    public static function typeFor(string $field, ?string $code = null): ?DocumentType
    {
        return DocumentType::forField($field, $code);
    }

    /**
     * Stores the copies given in $data (taken out of it), then runs $save, in one transaction.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, array{field: string, code?: ?string, title: string, expires_on?: ?string, issued_on?: ?string}>  $copies  form field => where it is filed
     */
    public static function saveWith(Employee $employee, array &$data, array $copies, ?string $reason, Closure $save): mixed
    {
        $staged = [];
        foreach (array_keys($copies) as $name) {
            $value = $data[$name] ?? null;
            unset($data[$name]);
            $value = is_array($value) ? (array_values($value)[0] ?? null) : $value;
            if (filled($value)) {
                $staged[$name] = (string) $value;
            }
        }
        if ($staged === []) {
            return ProfileChangeActions::run($save);
        }
        $files = array_map(fn (string $path) => StagedUpload::toUploadedFile($path), $staged);
        $stored = [];
        try {
            return ProfileChangeActions::run(fn () => DB::transaction(function () use ($employee, $files, $copies, $reason, $save, &$stored) {
                foreach ($files as $name => $file) {
                    $copy = $copies[$name];
                    $type = self::typeFor($copy['field'], $copy['code'] ?? null);
                    $stored[] = app(Documents::class)->store($employee, $file, $type, $type?->name ?? $copy['title'], $copy['expires_on'] ?? null, $copy['issued_on'] ?? null,
                        $reason, uploadComment: 'Copy of the '.$copy['title']);
                }

                return $save();
            }));
        } catch (Throwable $e) {
            foreach ($stored as $document) {
                Storage::disk($document->disk)->delete($document->path);
            }

            throw $e;
        } finally {
            foreach ($staged as $name => $path) {
                StagedUpload::discard($path, $files[$name] ?? null);
            }
        }
    }
}
