<?php

namespace App\Domain\Performance\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * HROne setup parity HP-8c: a KPI rating formula. The achievement ratio (achievement ÷ target, or target ÷ achievement)
 * in percent falls in one band, and the band gives the rating: [{from_pct, to_pct, rating}], to_pct blank = no upper
 * limit. The ratio is not capped at 100 % (over-achievement can earn a higher band).
 */
#[Fillable(['tenant_id', 'code', 'name', 'based_on', 'bands', 'status'])]
class KpiRatingFormula extends Model
{
    use Auditable, BelongsToTenant;

    protected $attributes = ['status' => 'active', 'based_on' => 'achievement_over_target'];

    protected static function booted(): void
    {
        static::saving(function (self $formula): void {
            $formula->code = strtoupper(trim((string) $formula->code));
            if (! array_key_exists((string) $formula->based_on, (array) config('peopleos.performance.kpi_formula_bases'))) {
                throw new RuntimeException('Choose how achievement and target are compared.');
            }
            $formula->bands = self::normaliseBands((array) $formula->bands);
        });
    }

    /**
     * Sorted bands with numbers; refuses a band without a rating, a band whose "from" is above its "to", and overlaps.
     *
     * @return list<array{from_pct: float, to_pct: ?float, rating: float}>
     */
    public static function normaliseBands(array $bands): array
    {
        $rows = collect($bands)->map(function ($band) {
            $from = $band['from_pct'] ?? null;
            $to = $band['to_pct'] ?? null;
            $rating = $band['rating'] ?? null;
            if (! is_numeric($from) || ! is_numeric($rating) || ($to !== null && $to !== '' && ! is_numeric($to))) {
                throw new RuntimeException('Each band needs a "from" percentage and a rating (and a numeric "to", or none).');
            }

            return ['from_pct' => (float) $from, 'to_pct' => $to === null || $to === '' ? null : (float) $to, 'rating' => (float) $rating];
        })->sortBy('from_pct')->values();

        if ($rows->isEmpty()) {
            throw new RuntimeException('A KPI rating formula needs at least one band.');
        }
        foreach ($rows as $i => $row) {
            if ($row['to_pct'] !== null && $row['to_pct'] < $row['from_pct']) {
                throw new RuntimeException("A band cannot end ({$row['to_pct']} %) before it starts ({$row['from_pct']} %).");
            }
            $next = $rows[$i + 1] ?? null;
            if ($next !== null && ($row['to_pct'] === null || $next['from_pct'] <= $row['to_pct'])) {
                throw new RuntimeException('The bands overlap: each achievement percentage must fall in one band only.');
            }
        }

        return $rows->all();
    }

    protected function casts(): array
    {
        return ['bands' => 'array'];
    }

    public function auditModule(): string
    {
        return 'performance';
    }

    public function auditLabel(): string
    {
        return "{$this->name} ({$this->code})";
    }

    /** The rating of the band containing the percentage, or null when it falls in no band. */
    public function ratingFor(float $pct): ?float
    {
        foreach ((array) $this->bands as $band) {
            if ($pct >= (float) $band['from_pct'] && ($band['to_pct'] === null || $pct <= (float) $band['to_pct'])) {
                return (float) $band['rating'];
            }
        }

        return null;
    }
}
