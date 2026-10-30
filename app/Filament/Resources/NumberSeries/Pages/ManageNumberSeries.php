<?php

namespace App\Filament\Resources\NumberSeries\Pages;

use App\Filament\Resources\NumberSeries\NumberSeriesResource;
use App\Filament\Support\Pages\PeopleManageRecords;
use App\Support\Numbering\NumberSeries;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Support\Icons\Heroicon;

class ManageNumberSeries extends PeopleManageRecords
{
    protected static string $resource = NumberSeriesResource::class;

    public function getSubheading(): ?string
    {
        return 'Loans, letters, exit cases, alumni requests and tickets take the next number of their code type’s series: the company’s own, else the organisation-wide one, else the built-in format they have always used. Employee codes have their own screen.';
    }

    protected function getHeaderActions(): array
    {
        return [
            // Every code type with whether it is set up, including the ones that only use their built-in format.
            Action::make('codeTypes')->label('All code types')->icon(Heroicon::OutlinedListBullet)->color('gray')
                ->slideOver()->modalSubmitAction(false)->modalCancelActionLabel('Close')
                ->modalHeading('Code types')
                ->modalContent(fn () => view('filament.number-series.code-types', ['rows' => $this->codeTypes()])),
            CreateAction::make()->label('New series')->steps(NumberSeriesResource::wizardSteps()),
        ];
    }

    /**
     * The code types overview (module, code type, applicable on, configured?, pattern, next number).
     *
     * @return list<array<string, string>>
     */
    public function codeTypes(): array
    {
        return array_map(fn (array $row) => $row + ['configured_label' => NumberSeriesResource::configuredLabel($row['configured']),
            'applicable_label' => $row['applicable_on'] === 'company' ? 'Company' : 'Enterprise'], app(NumberSeries::class)->overview());
    }
}
