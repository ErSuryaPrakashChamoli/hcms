<?php

namespace App\Filament\Resources\BankFileLayouts\Pages;

use App\Domain\Payroll\Services\BankFileLayouts;
use App\Filament\Resources\BankFileLayouts\BankFileLayoutResource;
use App\Filament\Support\Pages\PeopleManageRecords;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use RuntimeException;

class ManageBankFileLayouts extends PeopleManageRecords
{
    protected static string $resource = BankFileLayoutResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('create')->label('New layout')->icon(Heroicon::OutlinedPlus)->modalWidth('6xl')
                ->visible(fn () => auth()->user()->can('payroll.manage'))
                ->schema(BankFileLayoutResource::fields(true))
                ->action(function (array $data) {
                    try {
                        app(BankFileLayouts::class)->create(['code' => $data['code'], 'name' => $data['name'], 'notes' => $data['notes'] ?? null, 'specification' => BankFileLayoutResource::specification($data)], auth()->user());
                        Notification::make()->success()->title('Layout drafted; activate it when it is right')->send();
                    } catch (RuntimeException $e) {
                        Notification::make()->danger()->title('Not saved')->body($e->getMessage())->persistent()->send();
                    }
                }),
        ];
    }
}
