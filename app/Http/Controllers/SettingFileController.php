<?php

namespace App\Http\Controllers;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Configuration\Services\SettingFiles;
use App\Domain\Platform\Models\TenantSetting;
use App\Domain\Platform\Services\SettingsRepository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * HROne setup parity CA-1: the file a Setup setting holds (e.g. the organisation's logo). The request passes: signed link
 * → authenticated user → tenant (the setting is read in the signed-in person's organisation, so another tenant's file is
 * never reachable) → the setting's own rule (settings.view, or anyone for a public setting) → ATTACHMENT_DOWNLOADED audit
 * → stream from the private documents disk.
 */
class SettingFileController extends Controller
{
    public function __invoke(Request $request, string $key, SettingsRepository $settings, SettingFiles $files, AuditRecorder $audit): StreamedResponse
    {
        abort_unless($request->hasValidSignature(), 403);
        $setting = SettingFiles::declared($key) ?? abort(404);
        abort_unless(SettingFiles::canDownload($request->user(), $setting), 403);

        $path = $settings->get($key);
        $disk = Storage::disk(SettingFiles::disk());
        abort_unless(is_string($path) && str_starts_with($path, $files->directory().'/') && $disk->exists($path), 404);

        $audit->record(AuditAction::AttachmentDownloaded, 'configuration', TenantSetting::query()->where('key', $key)->first(), metadata: ['setting' => $key, 'file' => basename($path)],
            reason: 'setting file download', entityLabel: "Setting {$key}");

        return $disk->download($path, basename($path), ['Cache-Control' => 'private, max-age=86400']);
    }
}
