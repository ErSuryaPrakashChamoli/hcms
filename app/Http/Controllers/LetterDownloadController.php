<?php

namespace App\Http\Controllers;

use App\Domain\Letters\Models\LetterDownload;
use App\Domain\Letters\Services\LetterDownloads;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * HROne parity WO-13: a prepared bulk letter download. Signed link → the full protected chain → the person who asked for
 * it, still allowed to read letters → audited (LetterDownloads::fetch).
 */
class LetterDownloadController extends Controller
{
    public function __invoke(Request $request, int $download, LetterDownloads $downloads): StreamedResponse
    {
        abort_unless($request->hasValidSignature(), 403);
        $record = LetterDownload::query()->findOrFail($download);
        try {
            $file = $downloads->fetch($record, $request->user());
        } catch (RuntimeException $e) {
            abort(403, $e->getMessage());
        }

        return response()->streamDownload(fn () => print ($file['content']), $file['name'], ['Content-Type' => 'application/zip']);
    }
}
