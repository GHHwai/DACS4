<?php

namespace App\Services;

use App\Contracts\SpamDetector;
use App\Models\Message;

/** Placeholder until the AI model is integrated: never flags anything. */
class NullSpamDetector implements SpamDetector
{
    public function classify(Message $message): ?array
    {
        return null;
    }
}
