<?php

namespace App\Http\Controllers\SignIn;

use App\Domain\Configuration\Services\SettingFiles;
use App\Filament\Support\SignInBranding;
use App\Http\Controllers\Controller;
use App\Support\Storage\FileSafety;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * Branded sign-in: the logo or sign-in picture of the organisation this browser remembers, for the signed-out sign-in
 * page. The link names no organisation: the encrypted workspace cookie picks it (SignInBranding::tenant), so a visitor
 * only ever gets the images of the organisation the browser already remembers, and only while its branded sign-in is on.
 *
 * Both images are public settings (any of the organisation's people may fetch them), and Setup stored them as checked
 * PNG / JPEG files. Fetches are not audited: every visit to the sign-in page loads them, so an audit event each would
 * flood the organisation's audit chain from a public page; the throttle on the route bounds the traffic instead.
 */
class BrandImageController extends Controller
{
    public function __invoke(string $asset, TenantContext $tenants): Response
    {
        abort_unless(array_key_exists($asset, SignInBranding::ASSETS), 404);
        $tenant = SignInBranding::tenant() ?? abort(404);

        return $tenants->runAs($tenant, function () use ($asset): Response {
            $path = SignInBranding::assetPath($asset) ?? abort(404);
            $disk = Storage::disk(SettingFiles::disk());
            $bytes = (string) $disk->get($path);
            $mime = FileSafety::sniff($bytes);
            abort_unless(in_array($mime, SettingFiles::IMAGE_MIMES, true), 404);

            return response($bytes, 200, [
                'Content-Type' => $mime,
                'Content-Disposition' => 'inline',
                'Cache-Control' => 'private, max-age=86400',
                'Vary' => 'Cookie',
                'X-Content-Type-Options' => 'nosniff',
            ]);
        });
    }
}
