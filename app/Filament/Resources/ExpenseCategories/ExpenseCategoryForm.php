<?php

namespace App\Filament\Resources\ExpenseCategories;

use App\Domain\Expense\Models\ExpenseCategory;
use App\Domain\Expense\Services\ExpenseCategories;

/**
 * The category form asks HROne's questions (hours and minutes, yes / no follow-ups); ExpenseCategories keeps the rules
 * in their stored shape. This maps one to the other.
 */
final class ExpenseCategoryForm
{
    /** @return array<string, mixed> form state of a category */
    public static function toForm(ExpenseCategory $category): array
    {
        $data = app(ExpenseCategories::class)->current($category);
        $data['hourly_constraints'] = array_map(fn (array $band) => [
            'from_hours' => intdiv((int) $band['from_minutes'], 60), 'from_minutes' => (int) $band['from_minutes'] % 60,
            'to_hours' => intdiv((int) $band['to_minutes'], 60), 'to_minutes' => (int) $band['to_minutes'] % 60, 'percent' => $band['percent'],
        ], (array) ($data['hourly_constraints'] ?? []));
        $rule = $data['attendance_rule'] ?? null;
        $data['attendance_restricted'] = $rule !== null;
        $data['attendance_mode'] = $rule['mode'] ?? null;
        $data['attendance_min_hours'] = isset($rule['min_minutes']) ? intdiv((int) $rule['min_minutes'], 60) : null;
        $data['attendance_min_minutes'] = isset($rule['min_minutes']) ? (int) $rule['min_minutes'] % 60 : null;
        $data['attendance_on_duty'] = $rule['on_duty'] ?? null;
        $data['attendance_on_duty_types'] = $rule['on_duty_types'] ?? [];
        $window = $data['submission_window_days'] ?? null;
        $data['tenure_enabled'] = $window !== null;
        $data['tenure_from_days'] = $window['from'] ?? null;
        $data['tenure_to_days'] = $window['to'] ?? null;
        $data['booking_task'] = $data['booking_service_id'] !== null;
        $data['status'] = $category->status;

        return $data;
    }

    /** @return array<string, mixed> what ExpenseCategories::save() takes */
    public static function fromForm(array $data): array
    {
        $minutes = fn ($hours, $mins) => (int) $hours * 60 + (int) $mins;
        $data['hourly_constraints'] = array_values(array_map(fn (array $band) => [
            'from_minutes' => filled($band['from_hours'] ?? null) || filled($band['from_minutes'] ?? null) ? $minutes($band['from_hours'] ?? 0, $band['from_minutes'] ?? 0) : null,
            'to_minutes' => filled($band['to_hours'] ?? null) || filled($band['to_minutes'] ?? null) ? $minutes($band['to_hours'] ?? 0, $band['to_minutes'] ?? 0) : null,
            'percent' => $band['percent'] ?? null,
        ], (array) ($data['hourly_constraints'] ?? [])));
        $data['attendance_rule'] = (bool) ($data['attendance_restricted'] ?? false) ? [
            'mode' => $data['attendance_mode'] ?? null,
            'min_minutes' => filled($data['attendance_min_hours'] ?? null) || filled($data['attendance_min_minutes'] ?? null) ? $minutes($data['attendance_min_hours'] ?? 0, $data['attendance_min_minutes'] ?? 0) : null,
            'on_duty' => $data['attendance_on_duty'] ?? null,
            'on_duty_types' => $data['attendance_on_duty_types'] ?? [],
        ] : null;
        $data['submission_window_days'] = (bool) ($data['tenure_enabled'] ?? false) ? ['from' => $data['tenure_from_days'] ?? null, 'to' => $data['tenure_to_days'] ?? null] : null;
        if (! (bool) ($data['booking_task'] ?? false)) {
            $data['booking_service_id'] = null;
        }
        if (blank($data['claim_frequency_period'] ?? null)) {
            // The count is hidden (and not sent) once the frequency is cleared.
            $data['claim_frequency_period'] = $data['claim_frequency_count'] = null;
        }
        $data['limits'] = array_values((array) ($data['limits'] ?? []));
        foreach (['attendance_restricted', 'attendance_mode', 'attendance_min_hours', 'attendance_min_minutes', 'attendance_on_duty', 'attendance_on_duty_types', 'tenure_enabled', 'tenure_from_days', 'tenure_to_days', 'booking_task'] as $key) {
            unset($data[$key]);
        }

        return $data;
    }
}
