# CLAUDE.md: guidance for future Claude sessions

Project: simulated Zalo/Messenger-style chat website (`Website-simulation/`, Laravel 12 + SQLite) that hosts an
**AI spam/scam message detector** (university project, Agile).

**Start here, every session:**
1. `Website-simulation/docs/PROGRESS.md`: current status, architecture, run/test commands, gotchas, status log.
2. `Website-simulation/docs/ROADMAP.md`: backlog, next sprint, where the AI detector plugs in.
3. `Website-simulation/docs/PROTOCOL.md`: TCP/WebSocket wire format.

**Rules of thumb**
- All chat business rules go in `app/Services/ChatService.php` (used by REST *and* the TCP/WS gateway).
- No inline `<script>`/`style=""` in Blade views (strict CSP). Never `innerHTML` with user data.
- New rate limits: named limiters in `AppServiceProvider` (not `throttle:N,1`, which shares a counter).
- Restart `php artisan chat:serve` after `migrate:fresh` or any change under `app/Chat/`, `ChatService` or models
  (the gateway is a long-running process and does not hot-reload).
- Before finishing a task: `php artisan test` and, for UI/realtime changes, `node tests/e2e/chat.e2e.cjs`
  (fresh DB + servers running). Update `docs/PROGRESS.md` (status log) and tick off / add items in `docs/ROADMAP.md`.
- The user is in Vietnam (UTC+7); UI/data examples may mix Vietnamese and English. Vietnamese IME input must keep working.
