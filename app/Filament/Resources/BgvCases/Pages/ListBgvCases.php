<?php

namespace App\Filament\Resources\BgvCases\Pages;

use App\Filament\Resources\BgvCases\BgvCaseResource;
use App\Filament\Support\Pages\Concerns\HasRegisterTools;
use App\Filament\Support\Pages\PeopleListRecords;

class ListBgvCases extends PeopleListRecords
{
    use HasRegisterTools;

    protected static string $resource = BgvCaseResource::class;

    public static function registerKey(): string
    {
        return 'bgv_cases';
    }

    public static function presetViews(): array
    {
        return [
            'open' => ['label' => 'Open checks', 'filters' => ['status' => ['values' => ['initiated', 'in_progress']]]],
            'discrepancies' => ['label' => 'Discrepancies', 'filters' => ['overall_result' => ['value' => 'discrepancy']]],
        ];
    }

    public static function registerReports(): array
    {
        return ['bgv_open', 'bgv_discrepancies'];
    }

    protected function getHeaderActions(): array
    {
        return $this->registerToolActions();
    }
}
