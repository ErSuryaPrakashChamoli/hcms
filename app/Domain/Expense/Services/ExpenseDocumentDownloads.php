<?php

namespace App\Domain\Expense\Services;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Services\AuditRecorder;
use App\Domain\Expense\Jobs\BuildExpenseDocumentDownload;
use App\Domain\Expense\Models\ExpenseDocumentDownload;
use App\Domain\Expense\Models\ExpenseReceipt;
use App\Domain\Expense\Models\ExpenseReport;
use App\Domain\Experience\Services\ScreenAccess;
use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Identity\Models\User;
use App\Support\Tenancy\TenantContext;
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
 * HROne parity XO-3 ("Download documents", matrix 26 §26.4.2 #11): the receipt files and / or cover letters of chosen
 * expense reports as one zip, prepared by a queued job — the WO-13 LetterDownloads pattern.
 *
 * - Asked for by someone who sees expense reports in scope (`expense.view`, `expense.approve` or `expense.pay`), for at
 *   most `peopleos.expense.documents.bulk_max` reports (the rows chosen, or every report matching the register's filters).
 *   Cover letters only while expense.report.cover_letter is on.
 * - The job runs as that person: only reports and receipts inside their access scope that pass the `view` policy go in,
 *   and only files kept under the tenant's own folder on the documents disk; each receipt file put in the zip is audited
 *   (ATTACHMENT_DOWNLOADED, purpose bulk_download), each cover letter by ExpenseCoverLetters (DOWNLOAD). Reports with
 *   nothing to include are counted as skipped.
 * - The zip is kept on the documents disk (tenants/{id}/expense/downloads) for `peopleos.expense.documents.download_hours`
 *   and fetched only by the person who asked, through a signed link (audited); peopleos:expense:expire removes expired zips.
 */
final class ExpenseDocumentDownloads
{
    public function __construct(private readonly AuditRecorder $audit, private readonly ExpenseCoverLetters $coverLetters) {}

    /** People who see expense reports in scope (the register's HR side). */
    public static function mayDownload(?User $user): bool
    {
        return $user !== null && ($user->hasPermission('expense.view') || $user->hasPermission('expense.approve') || $user->hasPermission('expense.pay'));
    }

    public static function max(): int
    {
        return max(1, (int) config('peopleos.expense.documents.bulk_max', 200));
    }

    /** @return array<string, string> the documents a zip may hold now (cover letters only while they are switched on) */
    public function documentOptions(): array
    {
        return array_intersect_key(ExpenseDocumentDownload::DOCUMENTS, array_flip($this->coverLetters->enabled() ? ['receipts', 'cover_letters'] : ['receipts']));
    }

    /**
     * @param  list<int|string>  $reportIds
     * @param  list<string>  $documents  receipts and / or cover_letters
     */
    public function request(User $actor, array $reportIds, array $documents = ['receipts'], string $selection = 'selected'): ExpenseDocumentDownload
    {
        if (! self::mayDownload($actor)) {
            throw new RuntimeException('You cannot download expense documents.');
        }
        $documents = array_values(array_intersect(array_keys(ExpenseDocumentDownload::DOCUMENTS), array_map('strval', $documents)));
        if ($documents === []) {
            throw new RuntimeException('Choose the documents to download: receipt files, cover letters or both.');
        }
        if (in_array('cover_letters', $documents, true) && ! $this->coverLetters->enabled()) {
            throw new RuntimeException('Expense cover letters are switched off (Setup → Expense).');
        }
        $ids = array_values(array_unique(array_map('intval', array_filter($reportIds, 'is_numeric'))));
        if ($ids === []) {
            throw new RuntimeException('Choose at least one expense report.');
        }
        if (count($ids) > self::max()) {
            throw new RuntimeException('At most '.self::max().' reports in one download. Narrow the register first.');
        }
        $download = ExpenseDocumentDownload::create(['requested_by' => $actor->id, 'report_ids' => $ids, 'documents' => $documents,
            'selection' => in_array($selection, ['selected', 'all_matching'], true) ? $selection : 'selected', 'status' => 'queued', 'total' => count($ids)]);
        BuildExpenseDocumentDownload::dispatch($download->id)->afterCommit();

        return $download->refresh();
    }

    /** The job: build the zip as the person who asked. */
    public function build(ExpenseDocumentDownload $download): ExpenseDocumentDownload
    {
        $claimed = ExpenseDocumentDownload::query()->whereKey($download->id)->where('status', 'queued')->update(['status' => 'running', 'updated_at' => now()]);
        if ($claimed !== 1) {
            return $download->refresh();
        }
        $download->refresh();
        $actor = User::query()->whereKey($download->requested_by)->where('tenant_id', $download->tenant_id)->first();
        if ($actor === null || $actor->status !== UserStatus::Active || ! self::mayDownload($actor)) {
            return $this->finish($download, 'failed', error: 'The person who asked can no longer download expense documents.');
        }

        $path = null;
        $tmp = (string) tempnam(sys_get_temp_dir(), 'peopleos-expense-');
        try {
            [$included, $files] = ScreenAccess::as($actor, fn () => $this->fill($download, $actor, $tmp));
            if ($files === 0) {
                return $this->finish($download, 'failed', skipped: $download->total, error: 'None of the chosen reports had documents you may open.');
            }
            $path = self::directory().'/'.Str::ulid().'.zip';
            $disk = (string) config('peopleos.documents.disk');
            Storage::disk($disk)->put($path, (string) file_get_contents($tmp));

            $download = $this->finish($download, 'ready', $included, max(0, $download->total - $included), $files, $disk, $path);
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
    public function fetch(ExpenseDocumentDownload $download, User $user): array
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
        $this->audit->record(AuditAction::Download, 'expense', $download, [], null, actor: $user, metadata: ['reports' => $download->included, 'files' => $download->files, 'purpose' => 'bulk_download']);

        return ['content' => (string) $disk->get((string) $download->file_path), 'name' => 'expense-documents-'.$download->created_at?->format('Ymd-His').'.zip'];
    }

    public static function url(ExpenseDocumentDownload $download): string
    {
        return URL::temporarySignedRoute('expense.documents.zip', $download->expires_at ?? now()->addHour(), ['download' => $download->id]);
    }

    /** Expired zips are removed (the row stays as the record of the download). */
    public function prune(): int
    {
        $count = 0;
        ExpenseDocumentDownload::query()->where('status', 'ready')->where('expires_at', '<', now())->each(function (ExpenseDocumentDownload $download) use (&$count) {
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
        ExpenseDocumentDownload::query()->whereKey($downloadId)->whereIn('status', ['queued', 'running'])->first()
            ?->withoutAuditing()->update(['status' => 'failed', 'error' => mb_substr($error, 0, 500)]);
    }

    public static function directory(): string
    {
        return 'tenants/'.app(TenantContext::class)->id().'/expense/downloads';
    }

    /**
     * Writes the zip (runs as the person who asked, so their access scope narrows every query).
     *
     * @return array{0: int, 1: int} reports included, files included
     */
    private function fill(ExpenseDocumentDownload $download, User $actor, string $tmp): array
    {
        $zip = new ZipArchive;
        if ($zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('The download could not be prepared.');
        }
        $documents = (array) $download->documents;
        $covers = in_array('cover_letters', $documents, true) && $this->coverLetters->enabled();
        $receipts = in_array('receipts', $documents, true);
        $disk = Storage::disk((string) config('peopleos.documents.disk', 'local'));
        $own = 'tenants/'.$download->tenant_id.'/';
        $folders = [];
        $reports = 0;
        $files = 0;
        ExpenseReport::query()->with('employee.person')->whereKey((array) $download->report_ids)->orderBy('id')
            ->each(function (ExpenseReport $report) use ($zip, $actor, $download, $covers, $receipts, $disk, $own, &$folders, &$reports, &$files) {
                if (Gate::forUser($actor)->denies('view', $report)) {
                    return;
                }
                $folder = self::unique(self::safe($report->number.' '.$report->employee?->employee_code.' '.$report->employee?->person?->full_name, 'Report'), $folders);
                $names = [];
                $added = 0;
                if ($covers && $report->status !== 'cancelled') {
                    try {
                        $file = $this->coverLetters->download($report, $actor);
                        $zip->addFromString($folder.'/'.self::unique(self::safe(pathinfo($file['name'], PATHINFO_FILENAME), 'Cover letter').'.'.pathinfo($file['name'], PATHINFO_EXTENSION), $names), $file['content']);
                        $added++;
                    } catch (RuntimeException) {
                        // a cover letter that cannot be made (e.g. its Word file is missing) is left out, never a broken entry
                    }
                }
                if ($receipts) {
                    ExpenseReceipt::query()->where('expense_report_id', $report->id)->orderBy('expense_date')->orderBy('id')
                        ->each(function (ExpenseReceipt $receipt) use ($zip, $actor, $download, $disk, $own, $folder, &$names, &$added) {
                            if (Gate::forUser($actor)->denies('view', $receipt)) {
                                return;
                            }
                            foreach (array_values((array) ($receipt->attachments ?? [])) as $path) {
                                if (! is_string($path) || ! str_starts_with($path, $own) || str_contains($path, '..') || ! $disk->exists($path)) {
                                    continue;
                                }
                                $base = pathinfo($path, PATHINFO_FILENAME);
                                $extension = pathinfo($path, PATHINFO_EXTENSION);
                                $name = self::unique(self::safe($receipt->number.' - '.$base, $receipt->number).($extension !== '' ? '.'.$extension : ''), $names);
                                $zip->addFromString($folder.'/'.$name, (string) $disk->get($path));
                                $this->audit->record(AuditAction::AttachmentDownloaded, 'expense', $receipt, [], 'bulk_download', actor: $actor,
                                    metadata: ['type' => 'expense-receipt', 'file' => basename($path), 'purpose' => 'bulk_download', 'expense_document_download' => $download->id]);
                                $added++;
                            }
                        });
                }
                if ($added > 0) {
                    $reports++;
                    $files += $added;
                }
            });
        $zip->close();

        return [$reports, $files];
    }

    private static function safe(string $name, string $fallback): string
    {
        return mb_substr(trim((string) preg_replace('~[\\\\/:*?"<>|\x00-\x1F]+~', '-', $name), ' .-'), 0, 120) ?: $fallback;
    }

    /** @param  array<string, true>  $taken */
    private static function unique(string $name, array &$taken): string
    {
        $extension = pathinfo($name, PATHINFO_EXTENSION);
        $base = $extension !== '' ? mb_substr($name, 0, -mb_strlen($extension) - 1) : $name;
        $candidate = $name;
        for ($i = 2; isset($taken[mb_strtolower($candidate)]); $i++) {
            $candidate = $base.' ('.$i.')'.($extension !== '' ? '.'.$extension : '');
        }
        $taken[mb_strtolower($candidate)] = true;

        return $candidate;
    }

    private function finish(ExpenseDocumentDownload $download, string $status, int $included = 0, int $skipped = 0, int $files = 0, ?string $disk = null, ?string $path = null, ?string $error = null): ExpenseDocumentDownload
    {
        $download->withoutAuditing()->update(['status' => $status, 'included' => $included, 'skipped' => $skipped, 'files' => $files, 'file_disk' => $disk, 'file_path' => $path, 'error' => $error,
            'expires_at' => $status === 'ready' ? now()->addHours(max(1, (int) config('peopleos.expense.documents.download_hours', 24))) : null]);

        return $download;
    }

    private function notify(User $actor, ExpenseDocumentDownload $download): void
    {
        rescue(function () use ($actor, $download) {
            $actor->notifyNow(Notification::make()->title('Expense documents ready to download')
                ->body($download->files.' file(s) of '.$download->included.' report(s) in one zip'.($download->skipped > 0 ? '; '.$download->skipped.' report(s) with nothing you may open' : '').'. The link works until '.$download->expires_at?->format('d M Y H:i').'.')
                ->icon('heroicon-o-arrow-down-tray')->iconColor($download->skipped > 0 ? 'warning' : 'success')
                ->actions([Action::make('download')->label('Download zip')->url(self::url($download))])->toDatabase());
        }, report: true);
    }
}
