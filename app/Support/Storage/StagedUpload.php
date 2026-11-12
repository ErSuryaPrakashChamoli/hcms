<?php

namespace App\Support\Storage;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Production readiness closure: form uploads are staged on a configurable disk
 * (PEOPLEOS_STAGING_DISK), shared object storage when more than one node serves the panel, before the
 * owning domain stores them. Nothing assumes the staging disk is local.
 */
final class StagedUpload
{
    /** The folder every staged upload lands in (the forms' FileUpload ->directory()); nothing outside it is ever read or deleted. */
    public const DIRECTORY = 'tmp-uploads';

    public static function disk(): string
    {
        return (string) config('peopleos.storage.staging_disk', 'local');
    }

    public static function storage(): Filesystem
    {
        return Storage::disk(self::disk());
    }

    /**
     * A temporary local copy of a staged file as an UploadedFile, whatever the staging disk. The caller
     * removes it with self::discard().
     */
    public static function toUploadedFile(string $path): UploadedFile
    {
        self::assertStaged($path);
        $disk = self::storage();
        $tmp = tempnam(sys_get_temp_dir(), 'peopleos-upload-');
        $in = $disk->readStream($path) ?: throw new \RuntimeException('The uploaded file is no longer available.');
        $out = fopen($tmp, 'wb');
        stream_copy_to_stream($in, $out);
        fclose($out);
        is_resource($in) && fclose($in);

        return new UploadedFile($tmp, basename($path), $disk->mimeType($path) ?: null, null, true);
    }

    /** Remove the staged object and any temporary local copy. */
    public static function discard(string $path, ?UploadedFile $copy = null): void
    {
        self::assertStaged($path);
        self::storage()->delete($path);
        if ($copy !== null && is_file($copy->getPathname())) {
            @unlink($copy->getPathname());
        }
    }

    /**
     * QA audit 2026-11-12: the path comes from a form field, which the browser can set to any stored file (another
     * organisation's report, someone's attachment). Only a file in the staging folder is a staged upload.
     */
    private static function assertStaged(string $path): void
    {
        if (! str_starts_with($path, self::DIRECTORY.'/') || str_contains($path, '..')) {
            throw new \RuntimeException('The uploaded file is no longer available.');
        }
    }
}
