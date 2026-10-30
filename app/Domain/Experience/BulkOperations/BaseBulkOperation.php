<?php

namespace App\Domain\Experience\BulkOperations;

use App\Domain\Employment\Models\Employee;
use App\Domain\Experience\Contracts\BulkOperation;
use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;

/** HROne parity WO-1: defaults for bulk operations (no inputs, no extra refusal, records named by their employee). */
abstract class BaseBulkOperation implements BulkOperation
{
    public function description(): string
    {
        return 'Each record is handled on its own; records you may not act on are listed with the reason.';
    }

    public function icon(): string
    {
        return 'heroicon-m-bolt';
    }

    public function color(): string
    {
        return 'primary';
    }

    public function schema(): array
    {
        return [];
    }

    public function prepare(array $data, User $actor): array
    {
        return $data;
    }

    public function with(): array
    {
        return [];
    }

    public function refusal(Model $record, User $actor): ?string
    {
        return null;
    }

    public function recordLabel(Model $record): string
    {
        $employee = $record instanceof Employee ? $record : (method_exists($record, 'employee') ? $record->loadMissing('employee.person')->getRelation('employee') : null);
        $prefix = $record instanceof Employee ? null : ($record->getAttributes()['number'] ?? null);
        $who = $employee instanceof Employee ? trim($employee->employee_code.' · '.($employee->loadMissing('person')->person?->display_name ?? ''), ' ·') : class_basename($record).' #'.$record->getKey();

        return mb_substr(($prefix ? $prefix.' · ' : '').$who, 0, 191);
    }

    /** Owning services refuse with a RuntimeException; one line for "only these states". */
    protected function refuse(string $message): never
    {
        throw new \RuntimeException($message);
    }
}
