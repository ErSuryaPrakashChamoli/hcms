<?php

namespace App\Filament\Resources\Dashboards\Pages;

use App\Domain\Analytics\Services\DashboardViews;
use App\Filament\Resources\Dashboards\DashboardResource;
use App\Filament\Support\GovernedEdit;
use App\Filament\Support\Pages\PeopleEditRecord;
use Filament\Actions\DeleteAction;

class EditDashboard extends PeopleEditRecord
{
    use GovernedEdit;

    protected static string $resource = DashboardResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }

    /** HROne parity CA-10: the view's one organisation dimension and its values, as the form edits them. */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        return app(DashboardViews::class)->fill($data);
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        return app(DashboardViews::class)->normalise($data, $this->getRecord()->getAttributes()['preset'] ?? null, auth()->user());
    }
}
