<?php

namespace App\Domain\People\Actions;

use App\Domain\People\Models\PersonExperience;

/**
 * HROne parity MS-3: the one write path for a person's earlier employment when it changes through an action (a
 * self-service profile change; HR's Previous employment tab keeps its own form).
 */
final class ChangeExperienceAction extends ChangePersonRecordAction
{
    protected function model(): string
    {
        return PersonExperience::class;
    }

    public function rules(): array
    {
        return [
            'employer' => ['required', 'string', 'max:255'],
            'designation' => ['nullable', 'string', 'max:255'],
            'from_date' => ['required', 'date', 'before_or_equal:today'],
            'to_date' => ['nullable', 'date', 'after_or_equal:from_date', 'before_or_equal:today'],
            'location' => ['nullable', 'string', 'max:255'],
            'responsibilities' => ['nullable', 'string', 'max:2000'],
            'reason_for_leaving' => ['nullable', 'string', 'max:255'],
            'is_verified' => ['nullable', 'boolean'],
        ];
    }

    protected function event(): string
    {
        return 'employee.experience_changed';
    }

    protected function label(): string
    {
        return 'Previous employment';
    }
}
