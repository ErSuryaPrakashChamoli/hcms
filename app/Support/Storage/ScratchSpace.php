<?php

namespace App\Support\Storage;

/**
 * Local working folders for files that exist only while a job or a download runs: a master upload's temporary sheets,
 * an error or template workbook on its way to the browser, an offboarding export being packaged. They are under
 * storage/app unless `peopleos.storage.scratch_path` names another folder.
 *
 * Tests point it at their own per-process folder (tests/TestCase::isolateLocalDisks), so a test run never writes into
 * the application's storage/app, which also holds preserved evidence (remediation I.10, 2026-11-25).
 */
final class ScratchSpace
{
    /** The working folder, or a path inside it. */
    public static function path(string $relative = ''): string
    {
        $root = rtrim((string) (config('peopleos.storage.scratch_path') ?: storage_path('app')), '/');

        return $relative === '' ? $root : $root.'/'.ltrim($relative, '/');
    }
}
