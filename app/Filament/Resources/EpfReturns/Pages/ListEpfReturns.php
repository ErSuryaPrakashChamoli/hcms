<?php

namespace App\Filament\Resources\EpfReturns\Pages;

use App\Domain\Compliance\Services\Returns\EpfReturns;
use App\Domain\Organisation\Models\Establishment;
use App\Filament\Resources\EpfReturns\EpfReturnResource;
use App\Filament\Support\Pages\PeopleListRecords;
use App\Filament\Support\StatutoryReturnActions;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Illuminate\Support\Carbon;

class ListEpfReturns extends PeopleListRecords
{
    protected static string $resource = EpfReturnResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('generate')->label('Generate ECR')
                ->visible(fn () => StatutoryReturnActions::user()->hasPermission('compliance.returns.generate'))
                ->schema([
                    Select::make('establishment_id')->label('Establishment')->required()->options(fn () => Establishment::query()->orderBy('name')->pluck('name', 'id')->all()),
                    Select::make('year')->required()->default(now()->subMonthNoOverflow()->year)->options(collect(range(now()->year - 2, now()->year))->mapWithKeys(fn ($y) => [$y => $y])->all()),
                    Select::make('month')->required()->default(now()->subMonthNoOverflow()->month)->options(collect(range(1, 12))->mapWithKeys(fn ($m) => [$m => Carbon::create(null, $m, 1)->format('F')])->all()),
                    Select::make('kind')->required()->default('regular')->options(['regular' => 'Regular', 'supplementary' => 'Supplementary (new members)']),
                    Textarea::make('reason')->rows(2),
                ])
                ->action(fn (array $data) => StatutoryReturnActions::run(fn () => app(EpfReturns::class)->generate(
                    Establishment::query()->findOrFail($data['establishment_id']), (int) $data['year'], (int) $data['month'], StatutoryReturnActions::user(), $data['kind'], null, $data['reason'] ?? null,
                ), 'ECR generated from finalized payroll')),
        ];
    }
}
