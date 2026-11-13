<?php

namespace App\Policies;

use App\Models\Friendship;
use App\Models\User;

class FriendshipPolicy
{
    /** Only the person who received a pending request can accept it. */
    public function accept(User $user, Friendship $friendship): bool
    {
        return $friendship->status === Friendship::PENDING && (int) $friendship->addressee_id === (int) $user->id;
    }

    /** Either side can cancel / decline while the request is still pending. */
    public function cancel(User $user, Friendship $friendship): bool
    {
        return $friendship->status === Friendship::PENDING && $friendship->involves((int) $user->id);
    }
}
