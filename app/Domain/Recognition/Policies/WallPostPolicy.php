<?php

namespace App\Domain\Recognition\Policies;

use App\Domain\Identity\Models\User;
use App\Domain\Recognition\Models\WallComment;
use App\Domain\Recognition\Models\WallPost;
use App\Domain\Recognition\Services\SocialWall;
use Illuminate\Database\Eloquent\Model;

/**
 * HROne setup parity HP-9e: the social wall. A post (and its comments and attachments) is seen as SocialWall decides
 * (the wall on, wall.view, its scope, the post's own audience); HR posts need wall.post; moderators (wall.moderate)
 * hide, restore and pin. Nothing is deleted.
 */
class WallPostPolicy
{
    public function viewAny(User $user): bool
    {
        return app(SocialWall::class)->canView($user);
    }

    public function view(User $user, Model $model): bool
    {
        $post = $model instanceof WallComment ? $model->post : $model;

        return $post instanceof WallPost && app(SocialWall::class)->canSee($user, $post);
    }

    public function create(User $user): bool
    {
        return app(SocialWall::class)->enabled() && $user->hasPermission('wall.post');
    }

    public function update(User $user, Model $model): bool
    {
        return app(SocialWall::class)->moderates($user);
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
