<?php

namespace App\Domain\People\Actions;

use App\Domain\People\Models\PersonQualification;
use App\Domain\People\Models\Qualification;
use App\Domain\People\Models\QualificationSpecialisation;
use Closure;

/**
 * HROne parity MS-3: the one write path for a person's education entries when they change through an action (a
 * self-service profile change; HR's Education tab keeps its own form). The qualification and specialisation masters
 * are looked up in the current tenant only; the model keeps their names in its text columns (CA-3).
 */
final class ChangeQualificationAction extends ChangePersonRecordAction
{
    protected function model(): string
    {
        return PersonQualification::class;
    }

    public function rules(): array
    {
        return [
            'qualification_id' => ['nullable', 'integer', self::exists(Qualification::class)],
            'specialisation_id' => ['nullable', 'integer', self::exists(QualificationSpecialisation::class)],
            'qualification' => ['required_without:qualification_id', 'nullable', 'string', 'max:255'],
            'specialisation' => ['nullable', 'string', 'max:255'],
            'institution' => ['nullable', 'string', 'max:255'],
            'board_or_university' => ['nullable', 'string', 'max:255'],
            'year_of_completion' => ['nullable', 'integer', 'min:1950', 'max:'.((int) now()->format('Y') + 6)],
            'grade_or_score' => ['nullable', 'string', 'max:32'],
            'is_verified' => ['nullable', 'boolean'],
        ];
    }

    protected function event(): string
    {
        return 'employee.qualification_changed';
    }

    protected function label(): string
    {
        return 'Education';
    }

    /** A master of the current tenant (the Eloquent query keeps the tenant scope; a plain `exists` rule would not). */
    private static function exists(string $class): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($class) {
            if ($value !== null && $value !== '' && ! $class::query()->whereKey((int) $value)->exists()) {
                $fail('Choose an entry from the list.');
            }
        };
    }
}
