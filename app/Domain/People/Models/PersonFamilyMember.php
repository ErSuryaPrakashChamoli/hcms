<?php

namespace App\Domain\People\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Employment\Exceptions\ProfileChangeRefused;
use App\Domain\Identity\Scopes\AccessScope;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

#[Fillable(['tenant_id', 'person_id', 'name', 'relation', 'date_of_birth', 'gender', 'is_dependent', 'is_nominee', 'nominee_share', 'in_school', 'in_hostel', 'phone', 'remarks', 'occupation'])]
class PersonFamilyMember extends Model
{
    use Auditable, BelongsToTenant;

    protected static function booted(): void
    {
        // HROne parity MS-4: a family member named on a nomination is removed from the nominations first, so a benefit's
        // shares never silently stop totalling 100.
        static::deleting(function (self $member): void {
            if (PersonNomination::query()->where('family_member_id', $member->getKey())->exists()) {
                throw new ProfileChangeRefused($member->name.' is a nominee: change the nominations first.');
            }
            // HROne parity MS-5: likewise a family member a health insurance policy covers (unscoped: the check is the data's).
            if (InsuredMember::query()->withoutGlobalScopes([AccessScope::class])->where('family_member_id', $member->getKey())->exists()) {
                throw new ProfileChangeRefused($member->name.' is covered by a health insurance policy: remove them from the policy first.');
            }
        });
    }

    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date',
            'is_dependent' => 'boolean',
            'is_nominee' => 'boolean',
            'nominee_share' => 'decimal:2',
            'in_school' => 'boolean',
            'in_hostel' => 'boolean',
        ];
    }

    public function auditLabel(): string
    {
        return $this->name.' ('.$this->relation.')';
    }

    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    /** HROne parity MS-4: a minor on $on (from the date of birth; unknown = not known to be a minor). */
    public function isMinor(?\DateTimeInterface $on = null): bool
    {
        $dob = $this->getAttributes()['date_of_birth'] ?? null;

        return $dob !== null && Carbon::parse($dob)->diffInYears($on ?? now()) < (int) config('peopleos.people.nominee_majority_age', 18);
    }

    public function nominations(): HasMany
    {
        return $this->hasMany(PersonNomination::class, 'family_member_id');
    }
}
