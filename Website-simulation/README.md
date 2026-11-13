# Chatter: Zalo/Messenger-style chat simulation

Laravel 12 + SQLite web chat with authentication, authorization, friend list, 1:1 and group chats, file
attachments and a real-time gateway that speaks **raw TCP and WebSocket** on one port. It is the host platform for an
AI spam/scam message detector (see `docs/ROADMAP.md`).

```bash
composer install && cp .env.example .env && php artisan key:generate
touch database/database.sqlite && php artisan migrate
./scripts/dev.sh        # http://127.0.0.1:8000 (web) + :9501 (TCP/WebSocket gateway)
```

Register two accounts (use two browsers or a private window), add each other as friends, and chat.
Raw TCP client: `nc 127.0.0.1 9501` -> `login alice Password1` -> `convs` -> `send 1 hello`.

| Doc | What |
|---|---|
| `docs/PROGRESS.md` | status, architecture, how to test, gotchas, status log (**read first**) |
| `docs/ROADMAP.md` | backlog, AI spam-detection plan, next sprint |
| `docs/PROTOCOL.md` | TCP / WebSocket wire protocol |

Tests: `php artisan test` (PHPUnit) and `node tests/e2e/chat.e2e.cjs` (Playwright browser e2e).
