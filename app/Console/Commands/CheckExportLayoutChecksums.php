<?php

namespace App\Console\Commands;

use App\Domain\Compliance\Models\StatutoryExportLayout;
use App\Domain\Compliance\Services\ExportLayouts;
use Illuminate\Console\Command;

/**
 * D7, read-only: each export layout's checksum algorithm and integrity on this database. Changes nothing. Fails when a
 * seal holds neither directly nor against the layout pack (see ExportLayouts::integrity()).
 */
class CheckExportLayoutChecksums extends Command
{
    protected $signature = 'peopleos:compliance:layout-checksums';

    protected $description = 'Report the checksum algorithm and integrity of every statutory export layout (read-only)';

    public function handle(ExportLayouts $layouts): int
    {
        $rows = StatutoryExportLayout::query()->orderBy('code')->orderBy('version')->get()
            ->map(fn (StatutoryExportLayout $layout) => [$layout->label(), $layout->status, $layout->checksumAlgorithm() ?? 'original', $layouts->integrity($layout)]);

        $this->table(['Layout', 'Status', 'Algorithm', 'Integrity'], $rows->all());
        $counts = $rows->countBy(3);
        $this->line('Integrity: '.($counts->isEmpty() ? 'no layouts' : $counts->map(fn ($n, $state) => "{$state} {$n}")->implode(', ')));
        if ($counts->has(ExportLayouts::INTACT_AGAINST_PACK)) {
            $this->line('intact_against_pack: sealed with the original algorithm, whose checksum this database cannot reproduce (it re-orders JSON keys); the stored checksum and content were proven against the layout pack (D7, decision B). Nothing was rewritten.');
        }

        return $counts->has(ExportLayouts::MISMATCH) ? self::FAILURE : self::SUCCESS;
    }
}
