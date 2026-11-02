<?php

namespace App\Domain\People\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Configuration\Services\OptionLists;
use App\Domain\Employment\Exceptions\ProfileChangeRefused;
use App\Domain\Employment\Models\Employee;
use App\Domain\People\Support\PhoneNumbers;
use App\Support\Tenancy\BelongsToTenant;
use Database\Factories\PersonFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * The lifetime person record (blueprint §4). Everything about the human being lives here or in
 * a satellite; everything about the employment lives on Employee.
 */
#[UseFactory(PersonFactory::class)]
#[Fillable(['tenant_id', 'salutation', 'first_name', 'middle_name', 'last_name', 'preferred_name', 'date_of_birth', 'birthday_month', 'birthday_day', 'birthday_shared', 'wedding_anniversary_month', 'wedding_anniversary_day', 'wedding_anniversary_shared', 'directory_hidden', 'gender', 'marital_status', 'nationality', 'blood_group', 'is_fresher', 'personal_email', 'personal_phone_country_code', 'personal_phone', 'photo_path', 'metadata'])]
class Person extends Model
{
    /** @use HasFactory<PersonFactory> */
    use Auditable, BelongsToTenant, HasFactory;

    protected $table = 'people';

    protected $attributes = ['birthday_shared' => true, 'wedding_anniversary_shared' => false];

    public const MONTHS = [1 => 'January', 2 => 'February', 3 => 'March', 4 => 'April', 5 => 'May', 6 => 'June', 7 => 'July', 8 => 'August', 9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December'];

    protected static function booted(): void
    {
        // The day this person is celebrated: the celebrated birthday when one is recorded, else the date of birth.
        static::saving(function (Person $person): void {
            $month = $person->getAttributes()['birthday_month'] ?? null;
            $day = $person->getAttributes()['birthday_day'] ?? null;
            if ($month === null || $day === null || $month === '' || $day === '') {
                $person->birthday_month = $person->birthday_day = null;
            } elseif (! self::validBirthday((int) $month, (int) $day)) {
                throw new InvalidArgumentException("{$day}/{$month} is not a day of the year.");
            }
            $person->birthday_key = $person->birthdayKey();

            // HROne setup parity HP-9a: the wedding anniversary is a day and month only (no year); a month without a day
            // clears both.
            $month = $person->getAttributes()['wedding_anniversary_month'] ?? null;
            $day = $person->getAttributes()['wedding_anniversary_day'] ?? null;
            if ($month === null || $day === null || $month === '' || $day === '') {
                $person->wedding_anniversary_month = $person->wedding_anniversary_day = null;
            } elseif (! self::validBirthday((int) $month, (int) $day)) {
                throw new InvalidArgumentException("{$day}/{$month} is not a day of the year.");
            }

            // HROne parity MS-4: a phone with a country code is kept in E.164; without one, as typed (as before).
            PhoneNumbers::normaliseOn($person, 'personal_phone', 'personal_phone_country_code');
            // MS-4: "I am a fresher" means no earlier employment, so it cannot be set while experience rows exist.
            if ($person->exists && $person->isDirty('is_fresher') && $person->isFresher() && $person->experiences()->exists()) {
                throw new ProfileChangeRefused('Remove the earlier employment first: a fresher has no previous employer.');
            }
        });
    }

    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date',
            'birthday_month' => 'integer',
            'birthday_day' => 'integer',
            'birthday_key' => 'integer',
            'birthday_shared' => 'boolean',
            'wedding_anniversary_month' => 'integer',
            'wedding_anniversary_day' => 'integer',
            'wedding_anniversary_shared' => 'boolean',
            'is_fresher' => 'boolean',
            'directory_hidden' => 'array',
            'metadata' => 'array',
        ];
    }

    /** @return list<string> */
    public function auditExcludedAttributes(): array
    {
        return [...config('peopleos.audit.ignored_attributes', []), 'birthday_key'];
    }

    /** month * 100 + day of the day this person is celebrated, or null when neither date is known. */
    public function birthdayKey(): ?int
    {
        $attributes = $this->getAttributes();
        if (($attributes['birthday_month'] ?? null) && ($attributes['birthday_day'] ?? null)) {
            return (int) $attributes['birthday_month'] * 100 + (int) $attributes['birthday_day'];
        }
        $dob = $attributes['date_of_birth'] ?? null;

        return $dob ? ($date = Carbon::parse($dob))->month * 100 + $date->day : null;
    }

    /** "14 February" (never the year: colleagues see the day only). */
    public function birthdayLabel(): ?string
    {
        $key = $this->birthdayKey();

        return $key === null ? null : ($key % 100).' '.self::MONTHS[intdiv($key, 100)];
    }

    /** "14 February": the wedding anniversary, when recorded (HP-9a). */
    public function weddingAnniversaryLabel(): ?string
    {
        $month = $this->getAttributes()['wedding_anniversary_month'] ?? null;
        $day = $this->getAttributes()['wedding_anniversary_day'] ?? null;

        return $month && $day ? ((int) $day).' '.self::MONTHS[(int) $month] : null;
    }

    /** A celebrated birthday is recorded and differs from the date of birth. */
    public function celebratesOtherDay(): bool
    {
        $dob = $this->getAttributes()['date_of_birth'] ?? null;

        return ($this->getAttributes()['birthday_month'] ?? null) !== null
            && ($dob === null || $this->birthdayKey() !== ($date = Carbon::parse($dob))->month * 100 + $date->day);
    }

    /** Days a birthday can fall on in $month (29 for February). */
    public static function daysIn(int $month): int
    {
        return Carbon::create(2000, $month)->daysInMonth;
    }

    /** Whether a day exists in a month in some year (29 February counts). */
    public static function validBirthday(?int $month, ?int $day): bool
    {
        return $month !== null && $day !== null && $month >= 1 && $month <= 12 && $day >= 1 && $day <= self::daysIn($month);
    }

    public function auditLabel(): string
    {
        return $this->full_name;
    }

    /** HROne parity MS-4: "I am a fresher" (read safely on a just-created instance, where strict mode forbids a missing attribute). */
    public function isFresher(): bool
    {
        return (bool) ($this->getAttributes()['is_fresher'] ?? false);
    }

    /** HROne parity MS-4: the recorded salutation's label (option list `salutations`), or null when none is recorded. */
    public function salutationLabel(): ?string
    {
        $code = $this->getAttributes()['salutation'] ?? null;

        return $code === null || $code === '' ? null : app(OptionLists::class)->label('salutations', (string) $code);
    }

    protected function fullName(): Attribute
    {
        return Attribute::get(fn () => trim(collect([$this->first_name, $this->middle_name, $this->last_name])->filter()->implode(' ')));
    }

    protected function displayName(): Attribute
    {
        return Attribute::get(fn () => $this->preferred_name ?: $this->full_name);
    }

    public function employee(): HasOne
    {
        return $this->hasOne(Employee::class);
    }

    public function addresses(): HasMany
    {
        return $this->hasMany(PersonAddress::class);
    }

    public function familyMembers(): HasMany
    {
        return $this->hasMany(PersonFamilyMember::class);
    }

    public function emergencyContacts(): HasMany
    {
        return $this->hasMany(PersonEmergencyContact::class)->orderBy('priority');
    }

    public function qualifications(): HasMany
    {
        return $this->hasMany(PersonQualification::class)->orderByDesc('year_of_completion');
    }

    public function experiences(): HasMany
    {
        return $this->hasMany(PersonExperience::class)->orderByDesc('from_date');
    }

    public function certifications(): HasMany
    {
        return $this->hasMany(PersonCertification::class)->orderByDesc('issued_on');
    }

    public function skills(): BelongsToMany
    {
        return $this->belongsToMany(Skill::class, 'person_skills')
            ->withPivot(['id', 'proficiency', 'years_of_experience', 'last_used_on'])
            ->withTimestamps();
    }

    public function personSkills(): HasMany
    {
        return $this->hasMany(PersonSkill::class);
    }

    /** HROne parity MS-4: passport / driving licence (number encrypted). */
    public function identityDocuments(): HasMany
    {
        return $this->hasMany(PersonIdentityDocument::class)->orderBy('kind');
    }

    /** HROne parity MS-4: nominations per benefit. */
    public function nominations(): HasMany
    {
        return $this->hasMany(PersonNomination::class)->orderBy('benefit')->orderBy('id');
    }

    /** HROne parity MS-4: the qualification marked "This is the highest qualification". */
    public function highestQualification(): HasOne
    {
        return $this->hasOne(PersonQualification::class)->where('is_highest', true);
    }
}
