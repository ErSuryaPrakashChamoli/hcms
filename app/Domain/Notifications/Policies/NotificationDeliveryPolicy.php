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

    /** SaaS.8: sending a failed delivery again (NotificationRecovery checks this too, in the service). */
    public function resend(User $user, Model $model): bool
    {
        return $user->hasPermission('notification.resend') && $model->status === 'failed';
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
