<?php

namespace App\Domain\Engagement\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Identity\Models\User;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RuntimeException;

/**
 * HROne parity GO-18 (Engage → "Initiate fundraise"): a fundraiser campaign — the cause (name, purpose, beneficiary,
 * a supporting document), its window, target and currency, and who may donate (a saved audience or criteria, pinned with
 * the opener's scope when it opens). Status draft → open → closed, or cancelled; written only by Fundraisers. Pledges
 * through payroll are possible only when the campaign collects through payroll (`payroll_deduction`, allowed only while
 * the organisation switched engagement.fundraisers.payroll_deduction on), optionally in one fixed deduction month.
 * Campaigns are never deleted.
 */
#[Fillable(['tenant_id', 'name', 'purpose', 'beneficiary', 'starts_on', 'ends_on', 'target_amount', 'currency', 'document_path', 'document_name', 'audience_id',
    'audience_criteria', 'scope_user_id', 'payroll_deduction', 'deduction_month', 'status', 'opened_at', 'opened_by', 'closed_at', 'closed_by', 'cancelled_at',
    'cancelled_by', 'cancel_reason', 'created_by'])]
class FundraiserCampaign extends Model
{
    use Auditable, BelongsToTenant;

    public const STATUSES = ['draft' => 'Draft', 'open' => 'Open', 'closed' => 'Closed', 'cancelled' => 'Cancelled'];

    /** Fields of the campaign itself; they change only while it is a draft. */
    public const CONTENT = ['name', 'purpose', 'beneficiary', 'starts_on', 'ends_on', 'target_amount', 'currency', 'document_path', 'document_name', 'audience_id', 'audience_criteria',
        'payroll_deduction', 'deduction_month'];

    protected $attributes = ['status' => 'draft', 'payroll_deduction' => false];

    protected static function booted(): void
    {
        static::saving(function (self $campaign): void {
            if (! array_key_exists((string) $campaign->status, self::STATUSES)) {
                throw new RuntimeException("Unknown fundraiser status [{$campaign->status}].");
            }
            $campaign->currency = strtoupper(trim((string) $campaign->currency));
        });
        static::updating(function (self $campaign): void {
            // Once open, the cause, window, target, audience and payroll terms are what donors were shown.
            if ($campaign->getRawOriginal('status') !== 'draft' && array_intersect(array_keys($campaign->getDirty()), self::CONTENT) !== []) {
                throw new RuntimeException('Only a draft fundraiser can be changed.');
            }
        });
        static::deleting(function (): void {
            throw new RuntimeException('Fundraisers are closed or cancelled, never deleted.');
        });
    }

    protected function casts(): array
    {
        return [
            'starts_on' => 'date', 'ends_on' => 'date', 'target_amount' => 'decimal:2', 'audience_criteria' => 'array', 'payroll_deduction' => 'boolean',
            'opened_at' => 'datetime', 'closed_at' => 'datetime', 'cancelled_at' => 'datetime',
        ];
    }

    public function auditModule(): string
    {
        return 'engagement';
    }

    public function auditLabel(): string
    {
        return "Fundraiser {$this->name}";
    }

    public function pledges(): HasMany
    {
        return $this->hasMany(FundraiserPledge::class, 'fundraiser_campaign_id');
    }

    public function audience(): BelongsTo
    {
        return $this->belongsTo(Audience::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? (string) $this->status;
    }

    /** The deduction month as "Oct 2026" (null when donors choose their month). */
    public function deductionMonthLabel(): ?string
    {
        return self::monthLabel($this->deduction_month);
    }

    public static function monthLabel(?string $month): ?string
    {
        return $month !== null && preg_match('/^(\d{4})-(\d{2})$/', $month, $m) === 1 ? date('M Y', mktime(0, 0, 0, (int) $m[2], 1, (int) $m[1])) : null;
    }
}
