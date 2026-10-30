<?php

namespace App\Filament\Resources\Letters\Pages;

use App\Filament\Resources\Letters\LetterResource;
use App\Filament\Support\Pages\Concerns\HasRegisterTools;
use App\Filament\Support\Pages\PeopleListRecords;

class ListLetters extends PeopleListRecords
{
    use HasRegisterTools;

    protected static string $resource = LetterResource::class;

    public static function registerKey(): string
    {
        return 'letters';
    }

    /** HROne parity WO-1: the Workforce letters registers as views of the one Letters register (by type). */
    public static function presetViews(): array
    {
        return collect(['confirmation', 'transfer', 'relieving', 'experience', 'appointment', 'warning'])
            ->filter(fn (string $type) => array_key_exists($type, config('peopleos.letters.types')))
            ->mapWithKeys(fn (string $type) => [$type => ['label' => config("peopleos.letters.types.{$type}").' letters', 'filters' => ['type' => ['value' => $type]]]])
            ->put('pending', ['label' => 'Waiting for approval', 'filters' => ['status' => ['value' => 'pending_approval']]])
            // HROne parity GO-1: the Performance panel's letter registers (performance, PIP and PIP evaluation letters);
            // PO-1: the Payroll panel's "Salary revision letters" (increment letters).
            ->merge(collect(['performance' => 'Performance letters', 'pip' => 'PIP letters', 'pip_outcome' => 'PIP evaluation letters', 'increment' => 'Salary revision letters'])
                ->filter(fn (string $label, string $type) => array_key_exists($type, config('peopleos.letters.types')))
                ->map(fn (string $label, string $type) => ['label' => $label, 'filters' => ['type' => ['value' => $type]]]))
            ->all();
    }

    public static function registerReports(): array
    {
        return ['letters_issued_this_month', 'letters_pending'];
    }

    protected function getHeaderActions(): array
    {
        return [...$this->registerToolActions(), LetterResource::generate()];
    }
}
