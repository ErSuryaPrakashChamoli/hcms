<?php

namespace App\Domain\Notifications\Policies;

use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;

class NotificationDeliveryPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('notification.deliveries');
    }

    public function view(User $user, Model $model): bool
    {
        return $user->hasPermission('notification.deliveries') || $model->user_id === $user->id;
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
}
