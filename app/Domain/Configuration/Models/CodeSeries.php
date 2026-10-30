<?php

namespace App\Domain\Configuration\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Organisation\Enums\ActiveStatus;
use App\Domain\Organisation\Models\Company;
use App\Support\Numbering\NumberSeries;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

/**
 * HROne setup parity (Forms & templates → Code series): how one code type (`key`, see NumberSeries::types()) is
 * numbered for the whole organisation (company_id null) or one company: prefix + zero-padded number + suffix, where the
 * prefix and suffix may carry {YYYY} {YY} {MM} {FY}. `start_number` is where numbering starts (a series always continues
 * after the highest number already used in its pattern). An inactive series is skipped. Edits are audited; numbers are
 * counted elsewhere (number_sequences), so issuing a number is not a configuration change.
 */
#[Fillable(['tenant_id', 'key', 'company_id', 'prefix', 'suffix', 'digits', 'start_number', 'status'])]
class CodeSeries extends Model
{
    use Auditable, BelongsToTenant;

    protected $table = 'number_series';

    protected $attributes = ['prefix' => '', 'suffix' => '', 'digits' => 5, 'start_number' => 1, 'status' => 'active'];

    protected static function booted(): void
    {
        static::saving(function (self $series): void {
            $series->prefix = trim((string) $series->prefix);
            $series->suffix = trim((string) $series->suffix);
            $problem = app(NumberSeries::class)->problemWith($series);
            if ($problem !== null) {
                throw ValidationException::withMessages([$problem[0] => $problem[1]]);
            }
        });
    }

    protected function casts(): array
    {
        return ['digits' => 'integer', 'start_number' => 'integer', 'status' => ActiveStatus::class];
    }

    public function auditModule(): string
    {
        return 'configuration';
    }

    public function auditLabel(): string
    {
        return (NumberSeries::types()[$this->key]['label'] ?? $this->key).' · '.($this->company?->name ?? 'All companies').' · '.$this->pattern();
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function isActive(): bool
    {
        return ($this->getAttributes()['status'] ?? ActiveStatus::Active->value) === ActiveStatus::Active->value;
    }

    /** The shape of the numbers for people: LN-{YYYY}-#####. */
    public function pattern(): string
    {
        return $this->prefix.str_repeat('#', max(1, (int) $this->digits)).$this->suffix;
    }
}
