<?php

namespace App\Domain\People\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Configuration\Services\OptionLists;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * HROne parity MS-4 (matrix 22 §22.2.3 #10, #12–14): one family member nominated for one benefit (option list
 * `nomination_benefits`) with a share. The nominations of a benefit are saved together through ChangeNominationsAction,
 * which checks that the shares total 100 and that a minor nominee has a guardian.
 */
#[Fillable(['tenant_id', 'person_id', 'family_member_id', 'benefit', 'share', 'address', 'remarks', 'guardian_name', 'guardian_relation'])]
class PersonNomination extends Model
{
    use Auditable, BelongsToTenant;

    protected function casts(): array
    {
        return [
            'share' => 'decimal:2',
        ];
    }

    public function benefitLabel(): string
    {
        return (string) (app(OptionLists::class)->label('nomination_benefits', (string) $this->benefit) ?? $this->benefit);
    }

    public function auditLabel(): string
    {
        return 'Nomination for '.$this->benefitLabel();
    }

    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    public function familyMember(): BelongsTo
    {
        return $this->belongsTo(PersonFamilyMember::class, 'family_member_id');
    }
}
