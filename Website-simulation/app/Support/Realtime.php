<?php

namespace App\Support;

use App\Models\RealtimeEvent;

/**
 * Outbox for push notifications. Web requests (a different PHP process from the
 * socket gateway) record events here; the gateway polls the table and delivers
 * them to whichever sockets the target users currently have open.
 */
final class Realtime
{
    /**
     * @param  iterable<int>  $userIds
     * @param  array<string, mixed>  $payload
     */
    public static function push(iterable $userIds, string $type, array $payload = []): void
    {
        $now = now();
        $rows = [];
        foreach (array_unique(is_array($userIds) ? $userIds : iterator_to_array($userIds)) as $id) {
            $rows[] = [
                'user_id' => (int) $id,
                'type' => $type,
                'payload' => json_encode($payload),
                'created_at' => $now,
            ];
        }

        if ($rows) {
            RealtimeEvent::insert($rows);
        }
    }
}
