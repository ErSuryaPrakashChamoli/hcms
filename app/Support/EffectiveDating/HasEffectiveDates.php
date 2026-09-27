<?php

namespace App\Support\EffectiveDating;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Effective-dated records: effective_from / effective_to (both nullable = open-ended).
 * Models using this trait must cast both columns to 'date'.
 */
trait HasEffectiveDates
{
    #[Scope]
    protected function effectiveOn(Builder $query, CarbonInterface|string|null $date = null): Builder
    {
        $day = Carbon::parse($date ?? now())->toDateString();

        // The upper bound carries a time so an equal date matches whether the driver stores a
        // bare DATE (MySQL/PostgreSQL) or a "Y-m-d H:i:s" string (SQLite). Index-friendly on both.
        return $query
            ->where(fn (Builder $q) => $q->whereNull($this->qualifyColumn('effective_from'))
                ->orWhere($this->qualifyColumn('effective_from'), '<=', $day.' 23:59:59'))
            ->where(fn (Builder $q) => $q->whereNull($this->qualifyColumn('effective_to'))
                ->orWhere($this->qualifyColumn('effective_to'), '>=', $day));
    }

    #[Scope]
    protected function currentlyEffective(Builder $query): Builder
    {
        return $this->effectiveOn($query);
    }

    #[Scope]
    protected function futureDated(Builder $query): Builder
    {
        return $query->where($this->qualifyColumn('effective_from'), '>', now()->toDateString());
    }

    #[Scope]
    protected function expired(Builder $query): Builder
    {
        return $query->where($this->qualifyColumn('effective_to'), '<', now()->toDateString());
    }

    public function isEffectiveOn(CarbonInterface|string|null $date = null): bool
    {
        $day = Carbon::parse($date ?? now())->startOfDay();

        $from = $this->effective_from;
        $to = $this->effective_to;

        return ($from === null || $from->lte($day)) && ($to === null || $to->gte($day));
    }
}
