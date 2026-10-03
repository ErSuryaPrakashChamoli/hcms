<?php

namespace App\Http\Controllers;

use App\Domain\Learning\Models\LearningCertificate;
use App\Domain\Learning\Services\Certificates;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Phase 8: authenticated request → tenant → signed URL → policy → audited download from the private disk. */
class LearningCertificateDownloadController extends Controller
{
    public function __invoke(Request $request, int $certificate, Certificates $certificates): StreamedResponse
    {
        abort_unless($request->hasValidSignature(), 403);

        // Resolved after ResolveTenant, so ids from other tenants fail closed (404).
        $certificate = LearningCertificate::query()->findOrFail($certificate);
        Gate::authorize('view', $certificate);
        abort_if($certificate->document_path === null, 404);

        $certificates->recordDownload($certificate, $request->user());

        return Storage::disk(Certificates::disk())->download($certificate->document_path, $certificate->document_name ?? 'certificate.pdf');
    }
}
