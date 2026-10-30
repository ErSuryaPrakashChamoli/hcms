<?php

namespace App\Http\Controllers;

use App\Domain\Documents\Services\Documents;
use App\Domain\Experience\Services\MyProfileSections;
use App\Domain\Identity\Services\CurrentEmployee;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * HROne parity MS-2 (My profile → Documents → "Download all"): the signed-in person's own current documents that they may
 * open, as one ZIP built on the fly (Documents::bundle; one audit entry per file). No parameters: only ever their own
 * record, in their own organisation, after the full protected chain.
 */
class MyDocumentsZipController extends Controller
{
    public function __invoke(Request $request, Documents $documents, MyProfileSections $profile): BinaryFileResponse
    {
        $user = $request->user();
        $me = app(CurrentEmployee::class)->of($user);
        abort_if($me === null || ! $user->can('view', $me), 403);
        $chosen = $profile->downloadable($user, $me);
        abort_if($chosen->isEmpty(), 404);
        try {
            $bundle = $documents->bundle($user, $me, $chosen);
        } catch (RuntimeException $e) {
            abort(422, $e->getMessage());
        }
        abort_if($bundle === null, 404);

        return response()->download($bundle['path'], 'documents-'.preg_replace('/[^A-Za-z0-9_-]+/', '-', (string) $me->employee_code).'.zip', ['Content-Type' => 'application/zip'])
            ->deleteFileAfterSend();
    }
}
