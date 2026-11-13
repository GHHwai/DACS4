# ROADMAP: future work and backlog

Ordered by value for the thesis goal (**detect spam/scam messages on this chat platform**), then general polish.
Agile style: epics -> user stories -> acceptance criteria. Move items to `PROGRESS.md` when done.

## Epic A: Spam/scam detection (the point of the project)

### Hook points that already exist (don't rebuild these)
- `App\Contracts\SpamDetector::classify(Message): ?array{label: ham|spam|scam, score: 0..1}`
- `AppServiceProvider::register()` binds it to `NullSpamDetector`. **Swap in the real implementation there.**
- `ChatService::scan()` runs the detector after each text message is stored (when `CHAT_SPAM_ENABLED=true`),
  saves `messages.spam_label / spam_score / scanned_at`, and never lets a detector failure block messaging.
- Message JSON already exposes `spam_label`; `chat.js` outlines flagged bubbles and shows
  "Flagged as possible spam/scam".

### Stories
1. **A1: Synchronous rule-based baseline** (first sprint; no ML risk). `RuleBasedSpamDetector`: URL extraction +
   shortener/lookalike-domain checks, scam keyword lists (Vietnamese + English: "trúng thưởng", "chuyển khoản gấp",
   "OTP", "đầu tư sinh lời", "việc nhẹ lương cao", fake bank/Zalo/Shopee/Momo/VNPost notices), phone/bank-account
   patterns, ALL-CAPS/urgency, repeated-message bursts. *Accept:* unit tests with >= 30 labelled examples; score + reasons.
2. **A2: ML/LLM classifier behind the same interface.** Options: (a) Python microservice (FastAPI) with a
   PhoBERT/TF-IDF + logistic baseline trained on a Vietnamese SMS/Zalo scam dataset; (b) call a hosted LLM with a
   strict JSON schema. Call it from a **queued job** so chat latency is unaffected, then push a `message_flagged`
   realtime event (`Realtime::push`) to update the bubble. Keep a 300-500 ms timeout + fail-open.
3. **A3: Store the *reasons*** (new JSON column `spam_reasons`) so the UI can explain "why was this flagged?".
4. **A4: User-facing behaviour**: warning banner on flagged messages, "Report as spam" / "This is safe" buttons
   (feedback -> training data), blur flagged links/attachments until the user clicks, optional auto-hide above a threshold.
5. **A5: Reporting + blocking**: `reports` table (reporter, message, reason, status), `blocks` table (blocked users
   can't message / friend-request). This also produces labelled data.
6. **A6: Behavioural signals**: friend-request spam (many requests/hour), new-account + link-in-first-message,
   mass-forward to groups, rate of unknown-sender DMs. Feed as features next to text.
7. **A7: Admin / analyst dashboard** (`users.is_admin` exists): flagged-message queue, precision/recall over time,
   confusion matrix, threshold slider, confirm/override labels. Use a Dashboard artifact or a Blade page.
8. **A8: Attachment & link scanning**: extension/MIME mismatch, hash blacklist, optional URL reputation lookup.
9. **A9: Evaluation harness**: dataset folder + `php artisan spam:evaluate` printing precision/recall/F1 per class;
   a *traffic simulator* (`php artisan chat:simulate --scammers=5`) that logs in over raw TCP and plays scripted
   scam/ham conversations, ideal for demos and for generating test traffic using the existing TCP protocol.
10. **A10: Privacy**: say in the UI that messages are scanned; log scores not full text outside the DB; retention policy.

**Definition of Done for the AI epic:** documented dataset + metrics (precision >= 0.9 on scam class on held-out set),
latency budget met (p95 added delay < 300 ms or async), failure mode = fail-open, covered by tests, noted in PROGRESS.md.

## Epic B: Product polish (Zalo/Messenger parity)
- Edit message, reply/quote, emoji reactions, @mentions, link previews, message search, pin messages.
- Show `last seen`, profile page (avatar upload, bio), per-conversation mute, delete-for-me, archive.
- Voice notes, image gallery view, upload progress bar (XHR/fetch streams), resumable large uploads.
- Browser notifications + sound; PWA manifest.
- Multi-device: already works (several sockets per user); add "active sessions" page.

## Epic C: Security & accounts
- E-mail verification, password reset, optional 2FA (TOTP), session management / "log out everywhere".
- Account lockout notifications, CAPTCHA after repeated failures, password breach check.
- Audit log (who removed whom, role changes), admin tools (ban user).
- ClamAV scan of uploads; image re-encoding to strip EXIF/polyglots; per-user storage quota.
- TLS: terminate `wss://` in a reverse proxy (nginx/Caddy); set `CHAT_ALLOWED_ORIGINS` to the real domain.
- Optional end-to-end encryption (note: conflicts with server-side spam scanning; decide explicitly and document).

## Epic D: Scale & operations
- Replace DB polling with Redis pub/sub (gateway subscribes; `Realtime::push` publishes) and run several gateway workers.
- Move to MySQL/PostgreSQL; add indexes after measuring; S3-compatible storage for attachments.
- Swoole/RoadRunner or ReactPHP for the gateway if connection counts grow; or Laravel Reverb.
- Dockerfile + docker-compose (app, gateway, redis), GitHub Actions CI (`php artisan test` + Pint + e2e job).
- Metrics: `/health` already reports connections; add Prometheus endpoint and structured logs.

## Epic E: Quality
- Convert the Playwright e2e into CI; add gateway protocol tests (spawn gateway, raw TCP client) in PHPUnit.
- Load test (k6 / custom TCP script): target 500 concurrent sockets, measure delivery latency.
- Accessibility pass (focus order in modals, ARIA live region for new messages), i18n (Vietnamese UI strings).

## Next sprint (proposed)
1. A1 rule-based detector + tests + `chat:simulate` traffic generator (A9).  *Why first:* gives a working end-to-end
   "message -> verdict -> flagged bubble" demo and a baseline to beat with ML.
2. A5 report/block (also data collection).
3. Epic C: e-mail verification + password reset (needed before any public demo).

## Ideas parking lot
Scam-awareness tips banner for new users · "verified" badge for contacts saved >30 days · trust score per account ·
honeypot accounts for collecting scam samples · federation with real Zalo OA webhooks (only for research, check ToS).
