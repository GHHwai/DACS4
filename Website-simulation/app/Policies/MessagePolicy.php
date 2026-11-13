<?php

namespace App\Policies;

use App\Models\Message;
use App\Models\User;

class MessagePolicy
{
    /** Users may recall their own messages, as long as they are still in the conversation. */
    public function delete(User $user, Message $message): bool
    {
        return $message->type !== Message::SYSTEM
            && $message->deleted_at === null
            && (int) $message->user_id === (int) $user->id
            && $message->conversation->hasMember($user);
    }
}
