<?php

namespace App\Domain\Letters\Services;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Experience\Services\ScreenAccess;
use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Identity\Models\User;
use App\Domain\Letters\Jobs\BuildLetterDownload;
use App\Domain\Letters\Models\Letter;
use App\Domain\Letters\Models\LetterDownload;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * HROne parity WO-13 ("Bulk download", §23.7.1 #6): issued letters as one zip, prepared by a queued job.
 *
 * - Asked for by someone who reads letters (letter.view / letter.issue / letter.manage), for at most
 *   `peopleos.letters.bulk_download_max` letters (the rows chosen, or every letter matching the register's filters).
 * - The job runs as that person: only letters still issued, inside their access scope and passing the letter's `view`
 *   policy go in (the rest are counted as skipped); each letter put in the zip is audited as a DOWNLOAD.
 * - The zip is kept on the documents disk for `peopleos.letters.download_hours` and fetched only by the person who asked,
 *   through a signed link (audited); `peopleos:letters:tick` removes expired zips.
 */
final class LetterDownloads
{
    public function __construct(private readonly AuditRecorder $audit, private readonly Letters $letters) {}

    public static function mayDownload(?User $user): bool
    {
        return $user !== null && ($user->hasPermission('letter.view') || $user->hasPermission('letter.issue') || $user->hasPermission('letter.manage'));
    }

    public static function max(): int
    {
        return max(1, (int) config('peopleos.letters.bulk_download_max', 500));
    }

    /** @param  list<int|string>  $letterIds */
    public function request(User $actor, array $letterIds, string $selection = 'selected'): LetterDownload
    {
        if (! self::mayDownload($actor)) {
            throw new RuntimeException('You cannot download letters.');
        }
        $ids = array_values(array_unique(array_map('intval', array_filter($letterIds, 'is_numeric'))));
        if ($ids === []) {
            throw new RuntimeException('Choose at least one issued letter.');
        }
        if (count($ids) > self::max()) {
            throw new RuntimeException('At most '.self::max().' letters in one download. Narrow the register first.');
        }
        $download = LetterDownload::create(['requested_by' => $actor->id, 'letter_ids' => $ids, 'selection' => in_array($selection, ['selected', 'all_matching'], true) ? $selection : 'selected',
            'status' => 'queued', 'total' => count($ids)]);
        BuildLetterDownload::dispatch($download->id)->afterCommit();

        return $download->refresh();
    }

    /** The job: build the zip as the person who asked. */
    public function build(LetterDownload $download): LetterDownload
    {
        $claimed = LetterDownload::query()->whereKey($download->id)->where('status', 'queued')->update(['status' => 'running', 'updated_at' => now()]);
        if ($claimed !== 1) {
            return $download->refresh();
        }
        $download->refresh();
        $actor = User::query()->whereKey($download->requested_by)->where('tenant_id', $download->tenant_id)->first();
        if ($actor === null || $actor->status !== UserStatus::Active || ! self::mayDownload($actor)) {
            return $this->finish($download, 'failed', error: 'The person who asked can no longer download letters.');
        }

        $path = null;
        $tmp = (string) tempnam(sys_get_temp_dir(), 'peopleos-letters-');
        try {
            $included = ScreenAccess::as($actor, function () use ($download, $actor, $tmp) {
                $zip = new ZipArchive;
                if ($zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                    throw new RuntimeException('The download could not be prepared.');
                }
                $names = [];
                $count = 0;
                Letter::query()->with('employee.person')->whereKey((array) $download->letter_ids)->where('status', 'issued')->orderBy('id')
                    ->each(function (Letter $letter) use ($zip, $actor, $download, &$names, &$count) {
                        if (Gate::forUser($actor)->denies('view', $letter)) {
                            return;
                        }
                        try {
                            $file = $this->letters->file($letter);
                        } catch (RuntimeException) {
                            return; // the Word file is missing: skipped, never a broken entry
                        }
                        $base = trim((string) preg_replace('~[\\\\/:*?"<>|\x00-\x1F]+~', '-', $letter->number.' '.$letter->employee?->employee_code.' '.$letter->employee?->person?->full_name), ' .-') ?: 'Letter';
                        $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
                        $name = $base.'.'.$extension;
                        for ($i = 2; isset($names[mb_strtolower($name)]); $i++) {
                            $name = "{$base} ({$i}).{$extension}";
                        }
                        $names[mb_strtolower($name)] = true;
                        $zip->addFromString($name, $file['content']);
                        $this->audit->record(AuditAction::Download, 'letters', $letter, [], 'bulk_download', actor: $actor, metadata: ['number' => $letter->number, 'purpose' => 'bulk_download', 'letter_download' => $download->id]);
                        $count++;
                    });
                $zip->close();

                return $count;
            });
            if ($included === 0) {
                return $this->finish($download, 'failed', skipped: $download->total, error: 'None of the chosen letters could be included: only issued letters you may open are downloaded.');
            }
            $path = Letters::directory('downloads').'/'.Str::ulid().'.zip';
            $disk = (string) config('peopleos.documents.disk');
            Storage::disk($disk)->put($path, (string) file_get_contents($tmp));

            $download = $this->finish($download, 'ready', $included, max(0, $download->total - $included), disk: $disk, path: $path);
        } catch (Throwable $e) {
            if ($path !== null) {
                Storage::disk((string) config('peopleos.documents.disk'))->delete($path);
            }
            throw $e;
        } finally {
            @unlink($tmp);
        }
        $this->notify($actor, $download);

        return $download;
    }

    /**
     * The prepared zip for the person who asked for it (audited).
     *
     * @return array{content: string, name: string}
     */
    public function fetch(LetterDownload $download, User $user): array
    {
        if ((int) $download->requested_by !== (int) $user->id || ! self::mayDownload($user)) {
            throw new RuntimeException('Only the person who asked for this download may fetch it.');
        }
        if (! $download->isReady()) {
            throw new RuntimeException('This download is not ready or has expired.');
        }
        $disk = Storage::disk($download->file_disk ?: (string) config('peopleos.documents.disk'));
        if (! $disk->exists((string) $download->file_path)) {
            throw new RuntimeException('This download has expired.');
        }
        $this->audit->record(AuditAction::Download, 'letters', $download, [], null, actor: $user, metadata: ['letters' => $download->included, 'purpose' => 'bulk_download']);

        return ['content' => (string) $disk->get((string) $download->file_path), 'name' => 'letters-'.$download->created_at?->format('Ymd-His').'.zip'];
    }

    public static function url(LetterDownload $download): string
    {
        return URL::temporarySignedRoute('letters.downloads.zip', $download->expires_at ?? now()->addHour(), ['download' => $download->id]);
    }

    /** Expired zips are removed (the row stays as the record of the download). */
    public function prune(): int
    {
        $count = 0;
        LetterDownload::query()->whereIn('status', ['ready'])->where('expires_at', '<', now())->each(function (LetterDownload $download) use (&$count) {
            if (filled($download->file_path)) {
                Storage::disk($download->file_disk ?: (string) config('peopleos.documents.disk'))->delete($download->file_path);
            }
            $download->withoutAuditing()->update(['status' => 'expired', 'file_path' => null]);
            $count++;
        });

        return $count;
    }

    public function markFailed(int $downloadId, string $error): void
    {
        LetterDownload::query()->whereKey($downloadId)->whereIn('status', ['queued', 'running'])->first()
            ?->withoutAuditing()->update(['status' => 'failed', 'error' => mb_substr($error, 0, 500)]);
    }

    private function finish(LetterDownload $download, string $status, int $included = 0, int $skipped = 0, ?string $disk = null, ?string $path = null, ?string $error = null): LetterDownload
    {
        $download->withoutAuditing()->update(['status' => $status, 'included' => $included, 'skipped' => $skipped, 'file_disk' => $disk, 'file_path' => $path, 'error' => $error,
            'expires_at' => $status === 'ready' ? now()->addHours(max(1, (int) config('peopleos.letters.download_hours', 24))) : null]);

        return $download;
    }

    private function notify(User $actor, LetterDownload $download): void
    {
        rescue(function () use ($actor, $download) {
            $actor->notifyNow(Notification::make()->title('Letters ready to download')
                ->body($download->included.' letter(s) in one zip'.($download->skipped > 0 ? '; '.$download->skipped.' not included (not issued, or not yours to open)' : '').'. The link works until '.$download->expires_at?->format('d M Y H:i').'.')
                ->icon('heroicon-o-arrow-down-tray')->iconColor($download->skipped > 0 ? 'warning' : 'success')
                ->actions([Action::make('download')->label('Download zip')->url(self::url($download))])->toDatabase());
        }, report: true);
    }
}
