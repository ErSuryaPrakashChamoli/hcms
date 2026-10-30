<?php

namespace App\Filament\Resources\Dashboards\Pages;

use App\Domain\Analytics\Models\Dashboard;
use App\Domain\Analytics\Services\AnalyticsDefaults;
use App\Filament\Resources\Dashboards\DashboardResource;
use App\Filament\Support\Pages\PeopleListRecords;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;

class ListDashboards extends PeopleListRecords
{
    protected static string $resource = DashboardResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
            // HROne parity CA-10 (Access rights → CXO access): a ready-made view per HROne dashboard, shared with its author
            // until they choose its roles or people, organisation filter, e-mail and payroll refresh day.
            Action::make('addView')->label('Add a CXO view')->icon('heroicon-m-presentation-chart-bar')->color('gray')
                ->modalDescription('A ready-made view: you then choose who sees it, the part of the organisation it shows and its e-mail. Every figure follows each viewer\'s own rights and data access.')
                ->schema([
                    Select::make('preset')->label('View')->required()->options(fn () => array_map(fn ($p) => $p['label'].' — '.$p['description'], AnalyticsDefaults::PRESETS)),
                    TextInput::make('name')->label('Name')->maxLength(255)->placeholder('The preset\'s name'),
                ])
                ->action(function (array $data) {
                    $view = app(AnalyticsDefaults::class)->addPreset((string) $data['preset'], auth()->user(), $data['name'] ?? null);
                    Notification::make()->success()->title("\"{$view->name}\" added: choose who sees it")->send();
                    $this->redirect(DashboardResource::getUrl('edit', ['record' => $view]));
                }),
            // HROne setup parity EX-G (AN-07): the ready-made expense dashboard, for organisations that use expenses.
            Action::make('addExpenseDashboard')->label('Add the expense dashboard')->icon('heroicon-m-receipt-percent')->color('gray')
                ->visible(fn () => ! Dashboard::query()->where('slug', AnalyticsDefaults::EXPENSE_DASHBOARD)->exists())
                ->requiresConfirmation()->modalDescription('Adds "Expense overview" (expense KPIs, expense by category, outstanding advances and the payment register) for the roles that see expenses.')
                ->action(function () {
                    app(AnalyticsDefaults::class)->seedExpense();
                    Notification::make()->success()->title('Expense dashboard added')->send();
                }),
        ];
    }
}
