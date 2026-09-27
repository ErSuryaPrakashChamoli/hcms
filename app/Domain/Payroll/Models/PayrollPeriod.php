<?php

namespace App\Domain\Payroll\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Organisation\Models\Company;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/** A calendar month for one legal entity (§31). Closed once its run is finalized. */
#[Fillable(['tenant_id', 'company_id', 'year', 'month', 'start_date', 'end_date', 'status'])]
class PayrollPeriod extends Model
{
    use Auditable, BelongsToTenant;

    protected function casts(): array
    {
        return ['year' => 'integer', 'month' => 'integer', 'start_date' => 'date', 'end_date' => 'date'];
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

    public static function for(Company $company, int $year, int $month): self
    {
        $start = Carbon::create($year, $month, 1)->startOfDay();

        return static::query()->firstOrCreate(
            ['company_id' => $company->id, 'year' => $year, 'month' => $month],
            ['start_date' => $start, 'end_date' => $start->copy()->endOfMonth()->startOfDay(), 'status' => 'open'],
        );
    }
}
