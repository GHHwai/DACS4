# PROGRESS: Chatter (Zalo/Messenger-style chat simulation)

> Read this first when resuming work. Update the **Status log** at the end of every session.
> Context: this is the simulated chat website for the project *"AI system that detects spam/scam messages on a
> simulated website similar to Zalo/Messenger"* (Agile). The chat app is the **host platform**; the AI detector
> plugs into it (see `ROADMAP.md`).

## Status (2026-11-13): chat platform v0.1 is working end to end

| Area | State | Where |
|---|---|---|
| Authentication (register / login / logout, bcrypt, session, CSRF, login throttling) | Done | `AuthController`, `routes/web.php` |
| Authorization (policies + membership checks + group roles owner/admin/member) | Done | `app/Policies/*`, `ChatService` |
| TCP chat (raw line-JSON + plain-text netcat commands) | Done | `app/Chat/Gateway.php`, `docs/PROTOCOL.md` |
| WebSocket chat (same port, used by the browser) | Done | `Gateway`, `WebSocketCodec`, `public/js/chat.js` |
| Friend list (search, request, accept, decline, cancel, remove, online presence) | Done | `FriendController`, `Friendship` |
| 1:1 chats (friends only) with unread counts, "Seen", typing indicator, message recall | Done | `ChatService`, `chat.js` |
| Group chats (create, add/remove, rename, roles, transfer ownership, leave, delete, join-time history cutoff) | Done | `GroupController`, `ChatService` |
| Files + attachments (up to 5 x 20 MB, drag/drop, paste, inline images, private storage) | Done | `MessageController`, `AttachmentController` |
| Responsive UI + dark mode | Done | `public/css/app.css`, `resources/views` |
| Spam/scam hook points (`SpamDetector` contract, `messages.spam_*` columns, flagged-bubble UI) | Stubbed, no model yet | `app/Contracts/SpamDetector.php` |

Tests: `php artisan test` -> **9 passed**; browser e2e `node tests/e2e/chat.e2e.cjs` -> **46/46 passed** (see "How to test").

## Run it
```bash
cd Website-simulation
composer install && cp .env.example .env && php artisan key:generate
touch database/database.sqlite && php artisan migrate
./scripts/dev.sh            # web http://127.0.0.1:8000  +  gateway tcp/ws :9501
```
No npm/Vite build is needed: the UI is plain static JS/CSS in `public/`.
Try raw TCP: `nc 127.0.0.1 9501` then `login alice Password1`, `convs`, `send 1 hello`.

## How to test
```bash
php artisan test                                  # PHPUnit (in-memory SQLite)
php artisan migrate:fresh --force                 # e2e needs a FRESH db...
./scripts/dev.sh &                                # ...and both servers up (restart the gateway after any db reset!)
node tests/e2e/chat.e2e.cjs                       # needs Playwright; prints PASS/FAIL per check
```

## Architecture in one screen
- **Laravel 12 / PHP 8.3 / SQLite** (`DB_CONNECTION=sqlite`), session auth (database driver), no build step.
- **`ChatService` is the single place for chat rules.** REST controllers *and* the gateway call it, so TCP, WebSocket
  and HTTP can never disagree about who is allowed to do what. Put new business rules here, not in controllers.
- **Gateway** (`php artisan chat:serve`) = one `stream_select` loop, no extra PHP extensions. Web requests live in a
  different process, so they communicate through the DB: new `messages` rows are picked up by id, other things
  (friend events, recalls, read receipts, removals) go through the `realtime_events` outbox written by
  `App\Support\Realtime::push()`. Typing + presence are in-memory only.
- **Socket auth**: browser calls `POST /api/realtime/token` -> short-lived token (hash stored in `chat_tokens`) ->
  sends `{"type":"auth"}` as the first frame. TCP clients may use `login` instead.
- **Files** live on the *private* `local` disk (`storage/app/private/chat/...`), never under `public/`. They are
  streamed by `AttachmentController` after an `AttachmentPolicy` check. Only verified raster images (jpeg/png/gif/webp,
  by server-side MIME sniffing) render inline; everything else is `application/octet-stream` + `Content-Disposition:
  attachment` + `CSP: sandbox`, so an uploaded `.html/.svg` can never execute.
- **CSP** (`SecurityHeaders` middleware): `script-src 'self'; style-src 'self'` => **no inline `<script>`/`style=""`
  in Blade views.** Pass data via `data-*` attributes; set styles from JS via `el.style.x` (allowed).
- **Frontend** never uses `innerHTML` with user data (all DOM via `h()` helper + `textContent`).

## Data model (migrations `2026_11_09_00000x_*`)
`users` (+username, bio, is_admin, last_seen_at) · `friendships` (unique `pair_key` "minId-maxId", status
pending|accepted) · `conversations` (type direct|group, unique `direct_key`) · `conversation_user` (role,
`joined_after_message_id` = history cutoff, `last_read_message_id`) · `messages` (type text|file|system,
`deleted_at` = recalled, **`spam_label`, `spam_score`, `scanned_at`** reserved for the AI module) · `attachments` ·
`chat_tokens` · `realtime_events`.

## Gotchas learned the hard way (don't repeat)
1. **`throttle:30,1` shorthand shares ONE counter per user across all routes using it.** Group creation (10/min) was
   being starved by token/search/send calls (429). Use the *named* limiters in `AppServiceProvider::boot()`
   (`chat-group`, `chat-send`, ...). Add new limits there.
2. **PHP built-in server**: run it from `public/` with Laravel's `server.php` router (see `scripts/dev.sh`), and raise
   `upload_max_filesize` / `post_max_size` (defaults 2 MB / 8 MB break uploads; an oversized POST body even surfaces as a
   419 CSRF error).
3. **Restart `chat:serve` after `migrate:fresh`**: the gateway remembers the highest message/event id it has seen, so
   after a DB reset it would silently skip new low-id messages.
4. **Vietnamese IME**: Enter-to-send must ignore `keydown` while `e.isComposing` (Telex/VNI) or half-typed characters are
   sent. Already handled in `chat.js`; keep it.
5. The client throttles `typing` events to 1 per 2 s, so automated tests must wait >2 s between typing bursts.
6. `User::$fillable` deliberately excludes `is_admin` (covered by a test). Keep it that way.

## Known limitations (deliberate for v0.1; see ROADMAP)
Single gateway process (polls DB every 250 ms; fine for a demo / SQLite, not for scale) · no TLS in the gateway (use a
reverse proxy for `wss://`) · no e-mail verification / password reset / 2FA · no message edit, reactions, replies,
voice/video · no message search · no block/report user · no push notifications · `last_seen` is stored but not shown ·
uploaded files are not virus-scanned.

## Status log
- **2026-11-13**: v0.1 delivered: auth, friends, direct + group chat over TCP/WebSocket, attachments, UI, docs, tests.
  Fixed shared-throttle bug found by e2e. Next up: see `ROADMAP.md` -> "Next sprint".
