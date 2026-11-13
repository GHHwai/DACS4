<?php

namespace App\Chat;

use App\Models\ChatToken;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\RealtimeEvent;
use App\Models\User;
use App\Services\ChatService;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

/**
 * Real-time chat gateway.
 *
 * One non-blocking `stream_select` loop serving two kinds of client on the same port:
 *   - raw TCP        : newline-delimited JSON (or a few plain-text commands for netcat)
 *   - WebSocket      : the same JSON messages, one per text frame (used by the browser)
 *
 * The database is the source of truth. Web requests and socket commands both write
 * through ChatService; this process then notices new rows (messages by id, outbox events
 * by id) every ~250 ms and pushes them to whichever sockets belong to the recipients.
 * Typing indicators and presence are ephemeral and live only in memory here.
 *
 * See docs/PROTOCOL.md for the wire format.
 */
class Gateway
{
    private const POLL_INTERVAL = 0.25;
    private const HOUSEKEEPING_INTERVAL = 5.0;
    private const MAX_OUTBUF = 2_000_000;

    /** @var resource */
    private $server;

    /** @var array<string, Connection> by connection id */
    private array $connections = [];

    /** @var array<int, string> socket resource id => connection id */
    private array $bySocket = [];

    /** @var array<int, array<string, true>> user id => set of connection ids */
    private array $byUser = [];

    private int $lastMessageId = 0;
    private int $lastEventId = 0;
    private float $lastPoll = 0.0;
    private float $lastHousekeeping = 0.0;
    private float $lastPrune = 0.0;
    private int $nextId = 1;
    private bool $running = true;

    /** @var Closure(string):void */
    private Closure $logger;

    public function __construct(private ChatService $chat)
    {
        $this->logger = static fn (string $m) => null;
    }

    public function onLog(Closure $logger): void
    {
        $this->logger = $logger;
    }

    public function stop(): void
    {
        $this->running = false;
    }

    public function run(string $host, int $port): void
    {
        DB::connection()->disableQueryLog();

        $address = "tcp://{$host}:{$port}";
        $server = @stream_socket_server($address, $errno, $errstr);
        if (! $server) {
            throw new RuntimeException("Cannot listen on {$address}: {$errstr} ({$errno})");
        }
        stream_set_blocking($server, false);
        $this->server = $server;

        // Only deliver what happens from now on; clients re-sync via REST when they connect.
        $this->lastMessageId = (int) Message::max('id');
        $this->lastEventId = (int) RealtimeEvent::max('id');
        $this->log("Chat gateway listening on {$address} (raw TCP + WebSocket)");

        while ($this->running) {
            $read = [$this->server];
            $write = [];
            foreach ($this->connections as $c) {
                $read[] = $c->socket;
                if ($c->outbuf !== '') {
                    $write[] = $c->socket;
                }
            }
            $except = null;

            $ready = @stream_select($read, $write, $except, 0, 200_000);
            if ($ready === false) {
                continue; // interrupted by a signal
            }

            foreach ($read as $socket) {
                if ($socket === $this->server) {
                    $this->accept();
                } elseif (($conn = $this->connectionFor($socket)) !== null) {
                    $this->onReadable($conn);
                }
            }
            foreach ($write as $socket) {
                if (($conn = $this->connectionFor($socket)) !== null) {
                    $this->flush($conn);
                }
            }

            $now = microtime(true);
            if ($now - $this->lastPoll >= self::POLL_INTERVAL) {
                $this->lastPoll = $now;
                $this->poll();
            }
            if ($now - $this->lastHousekeeping >= self::HOUSEKEEPING_INTERVAL) {
                $this->lastHousekeeping = $now;
                $this->housekeeping($now);
            }
        }

        foreach ($this->connections as $c) {
            $this->close($c);
        }
        fclose($this->server);
    }

    /* ------------------------------------------------------------------ */
    /* Socket plumbing                                                      */
    /* ------------------------------------------------------------------ */

    private function accept(): void
    {
        $socket = @stream_socket_accept($this->server, 0, $peer);
        if (! $socket) {
            return;
        }
        if (count($this->connections) >= (int) config('chat.max_connections')) {
            fclose($socket);

            return;
        }

        stream_set_blocking($socket, false);
        $id = 'c'.$this->nextId++;
        $conn = new Connection($id, $socket, (string) $peer);
        $this->connections[$id] = $conn;
        $this->bySocket[(int) $socket] = $id;
    }

    /** @param  resource  $socket */
    private function connectionFor($socket): ?Connection
    {
        $id = $this->bySocket[(int) $socket] ?? null;

        return $id ? ($this->connections[$id] ?? null) : null;
    }

    private function onReadable(Connection $c): void
    {
        $data = @fread($c->socket, 65536);
        if ($data === false || ($data === '' && feof($c->socket))) {
            $this->close($c);

            return;
        }
        if ($data === '') {
            return;
        }

        $c->lastActivity = time();
        $c->inbuf .= $data;

        $limit = (int) config('chat.max_line_bytes');
        if (strlen($c->inbuf) > $limit * 2 + 1024) {
            $this->close($c);

            return;
        }

        try {
            $this->process($c);
        } catch (RuntimeException $e) {
            $this->log("{$c->id} protocol error: ".$e->getMessage());
            $this->close($c);
        } catch (Throwable $e) {
            $this->log("{$c->id} internal error: ".$e->getMessage());
            $this->close($c);
        }
    }

    private function process(Connection $c): void
    {
        if ($c->mode === 'unknown') {
            if (strlen($c->inbuf) < 4) {
                return;
            }
            $c->mode = str_starts_with($c->inbuf, 'GET ') ? 'http' : 'tcp';
        }

        match ($c->mode) {
            'http' => $this->processHttp($c),
            'ws' => $this->processWebSocket($c),
            'tcp' => $this->processTcp($c),
            default => null,
        };
    }

    /** Raw TCP: one command per line. */
    private function processTcp(Connection $c): void
    {
        $limit = (int) config('chat.max_line_bytes');

        while (! $c->closing && ($pos = strpos($c->inbuf, "\n")) !== false) {
            $line = trim(substr($c->inbuf, 0, $pos));
            $c->inbuf = (string) substr($c->inbuf, $pos + 1);
            if ($line === '') {
                continue;
            }
            if (strlen($line) > $limit) {
                $this->sendError($c, 'too_large', 'Line too long');
                $this->close($c);

                return;
            }
            $this->handleLine($c, $line, plainText: $line[0] !== '{');
        }

        if (! $c->closing && strlen($c->inbuf) > $limit) {
            $this->sendError($c, 'too_large', 'Line too long');
            $this->close($c);
        }
    }

    /** First bytes were "GET ": either a WebSocket upgrade or the /health probe. */
    private function processHttp(Connection $c): void
    {
        $end = strpos($c->inbuf, "\r\n\r\n");
        if ($end === false) {
            if (strlen($c->inbuf) > 8192) {
                $this->close($c);
            }

            return;
        }

        $head = substr($c->inbuf, 0, $end);
        $c->inbuf = (string) substr($c->inbuf, $end + 4);
        $lines = explode("\r\n", $head);
        $requestLine = array_shift($lines);
        $headers = [];
        foreach ($lines as $line) {
            if (str_contains($line, ':')) {
                [$k, $v] = explode(':', $line, 2);
                $headers[strtolower(trim($k))] = trim($v);
            }
        }

        $path = explode(' ', $requestLine)[1] ?? '/';
        $isUpgrade = strtolower($headers['upgrade'] ?? '') === 'websocket';

        if (! $isUpgrade) {
            $body = $path === '/health'
                ? json_encode(['status' => 'ok', 'connections' => count($this->connections), 'online_users' => count($this->byUser)])
                : 'This is the chat gateway. Connect with a WebSocket or raw TCP client.';
            $this->rawHttp($c, $path === '/health' ? '200 OK' : '426 Upgrade Required', $body);

            return;
        }

        $origin = $headers['origin'] ?? null;
        if ($origin !== null && ! $this->originAllowed($origin)) {
            $this->log("{$c->id} rejected origin {$origin}");
            $this->rawHttp($c, '403 Forbidden', 'Origin not allowed');

            return;
        }
        if (empty($headers['sec-websocket-key']) || ($headers['sec-websocket-version'] ?? '') !== '13') {
            $this->rawHttp($c, '400 Bad Request', 'Bad WebSocket handshake');

            return;
        }

        $accept = WebSocketCodec::acceptKey($headers['sec-websocket-key']);
        $c->outbuf .= "HTTP/1.1 101 Switching Protocols\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Accept: {$accept}\r\n\r\n";
        $c->mode = 'ws';
        $this->flush($c);

        if ($c->inbuf !== '') {
            $this->processWebSocket($c);
        }
    }

    private function processWebSocket(Connection $c): void
    {
        $max = (int) config('chat.max_line_bytes');

        foreach (WebSocketCodec::decode($c->inbuf, $max) as [$opcode, $fin, $payload]) {
            if ($c->closing) {
                return;
            }

            switch ($opcode) {
                case WebSocketCodec::OP_PING:
                    $c->outbuf .= WebSocketCodec::encode($payload, WebSocketCodec::OP_PONG);
                    $this->flush($c);
                    break;
                case WebSocketCodec::OP_PONG:
                    break;
                case WebSocketCodec::OP_CLOSE:
                    $this->close($c);

                    return;
                case WebSocketCodec::OP_TEXT:
                case WebSocketCodec::OP_CONT:
                    $c->fragment .= $payload;
                    if (strlen($c->fragment) > $max) {
                        throw new RuntimeException('Message too large');
                    }
                    if ($fin) {
                        $text = $c->fragment;
                        $c->fragment = '';
                        $this->handleLine($c, $text, plainText: false);
                    }
                    break;
                default:
                    throw new RuntimeException('Unsupported opcode');
            }
        }
    }

    private function rawHttp(Connection $c, string $status, string $body): void
    {
        $c->outbuf .= "HTTP/1.1 {$status}\r\nContent-Type: text/plain; charset=utf-8\r\nContent-Length: ".strlen($body)."\r\nConnection: close\r\n\r\n{$body}";
        $c->closing = true;
        $this->flush($c);
        $this->close($c);
    }

    private function originAllowed(string $origin): bool
    {
        $origin = rtrim(strtolower($origin), '/');
        foreach ((array) config('chat.allowed_origins') as $allowed) {
            if ($origin === rtrim(strtolower($allowed), '/')) {
                return true;
            }
        }

        return false;
    }

    /* ------------------------------------------------------------------ */
    /* Sending                                                              */
    /* ------------------------------------------------------------------ */

    /** @param  array<string, mixed>  $payload */
    private function send(Connection $c, array $payload): void
    {
        if ($c->closing || ! isset($this->connections[$c->id])) {
            return;
        }

        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        $c->outbuf .= $c->mode === 'ws' ? WebSocketCodec::encode($json) : $json."\n";

        if (strlen($c->outbuf) > self::MAX_OUTBUF) { // slow consumer
            $this->close($c);

            return;
        }
        $this->flush($c);
    }

    private function sendError(Connection $c, string $code, string $message, ?string $clientId = null): void
    {
        $this->send($c, array_filter([
            'type' => 'error', 'code' => $code, 'message' => $message, 'client_id' => $clientId,
        ], fn ($v) => $v !== null));
    }

    /** @param  array<string, mixed>  $payload */
    private function sendToUser(int $userId, array $payload, ?string $exceptConnection = null): void
    {
        foreach (array_keys($this->byUser[$userId] ?? []) as $id) {
            if ($id !== $exceptConnection && isset($this->connections[$id])) {
                $this->send($this->connections[$id], $payload);
            }
        }
    }

    private function flush(Connection $c): void
    {
        if ($c->outbuf === '' || ! is_resource($c->socket)) {
            return;
        }

        $written = @fwrite($c->socket, $c->outbuf);
        if ($written === false) {
            $this->close($c);

            return;
        }
        if ($written > 0) {
            $c->outbuf = (string) substr($c->outbuf, $written);
        }
    }

    private function close(Connection $c): void
    {
        if (! isset($this->connections[$c->id])) {
            return;
        }
        $c->closing = true;

        if ($c->mode === 'ws') {
            @fwrite($c->socket, WebSocketCodec::encode('', WebSocketCodec::OP_CLOSE));
        } elseif ($c->outbuf !== '') {
            @fwrite($c->socket, $c->outbuf); // best effort: deliver the last error / reply
        }

        unset($this->connections[$c->id], $this->bySocket[(int) $c->socket]);
        @fclose($c->socket);

        if ($c->user) {
            $uid = (int) $c->user->id;
            unset($this->byUser[$uid][$c->id]);
            if (empty($this->byUser[$uid])) {
                unset($this->byUser[$uid]);
                $this->userWentOffline($c->user);
            }
        }
    }

    /* ------------------------------------------------------------------ */
    /* Command handling                                                     */
    /* ------------------------------------------------------------------ */

    private function handleLine(Connection $c, string $line, bool $plainText): void
    {
        if ($plainText) {
            $cmd = $this->parsePlainText($line);
        } else {
            $cmd = json_decode($line, true);
            if (! is_array($cmd)) {
                $this->sendError($c, 'bad_json', 'Expected a JSON object');

                return;
            }
        }

        $type = (string) ($cmd['type'] ?? '');
        $clientId = isset($cmd['client_id']) ? Str::limit((string) $cmd['client_id'], 64, '') : null;

        // Anything except the handshake commands requires authentication first.
        if (! $c->isAuthenticated() && ! in_array($type, ['auth', 'login', 'ping', 'quit'], true)) {
            $this->sendError($c, 'unauthenticated', 'Send {"type":"auth","token":"..."} first', $clientId);

            return;
        }
        if (! $this->withinRateLimit($c)) {
            $this->sendError($c, 'rate_limited', 'Slow down', $clientId);

            return;
        }

        try {
            $this->dispatch($c, $type, $cmd, $clientId);
        } catch (ValidationException $e) {
            $this->sendError($c, 'invalid', (string) collect($e->errors())->flatten()->first(), $clientId);
        } catch (AuthorizationException $e) {
            $this->sendError($c, 'forbidden', $e->getMessage() ?: 'Not allowed', $clientId);
        } catch (Throwable $e) {
            $this->log("{$c->id} command '{$type}' failed: ".$e->getMessage());
            $this->sendError($c, 'internal_error', 'Something went wrong', $clientId);
            $this->recoverDatabase();
        }
    }

    /** @param  array<string, mixed>  $cmd */
    private function dispatch(Connection $c, string $type, array $cmd, ?string $clientId): void
    {
        switch ($type) {
            case 'ping':
                $this->send($c, ['type' => 'pong', 'time' => time()]);
                break;

            case 'quit':
                $this->send($c, ['type' => 'bye']);
                $this->close($c);
                break;

            case 'auth':
                $user = ChatToken::resolve((string) ($cmd['token'] ?? ''));
                if (! $user) {
                    $this->failLogin($c, 'invalid_token', 'Invalid or expired token');

                    return;
                }
                $this->authenticate($c, $user);
                break;

            case 'login': // convenience for raw TCP clients: e-mail / username + password
                $this->loginWithPassword($c, (string) ($cmd['login'] ?? $cmd['email'] ?? ''), (string) ($cmd['password'] ?? ''));
                break;

            case 'whoami':
                $this->send($c, ['type' => 'whoami', 'user' => $c->user->toPublic()]);
                break;

            case 'conversations':
                $this->send($c, ['type' => 'conversations', 'conversations' => $this->chat->summaries($c->user)]);
                break;

            case 'history':
                $conversation = Conversation::findOrFail((int) ($cmd['conversation_id'] ?? 0));
                $page = $this->chat->history(
                    $c->user, $conversation,
                    isset($cmd['before_id']) ? (int) $cmd['before_id'] : null,
                    isset($cmd['limit']) ? (int) $cmd['limit'] : null,
                );
                $this->send($c, ['type' => 'history', 'conversation_id' => (int) $conversation->id] + $page);
                break;

            case 'send':
                $conversation = Conversation::find((int) ($cmd['conversation_id'] ?? 0));
                if (! $conversation) {
                    throw new AuthorizationException('Conversation not found');
                }
                $message = $this->chat->send($c->user, $conversation, isset($cmd['body']) ? (string) $cmd['body'] : null);
                // Instant echo to the sender's connection; the poll loop will fan it out to everyone
                // (the sender's other devices included, deduplicated by message id on the client).
                $this->send($c, array_filter([
                    'type' => 'ack', 'client_id' => $clientId, 'message' => $message->toPayload(),
                ], fn ($v) => $v !== null));
                $this->lastPoll = 0.0; // deliver to the other members on the next loop iteration
                break;

            case 'read':
                $conversation = Conversation::find((int) ($cmd['conversation_id'] ?? 0));
                if (! $conversation) {
                    throw new AuthorizationException('Conversation not found');
                }
                $this->chat->markRead($c->user, $conversation, (int) ($cmd['message_id'] ?? 0));
                break;

            case 'typing':
                $this->typing($c, (int) ($cmd['conversation_id'] ?? 0));
                break;

            default:
                $this->sendError($c, 'unknown_command', "Unknown command '{$type}'", $clientId);
        }
    }

    /** Human-friendly commands so the server can be driven from plain netcat/telnet. */
    private function parsePlainText(string $line): array
    {
        $parts = preg_split('/\s+/', $line, 3);
        $verb = strtolower($parts[0] ?? '');

        return match ($verb) {
            'auth' => ['type' => 'auth', 'token' => $parts[1] ?? ''],
            'login' => ['type' => 'login', 'login' => $parts[1] ?? '', 'password' => $parts[2] ?? ''],
            'send' => ['type' => 'send', 'conversation_id' => (int) ($parts[1] ?? 0), 'body' => $parts[2] ?? ''],
            'history' => ['type' => 'history', 'conversation_id' => (int) ($parts[1] ?? 0), 'limit' => (int) ($parts[2] ?? 20)],
            'convs', 'conversations' => ['type' => 'conversations'],
            'whoami' => ['type' => 'whoami'],
            'ping' => ['type' => 'ping'],
            'quit', 'exit' => ['type' => 'quit'],
            default => ['type' => $verb],
        };
    }

    private function withinRateLimit(Connection $c): bool
    {
        $window = (int) config('chat.rate_limit.per_seconds');
        $max = (int) config('chat.rate_limit.max');
        $now = microtime(true);

        $c->recent = array_values(array_filter($c->recent, fn ($t) => $now - $t < $window));
        if (count($c->recent) >= $max) {
            return false;
        }
        $c->recent[] = $now;

        return true;
    }

    /* ------------------------------------------------------------------ */
    /* Authentication & presence                                            */
    /* ------------------------------------------------------------------ */

    private function loginWithPassword(Connection $c, string $login, string $password): void
    {
        $throttleKey = 'tcp-login:'.Str::lower($login).'|'.$c->remote;
        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            $this->failLogin($c, 'too_many_attempts', 'Too many attempts, try again later');

            return;
        }

        $field = filter_var($login, FILTER_VALIDATE_EMAIL) ? 'email' : 'username';
        $user = User::where($field, $field === 'email' ? $login : Str::lower($login))->first();

        if (! $user || ! Hash::check($password, $user->password)) {
            RateLimiter::hit($throttleKey, 60);
            $this->failLogin($c, 'invalid_credentials', 'Invalid credentials');

            return;
        }

        RateLimiter::clear($throttleKey);
        $this->authenticate($c, $user);
    }

    private function failLogin(Connection $c, string $code, string $message): void
    {
        $this->sendError($c, $code, $message);
        if (++$c->failedLogins >= 3) {
            $this->close($c);
        }
    }

    private function authenticate(Connection $c, User $user): void
    {
        if ($c->isAuthenticated()) {
            $this->sendError($c, 'already_authenticated', 'Already authenticated');

            return;
        }

        $uid = (int) $user->id;
        $first = empty($this->byUser[$uid]);
        $c->user = $user;
        $this->byUser[$uid][$c->id] = true;

        $friendIds = $user->friendIds();
        $this->send($c, [
            'type' => 'auth_ok',
            'user' => $user->toPublic(),
            'online_friend_ids' => array_values(array_filter($friendIds, fn ($id) => isset($this->byUser[$id]))),
        ]);
        $this->log("{$c->id} authenticated as @{$user->username} ({$c->mode})");

        if ($first) {
            foreach ($friendIds as $friendId) {
                $this->sendToUser($friendId, ['type' => 'presence', 'user_id' => $uid, 'online' => true]);
            }
        }
    }

    private function userWentOffline(User $user): void
    {
        try {
            $user->forceFill(['last_seen_at' => now()])->saveQuietly();
            foreach ($user->friendIds() as $friendId) {
                $this->sendToUser($friendId, ['type' => 'presence', 'user_id' => (int) $user->id, 'online' => false]);
            }
        } catch (Throwable $e) {
            $this->log('presence update failed: '.$e->getMessage());
            $this->recoverDatabase();
        }
    }

    private function typing(Connection $c, int $conversationId): void
    {
        $now = microtime(true);
        if ($now - ($c->typingAt[$conversationId] ?? 0) < 1.5) {
            return;
        }
        $c->typingAt[$conversationId] = $now;

        $conversation = Conversation::find($conversationId);
        if (! $conversation || ! $conversation->hasMember($c->user)) {
            throw new AuthorizationException('Conversation not found');
        }

        $payload = ['type' => 'typing', 'conversation_id' => $conversationId, 'user' => $c->user->toPublic()];
        foreach ($conversation->memberIds() as $memberId) {
            if ($memberId !== (int) $c->user->id) {
                $this->sendToUser($memberId, $payload);
            }
        }
    }

    /* ------------------------------------------------------------------ */
    /* Delivery from the database                                           */
    /* ------------------------------------------------------------------ */

    private function poll(): void
    {
        try {
            if (! $this->byUser) {
                // Nobody to deliver to: skip history instead of replaying it later.
                $this->lastMessageId = max($this->lastMessageId, (int) Message::max('id'));
                $this->lastEventId = max($this->lastEventId, (int) RealtimeEvent::max('id'));

                return;
            }

            $memberCache = [];
            $messages = Message::query()
                ->where('id', '>', $this->lastMessageId)
                ->orderBy('id')->limit(200)
                ->with(['user:id,name,username', 'attachments'])
                ->get();

            foreach ($messages as $message) {
                $this->lastMessageId = (int) $message->id;
                $members = $memberCache[$message->conversation_id] ??= DB::table('conversation_user')
                    ->where('conversation_id', $message->conversation_id)->pluck('user_id')->all();

                $payload = ['type' => 'message', 'message' => $message->toPayload()];
                foreach ($members as $memberId) {
                    if (isset($this->byUser[(int) $memberId])) {
                        $this->sendToUser((int) $memberId, $payload);
                    }
                }
            }

            $events = RealtimeEvent::query()
                ->where('id', '>', $this->lastEventId)
                ->orderBy('id')->limit(500)->get();

            foreach ($events as $event) {
                $this->lastEventId = (int) $event->id;
                if (isset($this->byUser[(int) $event->user_id])) {
                    $this->sendToUser((int) $event->user_id, [
                        'type' => 'event', 'event' => $event->type, 'data' => $event->payload ?? new \stdClass,
                    ]);
                }
            }
        } catch (Throwable $e) {
            $this->log('poll failed: '.$e->getMessage());
            $this->recoverDatabase();
        }
    }

    private function housekeeping(float $now): void
    {
        $authTimeout = (int) config('chat.auth_timeout');
        $idleTimeout = (int) config('chat.idle_timeout');
        $t = time();

        foreach ($this->connections as $c) {
            if (! $c->isAuthenticated() && $t - $c->connectedAt > $authTimeout) {
                $this->sendError($c, 'auth_timeout', 'Authenticate within '.$authTimeout.'s');
                $this->close($c);
                continue;
            }
            if ($t - $c->lastActivity > $idleTimeout) {
                $this->close($c);
                continue;
            }
            // Keep browsers (and proxies) from idling out; a pong refreshes lastActivity.
            if ($c->mode === 'ws' && $t - $c->lastActivity > 30) {
                $c->outbuf .= WebSocketCodec::encode('', WebSocketCodec::OP_PING);
                $this->flush($c);
            }
        }

        if ($now - $this->lastPrune > 300) {
            $this->lastPrune = $now;
            try {
                RealtimeEvent::where('created_at', '<', now()->subHour())->delete();
                ChatToken::where('expires_at', '<', now())->delete();
            } catch (Throwable $e) {
                $this->log('prune failed: '.$e->getMessage());
            }
        }
    }

    private function recoverDatabase(): void
    {
        try {
            DB::reconnect();
        } catch (Throwable) {
            // retried on the next tick
        }
    }

    private function log(string $message): void
    {
        ($this->logger)('['.date('H:i:s').'] '.$message);
    }
}
