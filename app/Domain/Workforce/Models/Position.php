<?php

namespace App\Domain\Workforce\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Employment\Models\EmployeePosition;
use App\Domain\Identity\Models\User;
use App\Domain\Organisation\Models\Company;
use App\Domain\Organisation\Models\Designation;
use App\Domain\Organisation\Models\Location;
use App\Domain\Organisation\Models\OrganisationNode;
use App\Domain\Workforce\Concerns\ScopedByOrganisationDimensions;
use App\Support\Tenancy\BelongsToTenant;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Phase 10: a position — organisational capacity (one or more seats with an FTE capacity), owned by
 * a company, never an employee. Its definition is a series of effective-dated, immutable
 * PositionVersions; the columns here mirror the latest version for listing and organisation scoping.
 * Employees occupy it through their own employee_positions rows. Never deleted.
 */
#[Fillable(['tenant_id', 'company_id', 'code', 'title', 'status', 'designation_id', 'organisation_node_id', 'business_unit_id', 'division_id', 'department_id', 'team_id', 'location_id', 'establishment_id', 'first_effective_from', 'current_version_id', 'source_plan_line_id', 'created_by', 'lock_version'])]
class Position extends Model
{
    use Auditable, BelongsToTenant, ScopedByOrganisationDimensions;

    protected $attributes = ['status' => 'draft'];

    protected static function booted(): void
    {
        static::saving(fn (self $p) => $p->code = strtoupper(trim((string) $p->code)));
        static::updating(function (self $p) {
            foreach (['code', 'company_id'] as $immutable) {
                if ($p->isDirty($immutable)) {
                    throw new \RuntimeException('A position keeps its code and company; abolish it and create another.');
                }
            }
            if (! $p->isDirty('lock_version')) {
                $p->lock_version = (int) $p->getRawOriginal('lock_version') + 1;
            }
        });
        static::deleting(fn () => throw new \RuntimeException('Positions are abolished or closed, never deleted.'));
    }

    protected function casts(): array
    {
        return ['first_effective_from' => 'date', 'lock_version' => 'integer'];
    }

    public function auditModule(): string
    {
        return 'workforce';
    }

    public function auditLabel(): string
    {
        return "Position {$this->code}";
    }

    public function versions(): HasMany
    {
        return $this->hasMany(PositionVersion::class)->orderBy('version');
    }

    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(PositionVersion::class, 'current_version_id');
    }

    /** The version in force on a date (zero-length same-day versions are never in force). */
    public function versionOn(CarbonInterface|string|null $on = null): ?PositionVersion
    {
        $day = Carbon::parse($on ?? now())->toDateString();

        return PositionVersion::query()->where('position_id', $this->id)->effectiveOn($day)->orderByDesc('version')->first();
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(EmployeePosition::class);
    }

    public function changeRequests(): HasMany
    {
        return $this->hasMany(PositionChangeRequest::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function designation(): BelongsTo
    {
        return $this->belongsTo(Designation::class);
    }

    public function organisationNode(): BelongsTo
    {
        return $this->belongsTo(OrganisationNode::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
