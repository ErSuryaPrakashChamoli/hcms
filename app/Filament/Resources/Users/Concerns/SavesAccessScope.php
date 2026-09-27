<?php

namespace App\Filament\Resources\Users\Concerns;

use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\AccessScopes;

/** Moves the `access_scope` form state in and out of user_access_scopes (Phase 0.2 ABAC). */
trait SavesAccessScope
{
    /** @var array<string, list<int>>|null */
    protected ?array $pendingAccessScope = null;

    /** @param  array<string, mixed>  $data */
    protected function extractAccessScope(array $data): array
    {
        if (array_key_exists('access_scope', $data)) {
            $this->pendingAccessScope = array_map(fn ($ids) => array_values(array_map('intval', (array) $ids)), array_filter($data['access_scope'] ?? []));
            unset($data['access_scope']);
        }

        return $data;
    }

    protected function persistAccessScope(User $user, ?string $reason = null): void
    {
        if ($this->pendingAccessScope === null) {
            return;
        }

        app(AccessScopes::class)->assign($user, $this->pendingAccessScope, $reason);
        $this->pendingAccessScope = null;
    }

    /** @return array<string, list<int>> */
    protected function currentAccessScope(User $user): array
    {
        return $user->accessScopes()->get()->groupBy('dimension')->map(fn ($rows) => $rows->pluck('scope_id')->map(fn ($id) => (int) $id)->all())->all();
    }
}
