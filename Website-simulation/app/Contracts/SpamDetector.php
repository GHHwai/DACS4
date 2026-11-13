<?php

namespace App\Contracts;

use App\Models\Message;

/**
 * Extension point for the AI spam / scam detection module.
 *
 * ChatService calls classify() right after a text message is stored (when
 * config('chat.spam.enabled') is true) and persists the verdict on the message
 * (spam_label, spam_score, scanned_at). Return null to skip / "no opinion".
 */
interface SpamDetector
{
    /**
     * @return array{label: string, score: float}|null  label: ham|spam|scam, score: 0..1
     */
    public function classify(Message $message): ?array;
}
