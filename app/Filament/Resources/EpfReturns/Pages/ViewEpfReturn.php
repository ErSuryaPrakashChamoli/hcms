<?php

namespace App\Filament\Resources\EpfReturns\Pages;

use App\Domain\Compliance\Models\EpfReturnEntry;
use App\Domain\Compliance\Models\StatutoryReturn;
use App\Domain\Compliance\Services\Returns\EpfReturns;
use App\Domain\Compliance\Services\Returns\StatutoryReturns;
use App\Filament\Resources\EpfReturns\EpfReturnResource;
use App\Filament\Support\Pages\PeopleViewRecord;
use App\Filament\Support\StatutoryReturnActions;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;

class ViewEpfReturn extends PeopleViewRecord
{
    protected static string $resource = EpfReturnResource::class;

    public function mount(int|string $record): void
    {
        parent::mount($record);
        app(StatutoryReturns::class)->recordAccess($this->getRecord(), StatutoryReturnActions::user(), 'ui', 'view');
    }

    protected function getHeaderActions(): array
    {
        return [
            ...StatutoryReturnActions::forReturn(),
            Action::make('revise')->label('Revise members')->color('warning')->requiresConfirmation()
                ->modalDescription('EPFO allows a revised return only when no other return of the month is in process; a downward revision only before payment is initiated.')
                ->visible(fn (StatutoryReturn $record) => in_array($record->status, [StatutoryReturn::APPROVED, StatutoryReturn::EXPORTED, StatutoryReturn::SUBMITTED, StatutoryReturn::ACKNOWLEDGED, StatutoryReturn::RECONCILED], true) && StatutoryReturnActions::user()->hasPermission('compliance.returns.generate'))
                ->schema(fn (StatutoryReturn $record) => [
                    Select::make('employee_ids')->label('Members to revise')->multiple()->required()
                        ->options(EpfReturnEntry::query()->where('statutory_return_id', $record->id)->pluck('member_name', 'employee_id')->all()),
                    Textarea::make('reason')->required()->rows(2),
                    Toggle::make('payment_not_initiated')->label('I confirm no payment has been initiated for this wage month'),
                ])
                ->action(fn (StatutoryReturn $record, array $data) => StatutoryReturnActions::run(fn () => app(EpfReturns::class)->generate(
                    $record->establishment, (int) $record->period_start->year, (int) $record->period_start->month, StatutoryReturnActions::user(), 'revised', array_map('intval', $data['employee_ids']), $data['reason'], (bool) ($data['payment_not_initiated'] ?? false),
                ), 'Revised return generated')),
        ];
    }
}
