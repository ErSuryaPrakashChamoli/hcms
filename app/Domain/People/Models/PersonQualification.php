<?php

namespace App\Domain\People\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['tenant_id', 'person_id', 'qualification_id', 'specialisation_id', 'qualification', 'specialisation', 'institution', 'board_or_university', 'year_of_completion', 'grade_or_score', 'is_verified'])]
class PersonQualification extends Model
{
    use Auditable, BelongsToTenant;

    protected static function booted(): void
    {
        // HROne setup parity CA-3: an entry picked from the qualification masters keeps their names in its text columns
        // (reports and imports read the text); typed text that matches a master links to it.
        static::saving(function (PersonQualification $entry): void {
            $attributes = $entry->getAttributes();
            $master = filled($attributes['qualification_id'] ?? null) ? Qualification::query()->find($attributes['qualification_id']) : null;
            if ($master === null && filled($attributes['qualification'] ?? null)) {
                $master = Qualification::query()->where('name', trim((string) $attributes['qualification']))->first();
            }
            $entry->qualification_id = $master?->id;
            if ($master !== null) {
                $entry->qualification = $master->name;
            }
            $specialisation = null;
            if ($master !== null && filled($attributes['specialisation_id'] ?? null)) {
                $specialisation = $master->specialisations()->whereKey($attributes['specialisation_id'])->first();
            } elseif ($master !== null && filled($attributes['specialisation'] ?? null)) {
                $specialisation = $master->specialisations()->where('name', trim((string) $attributes['specialisation']))->first();
            }
            $entry->specialisation_id = $specialisation?->id;
            if ($specialisation !== null) {
                $entry->specialisation = $specialisation->name;
            }
            if (blank($entry->getAttributes()['qualification'] ?? null)) {
                throw new \RuntimeException('Name the qualification.');
            }
        });
    }

    protected function casts(): array
    {
        return [
            'year_of_completion' => 'integer',
            'is_verified' => 'boolean',
        ];
    }

    public function auditLabel(): string
    {
        return $this->qualification;
    }

    public function master(): BelongsTo
    {
        return $this->belongsTo(Qualification::class, 'qualification_id');
    }

    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }
}
