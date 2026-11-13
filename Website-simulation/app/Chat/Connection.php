<?php

namespace App\Chat;

use App\Models\User;

/** State for one accepted socket (either a raw TCP client or a WebSocket client). */
final class Connection
{
    /** unknown -> (tcp | http -> ws) */
    public string $mode = 'unknown';

    public string $inbuf = '';
    public string $outbuf = '';

    public ?User $user = null;

    public int $connectedAt;
    public int $lastActivity;
    public int $failedLogins = 0;

    /** @var list<float> timestamps of recent commands (flood control) */
    public array $recent = [];

    /** @var array<int, float> conversation_id => last typing broadcast */
    public array $typingAt = [];

    // Fragmented WebSocket message reassembly
    public string $fragment = '';

    public bool $closing = false;

    /**
     * @param  resource  $socket
     */
    public function __construct(
        public string $id,
        public $socket,
        public string $remote,
    ) {
        $this->connectedAt = $this->lastActivity = time();
    }

    public function isAuthenticated(): bool
    {
        return $this->user !== null;
    }
}
