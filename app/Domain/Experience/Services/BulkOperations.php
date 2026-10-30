<?php

namespace App\Domain\Experience\Services;

use App\Domain\Experience\Contracts\BulkOperation;
use App\Domain\Identity\Models\User;
use Illuminate\Contracts\Container\Container;
use RuntimeException;

/** HROne parity WO-1: the bulk operations registered under the `peopleos.bulk_operations` tag, by key. */
final class BulkOperations
{
    /** @var array<string, BulkOperation>|null */
    private ?array $operations = null;

    public function __construct(private readonly Container $app) {}

    /** @return array<string, BulkOperation> */
    public function all(): array
    {
        if ($this->operations === null) {
            $this->operations = [];
            foreach ($this->app->tagged('peopleos.bulk_operations') as $operation) {
                $this->operations[$operation->key()] = $operation;
            }
        }

        return $this->operations;
    }

    public function find(string $key): ?BulkOperation
    {
        return $this->all()[$key] ?? null;
    }

    public function get(string $key): BulkOperation
    {
        return $this->find($key) ?? throw new RuntimeException('Unknown bulk action.');
    }

    /** Whether the person may run the operation at all: bulk.run plus the operation's own permission. */
    public function allows(User $user, BulkOperation|string $operation): bool
    {
        $operation = is_string($operation) ? $this->find($operation) : $operation;

        return $operation !== null && $user->hasPermission('bulk.run') && $user->hasPermission($operation->permission());
    }
}
