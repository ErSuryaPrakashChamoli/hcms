<?php

namespace App\Domain\Offboarding\Services;

use App\Domain\Offboarding\Enums\DeletionStatus;
use App\Domain\Offboarding\Models\TenantDeletion;
use App\Domain\Offboarding\Support\Signatures;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * SaaS.12: the deletion register, kept OUTSIDE the database.
 *
 * A deletion's certificate lives in `tenant_deletions`, so a database restored to a point before the deletion loses
 * the deletion and its proof together. The register keeps one entry per completed deletion: the signed certificate,
 * written once, on its own disk (`peopleos.offboarding.register_disk`, separately backed up in production).
 * `DeletionReconciliation` compares it with the database after a restore.
 *
 * An entry is evidence only when its certificate hashes to the recorded SHA-256 and carries a valid signature (the
 * current application key or a previous one). Anything else is reported, never acted on.
 */
final class DeletionRegister
{
    public const FORMAT = 'peopleos-deletion-register/1';

    public const DIRECTORY = 'deletion-register';

    public function __construct(private readonly TenantContext $tenants) {}

    public function disk(): Filesystem
    {
        return Storage::disk((string) (config('peopleos.offboarding.register_disk') ?: 'local'));
    }

    public static function pathOf(string $reference): string
    {
        return self::DIRECTORY.'/'.$reference.'.json';
    }

    /**
     * Writes the entry of a completed, certified deletion. Write-once: true when written now, false when the same entry is
     * already there; a different entry under the same reference is refused (the register is never rewritten).
     */
    public function record(TenantDeletion $deletion): bool
    {
        if ($deletion->status !== DeletionStatus::Completed || $deletion->certificate === null || $deletion->certificate_sha256 === null) {
            throw new RuntimeException("Deletion {$deletion->reference} has no certificate to register.");
        }
        $path = self::pathOf($deletion->reference);
        $disk = $this->disk();
        if ($disk->exists($path)) {
            $existing = json_decode((string) $disk->get($path), true);
            if (is_array($existing) && ($existing['certificate_sha256'] ?? null) === $deletion->certificate_sha256) {
                return false;
            }
            throw new RuntimeException("The deletion register already holds a different entry for {$deletion->reference}.");
        }
        $certificate = $deletion->certificate;
        $entry = [
            'format' => self::FORMAT,
            'reference' => $deletion->reference,
            'tenant' => ['id' => (int) ($certificate['tenant']['id'] ?? $deletion->tenant_id), 'slug' => (string) ($certificate['tenant']['slug'] ?? '')],
            'final' => (bool) ($certificate['final'] ?? false),
            'certified_at' => $deletion->certified_at?->toIso8601String(),
            'certificate_sha256' => $deletion->certificate_sha256,
            'certificate_signature' => $deletion->certificate_signature,
            'certificate' => $certificate,
        ];
        if (! $disk->put($path, json_encode($entry, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR))) {
            throw new RuntimeException("The deletion register refused the entry for {$deletion->reference}.");
        }

        return true;
    }

    /** Records the entry of a deletion just certified; a failure is logged and left for the offboarding tick to retry. */
    public function recordQuietly(TenantDeletion $deletion): void
    {
        try {
            $this->record($deletion);
        } catch (Throwable $e) {
            Log::warning('Deletion register entry not written; the offboarding tick retries it.', ['deletion' => $deletion->reference, 'error' => $e::class]);
        }
    }

    /**
     * Completed deletions in the database without a register entry get one (the offboarding tick).
     *
     * @return array{written: int, failed: int}
     */
    public function syncMissing(): array
    {
        $result = ['written' => 0, 'failed' => 0];
        $completed = $this->tenants->bypass(fn () => TenantDeletion::query()->where('status', DeletionStatus::Completed->value)
            ->whereNotNull('certificate_sha256')->orderBy('id')->get());
        foreach ($completed as $deletion) {
            try {
                $result['written'] += $this->record($deletion) ? 1 : 0;
            } catch (Throwable $e) {
                $result['failed']++;
                Log::error('Deletion register entry could not be written.', ['deletion' => $deletion->reference, 'error' => $e::class]);
            }
        }

        return $result;
    }

    /**
     * Every entry, checked. `valid` entries are evidence; the others say why not.
     *
     * @return list<array{path: string, reference: ?string, valid: bool, problem: ?string, entry: ?array<string, mixed>}>
     */
    public function entries(): array
    {
        $disk = $this->disk();
        $entries = [];
        foreach ($disk->files(self::DIRECTORY) as $path) {
            if (! str_ends_with($path, '.json')) {
                continue;
            }
            $entry = json_decode((string) $disk->get($path), true);
            $problem = self::problemOf($entry, basename($path, '.json'));
            $entries[] = ['path' => $path, 'reference' => is_array($entry) ? ($entry['reference'] ?? null) : null, 'valid' => $problem === null,
                'problem' => $problem, 'entry' => $problem === null ? $entry : null];
        }

        return $entries;
    }

    private static function problemOf(mixed $entry, string $name): ?string
    {
        if (! is_array($entry) || ($entry['format'] ?? null) !== self::FORMAT || ! is_array($entry['certificate'] ?? null)) {
            return 'unreadable or not a register entry';
        }
        $certificate = $entry['certificate'];
        if (($entry['reference'] ?? null) !== $name || ($certificate['deletion']['reference'] ?? null) !== $name) {
            return 'the reference does not match the certificate';
        }
        if ((int) ($certificate['tenant']['id'] ?? 0) !== (int) ($entry['tenant']['id'] ?? -1) || ($certificate['tenant']['slug'] ?? null) !== ($entry['tenant']['slug'] ?? null)) {
            return 'the tenant does not match the certificate';
        }
        if (! hash_equals(hash('sha256', Signatures::canonical($certificate)), (string) ($entry['certificate_sha256'] ?? ''))) {
            return 'the certificate does not match its SHA-256';
        }
        if (! Signatures::verifyWithAnyKey('deletion-certificate', $certificate, (string) ($entry['certificate_signature'] ?? ''))) {
            return 'the signature is not valid for this installation\'s keys';
        }

        return null;
    }
}
