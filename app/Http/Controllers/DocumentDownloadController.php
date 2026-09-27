<?php

namespace App\Http\Controllers;

use App\Domain\Documents\Models\EmployeeDocument;
use App\Domain\Documents\Services\Documents;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Authenticated request -> authorisation -> signed URL -> secure download (§83). */
class DocumentDownloadController extends Controller
{
    public function __invoke(Request $request, EmployeeDocument $document, Documents $documents): StreamedResponse
    {
        abort_unless($request->hasValidSignature(), 403);
        Gate::authorize('view', $document);

        $documents->recordAccess($document, (string) $request->query('purpose', 'download'));

        return Storage::disk($document->disk)->download($document->path, $document->original_name);
    }
}
