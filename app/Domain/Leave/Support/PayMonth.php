<?php

namespace App\Domain\Leave\Support;

use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * HROne parity PO-8: the month an encashment is paid in, kept as 'YYYY-MM' (as variable pay and holds keep theirs). Blank
 * means the next pay run after approval, as always.
 */
final class PayMonth
{
    /** 'YYYY-MM' or null for blank; anything else is refused. */
    public static function parse(mixed $value): ?string
    {
        $month = trim((string) $value);
        if ($month === '') {
            return null;
        }
        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month) !== 1) {
            throw new RuntimeException('Give the pay month as YYYY-MM.');
        }

        return $month;
    }

    /** "Nov 2026", or "The next pay run" for none. */
    public static function label(?string $month): string
    {
        return filled($month) && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', (string) $month) === 1
            ? Carbon::createFromFormat('Y-m-d', $month.'-01')->format('M Y') : 'The next pay run';
    }

    /** @return array<string, string> 'YYYY-MM' => "Nov 2026": the month before this one to a year ahead */
    public static function options(): array
    {
        $out = [];
        $month = now()->startOfMonth()->subMonth();
        for ($i = 0; $i < 14; $i++, $month->addMonth()) {
            $out[$month->format('Y-m')] = $month->format('M Y');
        }

        return $out;
    }
}
