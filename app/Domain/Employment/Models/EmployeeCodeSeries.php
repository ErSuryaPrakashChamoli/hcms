<?php

namespace App\Domain\Employment\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Employment\Services\EmployeeCodeGenerator;
use App\Domain\Organisation\Models\Company;
use App\Domain\Organisation\Models\EmploymentType;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * How a company's employee codes are made: prefix + number padded to `digits` + suffix (MKT-00042-IN). One per company;
 * the one without a company is the tenant default for companies that have none. HROne setup parity (code series by
 * employee type): a series may also name an employment type; a hire takes company + type, then company, then type
 * (all companies), then the default. `next_number` is the counter, moved by
 * EmployeeCodeGenerator under a row lock without an audit row per hire (the hire itself is audited); an admin's edit
 * is audited. A series never goes back: saving it moves next_number past the highest code already used in its pattern.
 */
#[Fillable(['tenant_id', 'company_id', 'employment_type_id', 'prefix', 'suffix', 'digits', 'next_number', 'allow_manual'])]
class EmployeeCodeSeries extends Model
{
    use Auditable, BelongsToTenant;

    protected $table = 'employee_code_series';

    protected $attributes = ['prefix' => '', 'suffix' => '', 'digits' => 5, 'next_number' => 1, 'allow_manual' => true];

    protected static function booted(): void
    {
        static::saving(function (self $series): void {
            $series->prefix = trim((string) $series->prefix);
            $series->suffix = trim((string) $series->suffix);
            if ($series->isDirty(['prefix', 'suffix', 'next_number'])) {
                $series->next_number = max((int) $series->next_number, app(EmployeeCodeGenerator::class)->highestInPattern($series->prefix, $series->suffix) + 1);
            }
        });
    }

    protected function casts(): array
    {
        return ['company_id' => 'integer', 'employment_type_id' => 'integer', 'digits' => 'integer', 'next_number' => 'integer', 'allow_manual' => 'boolean'];
    }

    public function auditLabel(): string
    {
        return ($this->company?->name ?? 'All companies').($this->employmentType ? ' · '.$this->employmentType->name : '').' · '.$this->pattern();
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function employmentType(): BelongsTo
    {
        return $this->belongsTo(EmploymentType::class);
    }

    public function format(int $number): string
    {
        return $this->prefix.str_pad((string) $number, max(1, (int) $this->digits), '0', STR_PAD_LEFT).$this->suffix;
    }

    /** The shape of the codes for people: MKT-#####-IN. */
    public function pattern(): string
    {
        return $this->prefix.str_repeat('#', max(1, (int) $this->digits)).$this->suffix;
    }
}
