<?php

namespace App\Filament\Support\Pages;

use App\Domain\Employment\Imports\EmployeeImport;
use App\Domain\Employment\Imports\ImportTypes;
use Filament\Notifications\Notification;
use Livewire\Attributes\On;
use RuntimeException;

/**
 * An upload's own page (owner request 2026-11-26): after the columns are chosen again (ColumnMatch) or a name in the file
 * is settled (ReferenceReview: an existing record chosen, a new one added, or "Check the file again"), the file is checked
 * again and the page reloads, so its counts, rows and review show the new result.
 */
trait RechecksUploadReview
{
    #[On('upload-rechecked')]
    public function uploadRechecked(int $importId): void
    {
        if ($importId === (int) $this->getRecord()->getKey()) {
            $this->redirect(static::getUrl(['record' => $this->getRecord()]));
        }
    }

    #[On('references-resolved')]
    public function referencesResolved(string $scope, string $key = ''): void
    {
        /** @var EmployeeImport $import */
        $import = $this->getRecord()->refresh();
        if ($scope !== 'employee_imports:'.$import->getKey() || $import->status !== 'validated') {
            return;
        }
        try {
            ImportTypes::service($import->type)->validate($import);
        } catch (RuntimeException $e) {
            Notification::make()->danger()->title('The file could not be checked again')->body($e->getMessage())->send();

            return;
        }
        $this->redirect(static::getUrl(['record' => $import]));
    }
}
