<?php

namespace App\Domain\Compliance\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Identity\Concerns\ScopedByOrganisation;
use App\Domain\Identity\Models\User;
use App\Domain\Organisation\Models\Establishment;
use App\Domain\Organisation\Models\LegalEntity;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * HROne parity PO-13 (matrix 25 §25.6.12 #8, #10, #11, #15, #16): one challan payment in the statutory challan register — a
 * month's PF / ESI / PT / LWF contribution for an establishment, or the TDS deducted from salaries for a legal entity (its
 * TAN). Recorded through StatutoryChallans only: the payment facts are never edited afterwards (a wrong challan is
 * cancelled with a reason and recorded again); only the receipt (while recorded), verification and cancellation change.
 * Never deleted.
 */
#[Fillable(['tenant_id', 'company_id', 'legal_entity_id', 'establishment_id', 'statutory_registration_id', 'statutory_return_id', 'statute', 'state_code', 'financial_year', 'period_month', 'alias', 'challan_number', 'acknowledgement_number', 'bsr_code', 'nature_of_payment', 'paid_on', 'payment_mode', 'bank_name', 'branch', 'amount', 'interest', 'penalty', 'receipt_path', 'receipt_name', 'notes', 'status', 'active_key', 'recorded_by', 'recorded_at', 'verified_by', 'verified_at', 'cancelled_by', 'cancelled_at', 'cancel_reason'])]
class StatutoryChallan extends Model
{
    use Auditable, BelongsToTenant, ScopedByOrganisation;

    public const RECORDED = 'recorded';

    public const VERIFIED = 'verified';

    public const CANCELLED = 'cancelled';

    public const STATUSES = [self::RECORDED => 'Recorded', self::VERIFIED => 'Verified', self::CANCELLED => 'Cancelled'];

    /** statute => [label, statutory return type, registration type that identifies the payer] */
    public const STATUTES = [
        'pf' => ['label' => 'Provident fund', 'return_type' => 'EPF', 'registration' => 'epf_establishment_code', 'scope' => 'establishment'],
        'esi' => ['label' => 'Employee state insurance', 'return_type' => 'ESI', 'registration' => 'esic_employer_code', 'scope' => 'establishment'],
        'pt' => ['label' => 'Professional tax', 'return_type' => 'PT', 'registration' => 'pt_registration_certificate', 'scope' => 'establishment'],
        'lwf' => ['label' => 'Labour welfare fund', 'return_type' => 'LWF', 'registration' => 'lwf_registration', 'scope' => 'establishment'],
        'tds' => ['label' => 'Income tax', 'return_type' => 'TDS', 'registration' => 'tan', 'scope' => 'legal_entity'],
    ];

    /** What may change after the challan is recorded (everything else is the payment as made). */
    public const MUTABLE = ['status', 'active_key', 'receipt_path', 'receipt_name', 'verified_by', 'verified_at', 'cancelled_by', 'cancelled_at', 'cancel_reason', 'updated_at'];

    public string $accessScopeDimension = 'statutory_return';

    protected static function booted(): void
    {
        static::updating(function (StatutoryChallan $challan) {
            $changed = array_diff(array_keys($challan->getDirty()), self::MUTABLE);
            if ($changed !== []) {
                throw new RuntimeException('A recorded challan is not edited ('.implode(', ', $changed).'); cancel it and record it again.');
            }
            if ($challan->getRawOriginal('status') === self::CANCELLED) {
                throw new RuntimeException('A cancelled challan stays as it was.');
            }
        });

        static::deleting(fn () => throw new RuntimeException('Challans are never deleted; cancel them instead.'));
    }

    protected function casts(): array
    {
        return [
            'period_month' => 'date',
            'paid_on' => 'date',
            'amount' => 'decimal:2',
            'interest' => 'decimal:2',
            'penalty' => 'decimal:2',
            'recorded_at' => 'datetime',
            'verified_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function total(): float
    {
        return round((float) $this->amount + (float) $this->interest + (float) $this->penalty, 2);
    }

    public function statuteLabel(): string
    {
        return self::STATUTES[$this->statute]['label'] ?? strtoupper((string) $this->statute);
    }

    public function monthLabel(): string
    {
        return Carbon::parse($this->getAttributes()['period_month'])->format('M Y');
    }

    public function label(): string
    {
        return $this->statuteLabel().' challan '.$this->challan_number.' · '.$this->monthLabel();
    }

    public function isCancelled(): bool
    {
        return $this->status === self::CANCELLED;
    }

    public function auditModule(): string
    {
        return 'compliance';
    }

    public function auditLabel(): string
    {
        return $this->label();
    }

    public function legalEntity(): BelongsTo
    {
        return $this->belongsTo(LegalEntity::class);
    }

    public function establishment(): BelongsTo
    {
        return $this->belongsTo(Establishment::class);
    }

    public function registration(): BelongsTo
    {
        return $this->belongsTo(StatutoryRegistration::class, 'statutory_registration_id');
    }

    public function statutoryReturn(): BelongsTo
    {
        return $this->belongsTo(StatutoryReturn::class);
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(StatutoryChallanAllocation::class)->orderBy('id');
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function canceller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }
}
