<?php

namespace App\Policies;

use App\Models\Attachment;
use App\Models\User;

class AttachmentPolicy
{
    /** Members can open files posted after they joined the conversation. */
    public function view(User $user, Attachment $attachment): bool
    {
        $message = $attachment->message;
        $membership = $message->conversation->membership($user);

        return $membership !== null
            && (int) $message->id > (int) $membership->joined_after_message_id;
    }
}
