<?php

namespace App\Domain\People\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Employment\Models\Employee;
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
#[Fillable(['tenant_id', 'first_name', 'middle_name', 'last_name', 'preferred_name', 'date_of_birth', 'birthday_month', 'birthday_day', 'birthday_shared', 'gender', 'marital_status', 'nationality', 'blood_group', 'personal_email', 'personal_phone', 'photo_path', 'metadata'])]
class Person extends Model
{
    /** @use HasFactory<PersonFactory> */
    use Auditable, BelongsToTenant, HasFactory;

    protected $table = 'people';

    protected $attributes = ['birthday_shared' => true];

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
}
