<?php

namespace App\Filament\Support;

use App\Domain\Configuration\Services\SettingFiles;
use App\Domain\Identity\Services\RememberedWorkspace;
use App\Domain\Platform\Models\Tenant;
use App\Domain\Platform\Services\SettingsRepository;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Storage;

/**
 * Branded sign-in (HROne-style): the look of the sign-in page (and the other signed-out pages) for the organisation this
 * browser remembers (RememberedWorkspace): its name, logo, workspace colour and sign-in picture. Null — the PeopleOS
 * page — when someone is signed in, the browser remembers no open organisation, or the organisation turned it off
 * (Setup → Core HR → Sign-in page).
 *
 * The two images are served by SignInBrandingController from the same cookie: their links carry no organisation, so a
 * signed-out visitor can only ever see the look of the organisation the browser already remembers.
 */
final class SignInBranding
{
    /** The images of the sign-in page and the setting (a Setup file) each comes from. */
    public const ASSETS = ['logo' => 'branding.logo_path', 'picture' => 'branding.sign_in_picture_path'];

    private const MEMO = 'peopleos.sign_in_branding';

    /** @return array{name: string, logo: ?string, picture: ?string, colour: ?string}|null Worked out once per request. */
    public static function current(): ?array
    {
        $request = request();
        if (! $request->attributes->has(self::MEMO)) {
            $request->attributes->set(self::MEMO, self::resolve());
        }

        return $request->attributes->get(self::MEMO);
    }

    /** The organisation the sign-in page is branded for, or null (see current()). */
    public static function tenant(): ?Tenant
    {
        if (auth()->check()) {
            return null;
        }
        $tenant = app(RememberedWorkspace::class)->tenant();
        if ($tenant === null) {
            return null;
        }

        return app(TenantContext::class)->runAs($tenant, fn () => (bool) app(SettingsRepository::class)->get('branding.sign_in_page', true)) ? $tenant : null;
    }

    /**
     * The file behind a sign-in image, read in the organisation's own context: the path the setting holds, if it is inside
     * the organisation's settings folder and on the disk; otherwise null.
     */
    public static function assetPath(string $asset): ?string
    {
        $key = self::ASSETS[$asset] ?? null;
        if ($key === null) {
            return null;
        }
        $path = app(SettingsRepository::class)->get($key);
        $folder = app(SettingFiles::class)->directory().'/';

        return is_string($path) && str_starts_with($path, $folder) && ! str_contains($path, '..') && Storage::disk(SettingFiles::disk())->exists($path) ? $path : null;
    }

    /** @return array{name: string, logo: ?string, picture: ?string, colour: ?string}|null */
    private static function resolve(): ?array
    {
        $tenant = self::tenant();
        if ($tenant === null) {
            return null;
        }

        return app(TenantContext::class)->runAs($tenant, fn () => [
            'name' => (string) (app(SettingsRepository::class)->get('branding.display_name') ?: $tenant->name),
            'logo' => self::assetUrl('logo'),
            'picture' => self::assetUrl('picture'),
            'colour' => TenantBranding::themeColour(),
        ]);
    }

    /** The image's link (it changes when the file changes, so browsers may keep it for a day), or null without a file. */
    private static function assetUrl(string $asset): ?string
    {
        $path = self::assetPath($asset);

        return $path === null ? null : route('sign-in.brand', ['asset' => $asset, 'v' => substr(hash('sha256', $path), 0, 12)]);
    }
}
