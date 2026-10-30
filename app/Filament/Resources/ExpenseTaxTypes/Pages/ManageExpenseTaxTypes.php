<?php

namespace App\Filament\Resources\ExpenseTaxTypes\Pages;

use App\Domain\Expense\Services\ExpenseDefaults;
use App\Filament\Resources\ExpenseTaxTypes\ExpenseTaxTypeResource;
use App\Filament\Support\Pages\PeopleManageRecords;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;

class ManageExpenseTaxTypes extends PeopleManageRecords
{
    protected static string $resource = ExpenseTaxTypeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Add tax'),
            // Tenants created before the expense module (or that removed them) can add their country's starter tax heads.
            Action::make('starterTaxes')->label('Add the standard tax types')->icon(Heroicon::OutlinedPlusCircle)->color('gray')
                ->visible(fn () => ExpenseTaxTypeResource::canCreate() && app(ExpenseDefaults::class)->hasTaxDefaults())
                ->requiresConfirmation()
                ->modalDescription('Adds the standard tax heads of your country that are missing (names only, no rates). Existing tax types are left as they are.')
                ->action(function () {
                    abort_unless(ExpenseTaxTypeResource::canCreate(), 403);
                    $added = app(ExpenseDefaults::class)->seedTaxTypes();
                    Notification::make()->success()->title($added === 0 ? 'Every standard tax type is already there' : "{$added} tax type(s) added")->send();
                }),
        ];
    }
}
