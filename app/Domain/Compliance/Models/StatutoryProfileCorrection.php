<?php

namespace App\Domain\Compliance\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Organisation\Models\Establishment;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * C12-W: a controlled correction of an establishment statutory profile for months whose payroll is past review. The
 * proposal, the original context (the profile as payroll used it; per affected month and statute what applied before
 * and after, with the finalised runs and amounts) and the classification are written once. Afterwards only the status
 * moves: requested → approved → applied, or requested → rejected. Kept as history; never deleted. See
 * StatutoryProfileCorrections.
 */
#[Fillable(['company_id', 'establishment_id', 'profile_id', 'action', 'statute', 'original', 'proposed', 'affected', 'classification', 'required_actions', 'reason', 'evidence', 'status', 'requested_by', 'requested_at', 'reviewed_by', 'reviewed_at', 'review_note', 'applied_by', 'applied_at'])]
class StatutoryProfileCorrection extends Model
{
    use Auditable, BelongsToTenant;

    public const CONFIGURATION_ONLY = 'configuration_only';

    public const PAYROLL_IMPACT = 'payroll_impact';

    private const TRANSITIONS = ['requested' => ['approved', 'rejected'], 'approved' => ['applied']];

    private const WRITTEN_ONCE = ['company_id', 'establishment_id', 'profile_id', 'action', 'statute', 'original', 'proposed', 'affected', 'classification', 'required_actions', 'reason', 'evidence', 'requested_by', 'requested_at'];

    protected $attributes = ['status' => 'requested'];

    protected static function booted(): void
    {
        static::updating(function (StatutoryProfileCorrection $correction) {
            if ($correction->isDirty(self::WRITTEN_ONCE)) {
                throw new RuntimeException('A correction request\'s proposal and original context are written once.');
            }
            if ($correction->isDirty('status') && ! in_array($correction->status, self::TRANSITIONS[$correction->getOriginal('status')] ?? [], true)) {
                throw new RuntimeException("A {$correction->getOriginal('status')} correction cannot become {$correction->status}.");
            }
        });
        static::deleting(fn () => throw new RuntimeException('Correction requests are kept as history.'));
    }

    protected function casts(): array
    {
        return [
            'original' => 'array',
            'proposed' => 'array',
            'affected' => 'array',
            'required_actions' => 'array',
            'requested_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'applied_at' => 'datetime',
        ];
    }

    /** @return list<array{period_end: string, statute: string, before: bool, after: bool}> the changes approved, for comparison */
    public function approvedChanges(): array
    {
        return array_map(fn (array $a) => ['period_end' => $a['period_end'], 'statute' => $a['statute'], 'before' => (bool) $a['before'], 'after' => (bool) $a['after']], (array) $this->affected);
    }

    public function auditModule(): string
    {
        return 'compliance';
    }

    public function auditLabel(): string
    {
        return "Statutory profile correction #{$this->getKey()}";
    }

    public function establishment(): BelongsTo
    {
        return $this->belongsTo(Establishment::class);
    }
}
