<?php

namespace App\Http\Controllers;

use App\Domain\Expense\Models\ExpenseDocumentDownload;
use App\Domain\Expense\Services\ExpenseDocumentDownloads;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * HROne parity XO-3: a prepared bulk download of expense documents. Signed link → the full protected chain → the person
 * who asked for it, still seeing expense reports in scope → audited (ExpenseDocumentDownloads::fetch).
 */
class ExpenseDocumentDownloadController extends Controller
{
    public function __invoke(Request $request, int $download, ExpenseDocumentDownloads $downloads): StreamedResponse
    {
        abort_unless($request->hasValidSignature(), 403);
        $record = ExpenseDocumentDownload::query()->findOrFail($download);
        try {
            $file = $downloads->fetch($record, $request->user());
        } catch (RuntimeException $e) {
            abort(403, $e->getMessage());
        }

        return response()->streamDownload(fn () => print ($file['content']), $file['name'], ['Content-Type' => 'application/zip']);
    }
}
