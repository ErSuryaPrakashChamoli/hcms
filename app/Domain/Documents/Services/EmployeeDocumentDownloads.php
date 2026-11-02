<?php

namespace App\Domain\Documents\Services;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Documents\Jobs\BuildEmployeeDocumentDownload;
use App\Domain\Documents\Models\EmployeeDocument;
use App\Domain\Documents\Models\EmployeeDocumentDownload;
use App\Domain\Employment\Models\Employee;
use App\Domain\Experience\Services\ScreenAccess;
use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Scopes\AccessScope;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * HROne parity WO-4 (§23.3.1 #11, "download all documents"; the WO-13 LetterDownloads / XO-3 ExpenseDocumentDownloads
 * pattern): every current document of one employee as one zip, prepared by a queued job.
 *
 * - Asked for by someone who reads employee documents (document.view) and may open the employee.
 * - The job runs as that person (ScreenAccess::as): only documents their access returns and the document's `view` policy
 *   allows go in (archived ones never); each document put in the zip is audited as a DOWNLOAD (purpose joiner_download_all,
 *   Documents::bundle); documents left out are counted as skipped.
 * - The zip is kept on the documents disk under the tenant's employees folder for `peopleos.lifecycle.joiners.download_hours`,
 *   fetched only by the person who asked, through a signed link (audited); `peopleos:onboarding:start-due` removes expired
 *   zips daily (the row stays as the record of the download).
 */
final class EmployeeDocumentDownloads
{
    public const PURPOSE = 'joiner_download_all';

    public function __construct(private readonly AuditRecorder $audit, private readonly Documents $documents) {}

    public static function mayDownload(?User $user): bool
    {
        return $user !== null && $user->hasPermission('document.view');
    }

    /** The employee's current documents (archived versions left out). */
    public static function current(Employee $employee)
    {
        return EmployeeDocument::query()->where('employee_id', $employee->id)->where('status', '!=', 'archived');
    }

    public function request(User $actor, Employee $employee): EmployeeDocumentDownload
    {
        if (! self::mayDownload($actor) || Gate::forUser($actor)->denies('view', $employee)) {
            throw new RuntimeException('You cannot download this employee\'s documents.');
        }
        $total = self::current($employee)->count();
        if ($total === 0) {
            throw new RuntimeException('There are no documents to download.');
        }
        $download = EmployeeDocumentDownload::create(['employee_id' => $employee->id, 'requested_by' => $actor->id, 'status' => 'queued', 'total' => $total]);
        BuildEmployeeDocumentDownload::dispatch($download->id)->afterCommit();

        return $download->refresh();
    }

    /** The job: build the zip as the person who asked. */
    public function build(EmployeeDocumentDownload $download): EmployeeDocumentDownload
    {
        $claimed = EmployeeDocumentDownload::query()->whereKey($download->id)->where('status', 'queued')->update(['status' => 'running', 'updated_at' => now()]);
        if ($claimed !== 1) {
            return $download->refresh();
        }
        $download->refresh();
        $actor = User::query()->whereKey($download->requested_by)->where('tenant_id', $download->tenant_id)->first();
        if ($actor === null || $actor->status !== UserStatus::Active || ! self::mayDownload($actor)) {
            return $this->finish($download, 'failed', error: 'The person who asked can no longer download employee documents.');
        }

        $path = null;
        try {
            try {
                $bundle = ScreenAccess::as($actor, function () use ($download, $actor) {
                    $employee = Employee::query()->find($download->employee_id);
                    if ($employee === null || Gate::forUser($actor)->denies('view', $employee)) {
                        return null;
                    }

                    return $this->documents->bundle($actor, $employee, self::current($employee)->with('type')->orderBy('id')->get(), self::PURPOSE, ['employee_document_download' => $download->id]);
                });
            } catch (RuntimeException $e) {
                return $this->finish($download, 'failed', skipped: $download->total, error: $e->getMessage()); // e.g. too large together
            }
            if ($bundle === null) {
                return $this->finish($download, 'failed', skipped: $download->total, error: 'None of the documents could be included: only documents you may open are downloaded.');
            }
            try {
                $path = 'tenants/'.$download->tenant_id.'/employees/downloads/'.Str::ulid().'.zip';
                $disk = (string) config('peopleos.documents.disk');
                $stream = fopen($bundle['path'], 'rb');
                Storage::disk($disk)->put($path, $stream);
                is_resource($stream) && fclose($stream);
            } finally {
                @unlink($bundle['path']);
            }

            $download = $this->finish($download, 'ready', $bundle['count'], max(0, $download->total - $bundle['count']), disk: $disk, path: $path);
        } catch (Throwable $e) {
            if ($path !== null) {
                Storage::disk((string) config('peopleos.documents.disk'))->delete($path);
            }
            throw $e;
        }
        $this->notify($actor, $download);

        return $download;
    }

    /**
     * The prepared zip for the person who asked for it (audited).
     *
     * @return array{content: string, name: string}
     */
    public function fetch(EmployeeDocumentDownload $download, ?User $user): array
    {
        if ($user === null || (int) $download->requested_by !== (int) $user->id || ! self::mayDownload($user)) {
            throw new RuntimeException('Only the person who asked for this download may fetch it.');
        }
        if (! $download->isReady()) {
            throw new RuntimeException('This download is not ready or has expired.');
        }
        $disk = Storage::disk($download->file_disk ?: (string) config('peopleos.documents.disk'));
        if (! $disk->exists((string) $download->file_path)) {
            throw new RuntimeException('This download has expired.');
        }
        $code = Employee::query()->withoutGlobalScope(AccessScope::class)->whereKey($download->employee_id)->value('employee_code');
        $this->audit->record(AuditAction::Download, 'documents', $download, [], null, actor: $user, metadata: ['documents' => $download->included, 'employee_id' => $download->employee_id, 'purpose' => self::PURPOSE]);

        return ['content' => (string) $disk->get((string) $download->file_path), 'name' => 'documents-'.preg_replace('/[^A-Za-z0-9_-]+/', '-', (string) $code).'-'.$download->created_at?->format('Ymd-His').'.zip'];
    }

    public static function url(EmployeeDocumentDownload $download): string
    {
        return URL::temporarySignedRoute('employees.documents.zip', $download->expires_at ?? now()->addHour(), ['download' => $download->id]);
    }

    /** Expired zips are removed (the row stays as the record of the download). */
    public function prune(): int
    {
        $count = 0;
        EmployeeDocumentDownload::query()->where('status', 'ready')->where('expires_at', '<', now())->each(function (EmployeeDocumentDownload $download) use (&$count) {
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
        EmployeeDocumentDownload::query()->whereKey($downloadId)->whereIn('status', ['queued', 'running'])->first()
            ?->withoutAuditing()->update(['status' => 'failed', 'error' => mb_substr($error, 0, 500)]);
    }

    private function finish(EmployeeDocumentDownload $download, string $status, int $included = 0, int $skipped = 0, ?string $disk = null, ?string $path = null, ?string $error = null): EmployeeDocumentDownload
    {
        $download->withoutAuditing()->update(['status' => $status, 'included' => $included, 'skipped' => $skipped, 'file_disk' => $disk, 'file_path' => $path, 'error' => $error,
            'expires_at' => $status === 'ready' ? now()->addHours(max(1, (int) config('peopleos.lifecycle.joiners.download_hours', 24))) : null]);

        return $download;
    }

    private function notify(User $actor, EmployeeDocumentDownload $download): void
    {
        rescue(function () use ($actor, $download) {
            $actor->notifyNow(Notification::make()->title('Documents ready to download')
                ->body($download->included.' document(s) in one zip'.($download->skipped > 0 ? '; '.$download->skipped.' not included (not yours to open)' : '').'. The link works until '.$download->expires_at?->format('d M Y H:i').'.')
                ->icon('heroicon-o-arrow-down-tray')->iconColor($download->skipped > 0 ? 'warning' : 'success')
                ->actions([Action::make('download')->label('Download zip')->url(self::url($download))])->toDatabase());
        }, report: true);
    }
}
