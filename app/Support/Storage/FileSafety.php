<?php

namespace App\Support\Storage;

use RuntimeException;

/**
 * Production readiness closure: server-side checks for every stored employee file (documents, case
 * attachments, grievance evidence), whatever the client claimed:
 * - the extension is on the document allowlist (`peopleos.documents.mimes`);
 * - the size is within `peopleos.documents.max_kb`;
 * - the sniffed content is not active or executable content (HTML, SVG, scripts, binaries), even under
 *   an allowed extension.
 */
final class FileSafety
{
    /** Content types never stored, whatever the extension says. */
    public const REFUSED_CONTENT = [
        'text/html', 'application/xhtml+xml', 'image/svg+xml', 'text/javascript', 'application/javascript', 'application/x-javascript',
        'text/x-php', 'application/x-php', 'application/x-httpd-php', 'text/x-shellscript', 'application/x-sh', 'application/x-executable',
        'application/x-msdownload', 'application/x-dosexec', 'application/vnd.microsoft.portable-executable', 'application/x-mach-binary', 'application/java-archive',
    ];

    /** @return string the safe, lower-case extension */
    public static function assertAllowed(string $originalName, int $bytes, ?string $sniffedMime): string
    {
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $allowed = (array) config('peopleos.documents.mimes', []);
        if ($extension === '' || ! in_array($extension, $allowed, true)) {
            throw new RuntimeException('That file type is not accepted ('.implode(', ', $allowed).').');
        }
        if ($bytes <= 0) {
            throw new RuntimeException('The file is empty.');
        }
        if ($bytes > 1024 * (int) config('peopleos.documents.max_kb', 10240)) {
            throw new RuntimeException('The file is too large.');
        }
        if ($sniffedMime !== null && in_array(strtolower($sniffedMime), self::REFUSED_CONTENT, true)) {
            throw new RuntimeException('The file content is not an accepted document type.');
        }

        return $extension;
    }

    /** Sniff the bytes on disk (never the client's or the framework's reported type). */
    public static function sniffFile(string $path): ?string
    {
        return is_file($path) ? ((new \finfo(FILEINFO_MIME_TYPE))->file($path) ?: null) : null;
    }

    public static function sniff(string $contents): ?string
    {
        return (new \finfo(FILEINFO_MIME_TYPE))->buffer($contents) ?: null;
    }
}
