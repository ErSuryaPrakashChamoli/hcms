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
        self::storage()->delete($path);
        if ($copy !== null && is_file($copy->getPathname())) {
            @unlink($copy->getPathname());
        }
    }
}
