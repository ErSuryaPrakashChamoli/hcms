<?php

namespace App\Domain\People\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Configuration\Services\OptionLists;
use App\Domain\Employment\Models\Employee;
use App\Domain\Identity\Concerns\ScopedByEmployee;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * HROne parity MS-5 (matrix 22 §22.2.5 #1–4, §22.2.3 #3): a person a health insurance policy covers — the employee
 * (`member_key` "self") or one of the person's own family members ("family:{id}"), once per policy — with the relation,
 * date of birth, gender, whether the member is under the parents' cover, and the insurer's member id.
 *
 * The member id is highly sensitive: encrypted at rest, hidden from serialisation and masked in the audit trail; lists
 * show only the last four characters (`member_id_last4`, excluded from the audit). Revealing one is an audited sensitive
 * view (the 360's "Reveal", My profile's "Show" under PRV-1). Written only through InsuranceRecords.
 */
#[Fillable(['tenant_id', 'policy_id', 'employee_id', 'family_member_id', 'member_key', 'name', 'relation', 'date_of_birth', 'gender', 'member_id', 'is_parents_cover'])]
#[Hidden(['member_id'])]
class InsuredMember extends Model
{
    use Auditable, BelongsToTenant, ScopedByEmployee;

    public const SELF = 'self';

    protected static function booted(): void
    {
        static::saving(function (self $member): void {
            if ($member->isDirty('member_id')) {
                $value = $member->getAttributes()['member_id'] ?? null;
                $member->member_id_last4 = $value === null ? null : mb_substr((string) $member->member_id, -4);
            }
        });
    }

    protected function casts(): array
    {
        return [
            'member_id' => 'encrypted',
            'date_of_birth' => 'date',
            'is_parents_cover' => 'boolean',
        ];
    }

    public static function keyFor(?int $familyMemberId): string
    {
        return $familyMemberId === null ? self::SELF : 'family:'.$familyMemberId;
    }

    public function isSelf(): bool
    {
        return $this->member_key === self::SELF;
    }

    public function relationLabel(): string
    {
        return $this->relation === self::SELF ? 'Self' : (string) (app(OptionLists::class)->label('family_relations', (string) $this->relation) ?? ucfirst((string) $this->relation));
    }

    public function genderLabel(): ?string
    {
        return $this->gender === null ? null : (string) config('peopleos.people.genders.'.$this->gender, $this->gender);
    }

    /** "••••1234", or null when no member id is on file. */
    public function maskedMemberId(): ?string
    {
        $last4 = $this->getAttributes()['member_id_last4'] ?? null;

        return $last4 === null ? null : '••••'.$last4;
    }

    public function auditLabel(): string
    {
        return 'Insured member '.$this->name.' ('.$this->relationLabel().')';
    }

    /** @return list<string> */
    public function auditSensitiveAttributes(): array
    {
        return ['member_id'];
    }

    /** @return list<string> */
    public function auditExcludedAttributes(): array
    {
        return [...config('peopleos.audit.ignored_attributes', []), 'member_id_last4'];
    }

    public function policy(): BelongsTo
    {
        return $this->belongsTo(InsurancePolicy::class, 'policy_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function familyMember(): BelongsTo
    {
        return $this->belongsTo(PersonFamilyMember::class, 'family_member_id');
    }
}
