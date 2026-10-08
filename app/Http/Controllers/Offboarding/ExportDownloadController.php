<?php

namespace App\Http\Controllers\Offboarding;

use App\Domain\Offboarding\Services\OffboardingDirectory;
use App\Domain\Offboarding\Services\TenantExports;
use App\Domain\Platform\Support\LifecycleRefused;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * SaaS.9: streams an export package. Reached only through a signed link that expires in minutes and names the person it
 * was made for: the link, the signed-in person and their rights (TenantExports::authorize) must all agree. Never a public
 * or permanent URL; the file stays on the private disk. Every download is recorded and audited.
 */
class ExportDownloadController
{
    public function __invoke(Request $request, string $reference, TenantExports $exports, OffboardingDirectory $directory): StreamedResponse
    {
        $user = $request->user();
        abort_unless($user !== null && (int) $request->query('u') === (int) $user->getKey(), 403);
        $export = $directory->exportByReference($reference);
        abort_if($export === null || ! $export->downloadable(), 404);
        abort_unless(Storage::disk($export->disk)->exists($export->path), 404);
        try {
            $exports->recordDownload($export, $user);
        } catch (LifecycleRefused) {
            abort(403);
        }

        return Storage::disk($export->disk)->download($export->path, "peopleos-export-{$export->reference}.zip", ['Cache-Control' => 'no-store, private']);
    }
}
