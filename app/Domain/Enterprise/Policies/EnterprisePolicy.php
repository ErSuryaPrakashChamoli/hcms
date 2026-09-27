<?php

namespace App\Domain\Enterprise\Policies;

use App\Domain\Enterprise\Models\ExchangeRate;
use App\Domain\Enterprise\Models\SsoConnection;
use App\Domain\Enterprise\Models\WebhookDelivery;
use App\Domain\Enterprise\Models\WebhookEndpoint;
use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;

/** One permission per enterprise object family. */
class EnterprisePolicy
{
    private function permissionFor(Model|string $model): string
    {
        $class = is_string($model) ? $model : $model::class;

        return match ($class) {
            SsoConnection::class => 'sso.manage',
            WebhookEndpoint::class, WebhookDelivery::class => 'webhook.manage',
            ExchangeRate::class => 'currency.manage',
            default => 'security.manage',
        };
    }

    public function viewAny(User $user): bool
    {
        return $user->hasPermission('sso.manage') || $user->hasPermission('webhook.manage') || $user->hasPermission('currency.manage') || $user->hasPermission('security.manage');
    }

    public function view(User $user, Model $model): bool
    {
        return $user->hasPermission($this->permissionFor($model));
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, Model $model): bool
    {
        return $user->hasPermission($this->permissionFor($model));
    }

    public function delete(User $user, Model $model): bool
    {
        return $user->hasPermission($this->permissionFor($model));
    }
}
