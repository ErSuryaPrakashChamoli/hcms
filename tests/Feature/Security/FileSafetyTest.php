<?php

use App\Support\Storage\FileSafety;

/*
| QA: the server-side gate every stored employee file passes (documents, case attachments, grievance evidence). The
| extension must be on the allow-list, the size within the limit, and the sniffed bytes must not be active content —
| whatever name or type the browser claimed.
*/

it('accepts an allowed document and returns its lower-case extension', function () {
    expect(FileSafety::assertAllowed('Offer Letter.PDF', 2048, 'application/pdf'))->toBe('pdf')
        ->and(FileSafety::assertAllowed('photo.jpeg', 10, 'image/jpeg'))->toBe('jpeg');
});

it('refuses a file without an extension, with an extension off the allow-list, or hiding behind a double extension', function (string $name) {
    expect(fn () => FileSafety::assertAllowed($name, 100, 'application/pdf'))->toThrow(RuntimeException::class, 'not accepted');
})->with(['no-extension', 'payload.php', 'page.html', 'drawing.svg', 'archive.zip', 'letter.pdf.exe', 'trailing-dot.']);

it('refuses an empty file and one over the size limit', function () {
    config(['peopleos.documents.max_kb' => 1]);

    expect(fn () => FileSafety::assertAllowed('a.pdf', 0, 'application/pdf'))->toThrow(RuntimeException::class, 'empty')
        ->and(fn () => FileSafety::assertAllowed('a.pdf', 1025, 'application/pdf'))->toThrow(RuntimeException::class, 'too large')
        ->and(FileSafety::assertAllowed('a.pdf', 1024, 'application/pdf'))->toBe('pdf');
});

it('refuses active content disguised under an allowed extension, judged by the bytes', function (string $bytes) {
    $sniffed = FileSafety::sniff($bytes);

    expect(fn () => FileSafety::assertAllowed('innocent.pdf', strlen($bytes), $sniffed))
        ->toThrow(RuntimeException::class, 'not an accepted document type');
})->with([
    'html' => '<html><body><script>alert(1)</script></body></html>',
    'html with a byte-order mark' => "\xEF\xBB\xBF<!DOCTYPE html><html><script>alert(1)</script>",
    'bare script tag' => '<script>alert(1)</script>',
    'svg' => '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"/>',
    'svg with an xml prolog' => '<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>',
    'php' => '<?php system($_GET["c"]);',
    'shell script' => "#!/bin/sh\nrm -rf /",
]);

it('sniffs the bytes on disk, and reports nothing for a missing file', function () {
    $path = tempnam(sys_get_temp_dir(), 'qa');
    file_put_contents($path, '%PDF-1.4 minimal');

    try {
        expect(FileSafety::sniffFile($path))->toBe('application/pdf')
            ->and(FileSafety::sniffFile($path.'-missing'))->toBeNull();
    } finally {
        unlink($path);
    }
});
