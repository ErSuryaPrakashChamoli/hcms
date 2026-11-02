<?php

namespace App\Domain\Organisation\Imports\Concerns;

use App\Domain\Employment\Imports\EmployeeImport;
use App\Domain\Employment\Imports\EmployeeImportRow;
use App\Domain\Identity\Models\User;

/**
 * Data import DI-1 (decision D3): an upload may add records with the module's create permission (the upload's own
 * permission), but a row that changes a record on file needs the module's update permission too, as the record's edit
 * screen does. Without it such a row is an error naming the permission; rows that add records still run.
 */
trait RequiresUpdatePermission
{
    /** The permission a change to a record on file needs (organisation.update, people_setup.update). */
    abstract protected function updatePermission(): string;

    protected function check(array $r, array &$context, EmployeeImportRow $row, EmployeeImport $import): array
    {
        $decision = parent::check($r, $context, $row, $import);
        if ($decision['action'] !== 'update') {
            return $decision;
        }
        $context['may_update'] ??= (bool) User::query()->find($import->uploaded_by)?->hasPermission($this->updatePermission());

        return $context['may_update'] ? $decision : ['action' => 'error', 'errors' => ["This row changes a record on file, which needs the {$this->updatePermission()} permission; you may only add new records."]];
    }
}
