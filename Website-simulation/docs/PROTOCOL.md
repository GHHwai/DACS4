# Chat gateway protocol (raw TCP + WebSocket)

`php artisan chat:serve` listens on one port (default **9501**) and auto-detects the client:

| Client | How it is detected | Framing |
|---|---|---|
| Raw TCP (netcat, custom client, bots) | first bytes are not `GET ` | one command per `\n`-terminated line |
| WebSocket (the browser UI) | first bytes are `GET ` + `Upgrade: websocket` | one command per text frame |
| `GET /health` | plain HTTP | JSON status (`connections`, `online_users`) |

Both carry the **same JSON messages**. Max line/frame: `chat.max_line_bytes` (64 KB). Browser WebSockets must come
from an origin in `CHAT_ALLOWED_ORIGINS`; non-browser clients send no `Origin` and are not checked.

## Authentication (required first; unauthenticated sockets are dropped after 10 s)
Two ways, both rate limited (3 failures closes the socket):

```json
{"type":"auth","token":"<short-lived token>"}
```
Token comes from `POST /api/realtime/token` (needs the web session; valid 10 min, stored only as a SHA-256 hash).
This is what the browser does before every (re)connect.

```json
{"type":"login","login":"alice","password":"Password1"}
```
Username-or-email + password; meant for raw TCP clients. Success reply:
`{"type":"auth_ok","user":{...},"online_friend_ids":[2,3]}`

## Netcat-friendly plain text commands (TCP only)
```
login alice Password1
convs
history 1 20
send 1 hello there
whoami | ping | quit
```
(Any line starting with `{` is parsed as JSON instead.)

## Client -> server
| `type` | Fields | Notes |
|---|---|---|
| `send` | `conversation_id`, `body`, `client_id?` | text only; files go through `POST /api/conversations/{id}/messages` (multipart). Reply: `ack` |
| `history` | `conversation_id`, `before_id?`, `limit?` | reply `history` |
| `conversations` | – | reply `conversations` |
| `read` | `conversation_id`, `message_id` | marks read up to that message |
| `typing` | `conversation_id` | server throttles to 1 per 1.5 s per conversation |
| `ping` / `quit` / `whoami` | – | |

## Server -> client
`auth_ok`, `ack {client_id, message}`, `message {message}`, `history`, `conversations`, `presence {user_id, online}`,
`typing {conversation_id, user}`, `error {code, message, client_id?}`, `pong`, `bye`, and
`event {event, data}` for outbox events: `friend_request`, `friend_accepted`, `friend_request_cancelled`,
`friend_removed`, `message_deleted`, `read`, `removed_from_conversation`.

Error codes: `unauthenticated`, `invalid_token`, `invalid_credentials`, `too_many_attempts`, `rate_limited`,
`invalid` (validation), `forbidden`, `too_large`, `auth_timeout`, `unknown_command`, `internal_error`.

## Message payload
```json
{"id":12,"conversation_id":3,"type":"text|file|system","user":{"id":1,"name":"Alice","username":"alice"},
 "body":"hi","deleted":false,"attachments":[{"id":1,"name":"a.png","mime":"image/png","size":69,"is_image":true,"url":"/attachments/1"}],
 "created_at":"2026-11-13T03:30:00+00:00","spam_label":null}
```

## Delivery model (important when changing things)
The **database is the source of truth**. Web requests and socket commands both write via `App\Services\ChatService`.
The gateway polls every 250 ms for new `messages` rows (by id) and new `realtime_events` rows and pushes them to
the recipients' sockets. `send` over a socket additionally gets an instant `ack`. The sender's own connections also
receive the later `message` push; clients dedupe by `message.id`. After a reconnect the browser re-syncs through REST.
