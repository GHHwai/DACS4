<?php

namespace App\Policies;

use App\Models\Conversation;
use App\Models\User;

/**
 * Authorization rules for conversations.
 *
 *  - view / send  : any member
 *  - manage       : group owner or admin (rename, add / remove members)
 *  - own          : group owner only (roles, transfer, delete)
 *  - leave        : any member of a group
 */
class ConversationPolicy
{
    public function view(User $user, Conversation $conversation): bool
    {
        return $conversation->hasMember($user);
    }

    public function send(User $user, Conversation $conversation): bool
    {
        return $conversation->hasMember($user);
    }

    public function manage(User $user, Conversation $conversation): bool
    {
        return $conversation->isGroup()
            && in_array($conversation->roleOf($user), [Conversation::ROLE_OWNER, Conversation::ROLE_ADMIN], true);
    }

    public function own(User $user, Conversation $conversation): bool
    {
        return $conversation->isGroup() && $conversation->roleOf($user) === Conversation::ROLE_OWNER;
    }

    public function leave(User $user, Conversation $conversation): bool
    {
        return $conversation->isGroup() && $conversation->hasMember($user);
    }
}
