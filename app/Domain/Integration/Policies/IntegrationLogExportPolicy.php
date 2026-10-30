<?php

namespace App\Domain\Integration\Policies;

use App\Domain\Identity\Models\User;
use App\Domain\Identity\Policies\PermissionPolicy;
use App\Domain\Integration\Models\IntegrationLogExport;
use App\Domain\Integration\Services\IntegrationLogExports;
use Illuminate\Database\Eloquent\Model;

/** HROne parity SV-26: a person sees only the log exports they asked for, while they may still export that log. */
class IntegrationLogExportPolicy extends PermissionPolicy
{
    protected string $resource = 'integration_log_export';

    public function viewAny(User $user): bool
    {
        $exports = app(IntegrationLogExports::class);

        return $exports->mayExport('webhook_deliveries', $user) || $exports->mayExport('api_request_logs', $user);
    }

    public function view(User $user, Model $model): bool
    {
        return $model instanceof IntegrationLogExport && (int) $model->requested_by === (int) $user->id && app(IntegrationLogExports::class)->mayExport($model->kind, $user);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, Model $model): bool
    {
        return false;
    }

    public function delete(User $user, Model $model): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
