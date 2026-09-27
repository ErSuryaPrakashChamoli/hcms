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

/**
 * The lifetime person record (blueprint §4). Everything about the human being lives here or in
 * a satellite; everything about the employment lives on Employee.
 */
#[UseFactory(PersonFactory::class)]
#[Fillable(['tenant_id', 'first_name', 'middle_name', 'last_name', 'preferred_name', 'date_of_birth', 'gender', 'marital_status', 'nationality', 'blood_group', 'personal_email', 'personal_phone', 'photo_path', 'metadata'])]
class Person extends Model
{
    /** @use HasFactory<PersonFactory> */
    use Auditable, BelongsToTenant, HasFactory;

    protected $table = 'people';

    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date',
            'metadata' => 'array',
        ];
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
