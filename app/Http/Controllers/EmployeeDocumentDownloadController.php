<?php

namespace App\Http\Controllers;

use App\Domain\Documents\Models\EmployeeDocumentDownload;
use App\Domain\Documents\Services\EmployeeDocumentDownloads;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * HROne parity WO-4: a prepared "Download all documents" zip of one employee. Signed link → the full protected chain → the
 * person who asked for it, still allowed to read documents → audited (EmployeeDocumentDownloads::fetch).
 */
class EmployeeDocumentDownloadController extends Controller
{
    public function __invoke(Request $request, int $download, EmployeeDocumentDownloads $downloads): StreamedResponse
    {
        abort_unless($request->hasValidSignature(), 403);
        $record = EmployeeDocumentDownload::query()->findOrFail($download);
        try {
            $file = $downloads->fetch($record, $request->user());
        } catch (RuntimeException $e) {
            abort(403, $e->getMessage());
        }

        return response()->streamDownload(fn () => print ($file['content']), $file['name'], ['Content-Type' => 'application/zip']);
    }
}
