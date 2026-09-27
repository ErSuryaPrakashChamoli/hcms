<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\Concerns\SavesAccessScope;
use App\Filament\Resources\Users\UserResource;
use App\Filament\Support\GovernedEdit;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditUser extends EditRecord
{
    use GovernedEdit, SavesAccessScope;

    protected static string $resource = UserResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['access_scope'] = $this->currentAccessScope($this->getRecord());

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        return $this->extractAccessScope($data);
    }

    protected function afterSave(): void
    {
        $this->persistAccessScope($this->getRecord(), $this->data['audit_reason'] ?? null);
    }

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
