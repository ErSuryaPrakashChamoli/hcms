<?php

namespace App\Domain\Configuration\Services;

use App\Domain\Identity\Models\User;
use App\Domain\Platform\Services\SettingsRepository;
use App\Support\Storage\FileSafety;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use RuntimeException;

/**
 * Files held by Setup settings of type `file` (HROne setup parity CA-1), e.g. the organisation's logo. They live on the
 * private documents disk under tenants/{id}/settings and are only ever served through SettingFileController: a signed
 * link, the signed-in person of the same organisation (settings.view, or anyone for a `public` setting such as the
 * logo), an audit event, then the stream. Nothing builds a direct storage URL.
 */
final class SettingFiles
{
    /** Image types a setting that accepts images may hold (sniffed from the bytes, never the name). */
    public const IMAGE_MIMES = ['image/png', 'image/jpeg'];

    public function __construct(private readonly TenantContext $tenants, private readonly SettingsRepository $settings) {}

    public static function disk(): string
    {
        return (string) config('peopleos.documents.disk', 'local');
    }

    public function directory(): string
    {
        $tenant = $this->tenants->id() ?? throw new RuntimeException('Setting files belong to an organisation.');

        return "tenants/{$tenant}/settings";
    }

    /** The declared `file` setting with this key, from any Setup module. @return array<string, mixed>|null */
    public static function declared(string $key): ?array
    {
        foreach ((array) config('peopleos_setup.modules', []) as $module) {
            foreach ((array) ($module['settings'] ?? []) as $setting) {
                if (($setting['key'] ?? null) === $key && ($setting['type'] ?? null) === 'file') {
                    return $setting;
                }
            }
        }

        return null;
    }

    /**
     * Checks a stored upload for a file setting: it must sit in this organisation's settings folder, exist, fit the size
     * limit and be what the setting accepts (PNG / JPEG images, or the document allowlist without active content).
     */
    public function assertValid(array $setting, string $path): string
    {
        $disk = Storage::disk(self::disk());
        if (! str_starts_with($path, $this->directory().'/') || str_contains($path, '..') || ! $disk->exists($path)) {
            throw new RuntimeException("{$setting['label']}: the uploaded file is not available.");
        }
        $bytes = (int) $disk->size($path);
        $maxKb = (int) ($setting['max_kb'] ?? config('peopleos.documents.max_kb', 10240));
        if ($bytes <= 0 || $bytes > $maxKb * 1024) {
            throw new RuntimeException("{$setting['label']} can be at most ".round($maxKb / 1024, 1).' MB.');
        }
        $mime = FileSafety::sniff((string) $disk->get($path));
        if (($setting['accept'] ?? 'document') === 'image') {
            if (! in_array($mime, self::IMAGE_MIMES, true)) {
                throw new RuntimeException("{$setting['label']} must be a PNG or JPEG image.");
            }
        } else {
            FileSafety::assertAllowed(basename($path), $bytes, $mime);
        }

        return $path;
    }

    /** Removes a file a setting no longer holds (only inside this organisation's settings folder). */
    public function discard(?string $path): void
    {
        if (filled($path) && str_starts_with((string) $path, $this->directory().'/')) {
            Storage::disk(self::disk())->delete((string) $path);
        }
    }

    /**
     * A signed link to the file the setting holds now, or null. The link stays the same for a day (so browsers cache an
     * image such as the logo) and changes when the file changes.
     */
    public function url(string $key): ?string
    {
        $path = $this->settings->get($key);
        if (! is_string($path) || $path === '' || self::declared($key) === null) {
            return null;
        }

        return URL::temporarySignedRoute('settings.file', today()->addDays(2), ['key' => $key, 'v' => substr(hash('sha256', $path), 0, 12)]);
    }

    /** May this person fetch the file of this setting? */
    public static function canDownload(?User $user, array $setting): bool
    {
        return $user !== null && (($setting['public'] ?? false) === true || $user->can('settings.view'));
    }
}
