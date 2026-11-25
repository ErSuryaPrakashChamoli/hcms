<?php

use App\Domain\Compliance\Services\ComplianceRules;
use Illuminate\Support\Facades\Storage;

/*
| Test storage isolation (2026-11-25). The application's own storage/app holds preserved statutory evidence
| (storage/app/private/compliance-evidence). Test runs that seeded statutory packs wrote into it; tests/TestCase.php now
| points every local disk at a folder of the test process. These tests keep it that way.
*/

/** Files under the live evidence folder changed at or after $since (none when the folder does not exist). */
function liveEvidenceChangedSince(int $since): array
{
    $live = storage_path('app/private/compliance-evidence');
    if (! is_dir($live)) {
        return [];
    }
    $changed = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($live, FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->isFile() && $file->getMTime() >= $since) {
            $changed[] = $file->getPathname();
        }
    }

    return $changed;
}

it('points every local disk outside the application storage/app', function () {
    $live = storage_path('app');
    $local = collect(config('filesystems.disks'))->filter(fn (array $disk) => ($disk['driver'] ?? null) === 'local');

    expect($local)->not->toBeEmpty();
    foreach ($local as $name => $disk) {
        expect(str_starts_with((string) $disk['root'], $live))->toBeFalse("disk {$name} writes into {$disk['root']}")
            ->and(str_starts_with((string) $disk['root'], storage_path('framework/testing')))->toBeTrue("disk {$name} is not isolated");
    }
    $compliance = Storage::disk(config('peopleos.storage.compliance_disk', 'local'));
    expect(str_starts_with($compliance->path('compliance-evidence'), $live))->toBeFalse();
});

it('writes statutory pack evidence into the test folder, never into the preserved evidence', function () {
    $since = time();
    $disk = Storage::disk(config('peopleos.storage.compliance_disk', 'local'));

    app(ComplianceRules::class)->sync();

    $written = $disk->allFiles('compliance-evidence');
    expect($written)->not->toBeEmpty()   // the packs ship official documents: they were stored, in the test folder
        ->and(liveEvidenceChangedSince($since))->toBe([]);
});
