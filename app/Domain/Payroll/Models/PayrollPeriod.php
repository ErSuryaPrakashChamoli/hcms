<?php

namespace App\Domain\Payroll\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Organisation\Models\Company;
use App\Domain\Payroll\Services\PayCycle;
use App\Support\Tenancy\BelongsToTenant;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A payroll month for one legal entity (§31), named after the month it ends in; its dates follow the tenant's pay
 * cycle (PayCycle: 1 Nov – 30 Nov, or 25 Oct – 24 Nov). Closed once its run is finalized.
 */
#[Fillable(['tenant_id', 'company_id', 'year', 'month', 'start_date', 'end_date', 'payment_date', 'status'])]
class PayrollPeriod extends Model
{
    use Auditable, BelongsToTenant;

    protected static function booted(): void
    {
        // A month closes when its payroll is finalized, and finalized payroll is never reopened (decision C12-D1 open).
        static::updating(function (self $period): void {
            if ($period->getOriginal('status') === 'closed' && $period->status !== 'closed') {
                throw new \RuntimeException("{$period->label()} is closed: its payroll is finalized and the month cannot be reopened.");
            }
        });
    }

    protected function casts(): array
    {
        return ['year' => 'integer', 'month' => 'integer', 'start_date' => 'date', 'end_date' => 'date', 'payment_date' => 'date'];
    }

    public function auditModule(): string
    {
        return 'payroll';
    }

    public function auditLabel(): string
    {
        return $this->label();
    }

    public function label(): string
    {
        return Carbon::create($this->year, $this->month, 1)->format('M Y');
    }

    /** The month as Y-m ("2026-11"), whatever day it starts on. */
    public function key(): string
    {
        return sprintf('%04d-%02d', $this->year, $this->month);
    }

    /** "25 Oct – 24 Nov 2026". */
    public function rangeLabel(): string
    {
        return $this->start_date->format($this->start_date->year === $this->end_date->year ? 'j M' : 'j M Y').' – '.$this->end_date->format('j M Y');
    }

    /** Dates are fixed once a payroll run is opened for the month (or it closes); until then they follow the pay cycle. */
    public function isFixed(): bool
    {
        return $this->status === 'closed' || $this->runs()->exists();
    }

    /**
     * Months whose first calendar day falls in the range: for calendar months the same as "starts in the range", and
     * a month on a 25th-to-24th cycle still counts in the month it is named for.
     */
    #[Scope]
    protected function monthsBetween(Builder $query, CarbonInterface|string $from, CarbonInterface|string $to): void
    {
        $from = Carbon::parse($from);
        $to = Carbon::parse($to);
        $ordinal = '('.$query->qualifyColumn('year').' * 12 + '.$query->qualifyColumn('month').')';
        $query->whereRaw("{$ordinal} between ? and ?", [$from->year * 12 + $from->month + ($from->day > 1 ? 1 : 0), $to->year * 12 + $to->month]);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function runs(): HasMany
    {
        return $this->hasMany(PayrollRun::class);
    }

    public function adjustments(): HasMany
    {
        return $this->hasMany(PayrollAdjustment::class);
    }

    public function daysInPeriod(): int
    {
        return (int) $this->start_date->diffInDays($this->end_date) + 1;
    }

    /**
     * Phase 7: the date salary for this period is paid — the statutory trigger for salary TDS
     * (Income-tax Act, 2025 s.392(1)). Defaults to the period end date.
     */
    public function paymentDate(): Carbon
    {
        return ($this->payment_date ?? $this->end_date)->copy()->startOfDay();
    }

    public static function for(Company $company, int $year, int $month): self
    {
        return app(PayCycle::class)->period($company->id, $year, $month);
    }

    /** The company's payroll month a date falls in (25 Oct is November's on a 25th-to-24th cycle). */
    public static function containing(Company $company, CarbonInterface $date): self
    {
        [$year, $month] = app(PayCycle::class)->monthContaining($company->id, $date);

        return static::for($company, $year, $month);
    }
}
