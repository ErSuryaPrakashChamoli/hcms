<?php

namespace App\Filament\Support;

use App\Domain\Compliance\Services\Returns\PayrollLineReturns;
use App\Domain\Organisation\Models\Establishment;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Illuminate\Support\Carbon;

/** "Generate" header action for monthly establishment returns (ESI, PT, LWF). */
final class GenerateMonthlyReturnAction
{
    /** @param  class-string<PayrollLineReturns>  $generator */
    public static function make(string $generator, string $label): Action
    {
        return Action::make('generate')->label($label)
            ->visible(fn () => StatutoryReturnActions::user()->hasPermission('compliance.returns.generate'))
            ->schema([
                Select::make('establishment_id')->label('Establishment')->required()->options(fn () => Establishment::query()->orderBy('name')->pluck('name', 'id')->all()),
                Select::make('year')->required()->default(now()->subMonthNoOverflow()->year)->options(collect(range(now()->year - 2, now()->year))->mapWithKeys(fn ($y) => [$y => $y])->all()),
                Select::make('month')->required()->default(now()->subMonthNoOverflow()->month)->options(collect(range(1, 12))->mapWithKeys(fn ($m) => [$m => Carbon::create(null, $m, 1)->format('F')])->all()),
                Textarea::make('reason')->rows(2),
            ])
            ->action(fn (array $data) => StatutoryReturnActions::run(fn () => app($generator)->generate(
                Establishment::query()->findOrFail($data['establishment_id']), (int) $data['year'], (int) $data['month'], StatutoryReturnActions::user(), $data['reason'] ?? null,
            ), 'Return generated from finalized payroll'));
    }
}
