/* Browser end-to-end test. Needs: web server on :8000, `php artisan chat:serve` on :9501, a FRESH database
 * (php artisan migrate:fresh) and Playwright (`require("playwright")`). Run: node tests/e2e/chat.e2e.cjs */
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

const SP = fs.mkdtempSync(path.join(require('os').tmpdir(), 'chatter-e2e-'));
const BASE = process.env.BASE_URL || 'http://127.0.0.1:8000';
const results = [];
const ok = (name, cond, extra = '') => { results.push([cond ? 'PASS' : 'FAIL', name, extra]); console.log(`${cond ? 'PASS' : 'FAIL'}  ${name} ${extra}`); };

// tiny valid 1x1 PNG and a text file
fs.writeFileSync(path.join(SP, 'pixel.png'), Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', 'base64'));
fs.writeFileSync(path.join(SP, 'notes.txt'), 'hello attachment');
fs.writeFileSync(path.join(SP, 'evil.php'), '<?php echo 1;');
fs.writeFileSync(path.join(SP, 'xss.html'), '<script>alert(1)</script>');

async function register(browser, name, username) {
    const ctx = await browser.newContext({ viewport: { width: 1200, height: 800 } });
    const page = await ctx.newPage();
    const consoleErrors = [];
    page.on('console', (m) => { if (m.type() === 'error') consoleErrors.push(m.text()); });
    page.on('pageerror', (e) => consoleErrors.push('pageerror: ' + e.message));
    await page.goto(`${BASE}/register`);
    await page.fill('input[name=name]', name);
    await page.fill('input[name=username]', username);
    await page.fill('input[name=email]', `${username}@example.com`);
    await page.fill('input[name=password]', 'Password1');
    await page.fill('input[name=password_confirmation]', 'Password1');
    await page.click('button[type=submit]');
    await page.waitForURL('**/chat');
    await page.waitForSelector('#conn-dot:not(.off)', { timeout: 10000 });
    return { ctx, page, consoleErrors };
}

(async () => {
    const browser = await chromium.launch();

    // ---- Auth ----------------------------------------------------------
    const anon = await browser.newContext();
    const ap = await anon.newPage();
    await ap.goto(`${BASE}/chat`);
    ok('unauthenticated /chat redirects to login', ap.url().endsWith('/login'));
    await ap.fill('input[name=login]', 'nobody');
    await ap.fill('input[name=password]', 'whatever1');
    await ap.click('button[type=submit]');
    ok('bad credentials rejected', (await ap.textContent('body')).includes('do not match'));
    await ap.goto(`${BASE}/register`);
    await ap.fill('input[name=name]', 'Weak');
    await ap.fill('input[name=username]', 'weak');
    await ap.fill('input[name=email]', 'weak@example.com');
    await ap.fill('input[name=password]', 'short');
    await ap.fill('input[name=password_confirmation]', 'short');
    await ap.click('button[type=submit]');
    ok('weak password rejected', (await ap.textContent('body')).toLowerCase().includes('at least 8'));
    await anon.close();

    const A = await register(browser, 'Alice Nguyen', 'alice');
    const B = await register(browser, 'Bob Tran', 'bob');
    const C = await register(browser, 'Carol Le', 'carol');
    ok('3 users registered and sockets connected', true);

    // ---- Friends -------------------------------------------------------
    await A.page.click('.tab[data-tab=friends]');
    await A.page.fill('#user-search', 'bo');
    await A.page.waitForSelector('#search-results .row:has-text("Bob Tran")');
    await A.page.click('#search-results .row:has-text("Bob Tran") button:has-text("Add")');
    await B.page.waitForSelector('#badge-friends:not([hidden])', { timeout: 8000 });
    ok('Bob gets a live friend-request badge', true);
    await B.page.click('.tab[data-tab=friends]');
    await B.page.click('#friend-sections .row:has-text("Alice") button:has-text("Accept")');
    await B.page.waitForSelector('#friend-sections .section-title:has-text("Friends (1)")');
    ok('Bob accepted; friends list updated', true);
    await A.page.waitForSelector('#friend-sections .section-title:has-text("Friends (1)")', { timeout: 8000 });
    ok('Alice sees the acceptance live', true);

    // also make Carol a friend of Alice (for group), via API from Alice's page
    const csrf = await A.page.getAttribute('meta[name=csrf-token]', 'content');
    const apiCall = async (page, method, url, body) => {
        const token = await page.getAttribute('meta[name=csrf-token]', 'content');
        return page.evaluate(async ([m, u, b, t]) => {
            const r = await fetch(u, { method: m, headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': t }, body: b ? JSON.stringify(b) : undefined });
            let j = null; try { j = await r.json(); } catch {}
            return { status: r.status, json: j };
        }, [method, url, body, token]);
    };

    const carolId = (await apiCall(A.page, 'GET', '/api/users/search?q=carol')).json.users[0].id;
    const req = await apiCall(A.page, 'POST', '/api/friends/requests', { user_id: carolId });
    ok('friend request API 201', req.status === 201);
    const carolFriends = (await apiCall(C.page, 'GET', '/api/friends')).json;
    await apiCall(C.page, 'POST', `/api/friends/requests/${carolFriends.incoming[0].friendship_id}/accept`);

    // authorization: Alice cannot accept a request she sent herself / Bob can't hijack
    const forbidden = await apiCall(A.page, 'POST', `/api/friends/requests/${carolFriends.incoming[0].friendship_id}/accept`);
    ok('accepting an already-accepted/other request is refused', forbidden.status === 403, `status ${forbidden.status}`);

    // ---- 1:1 chat ------------------------------------------------------
    await A.page.reload();
    await A.page.waitForSelector('#conn-dot:not(.off)');
    await A.page.click('.tab[data-tab=friends]');
    await A.page.click('#friend-sections .row:has-text("Bob Tran")');
    await A.page.waitForSelector('#chat:not([hidden])');
    await A.page.fill('#input', 'Hello Bob! Xin chào 👋');
    await A.page.keyboard.press('Enter');
    await A.page.waitForSelector('#msg-list .msg.mine:has-text("Xin chào")');
    ok('Alice sent a message (TCP/WS ack)', true);

    await B.page.click('.tab[data-tab=chats]');
    await B.page.waitForSelector('#conv-list .row.unread', { timeout: 8000 });
    ok('Bob sees unread conversation live', (await B.page.textContent('#badge-chats')).trim() === '1');
    await B.page.click('#conv-list .row:has-text("Alice")');
    await B.page.waitForSelector('#msg-list .msg:has-text("Xin chào")');
    ok('Bob received the message', true);
    await B.page.fill('#input', 'Hi Alice, got it');
    await B.page.keyboard.press('Enter');
    await A.page.waitForSelector('#msg-list .msg:not(.mine):has-text("got it")', { timeout: 8000 });
    ok('Alice received Bob\'s reply in real time', true);
    await A.page.waitForSelector('#msg-list .meta:has-text("Seen")', { timeout: 8000 }).catch(() => {});
    ok('read receipt shown ("Seen")', (await A.page.textContent('#msg-list')).includes('Seen'));

    // typing indicator
    await B.page.waitForTimeout(2300); // client throttles typing events to one per 2s
    await B.page.type('#input', 'typing...', { delay: 30 });
    await A.page.waitForSelector('#typing:not([hidden])', { timeout: 5000 });
    ok('typing indicator appears', (await A.page.textContent('#typing')).includes('typing'));
    await B.page.fill('#input', '');

    // ---- Attachments ---------------------------------------------------
    await A.page.setInputFiles('#file-input', [path.join(SP, 'pixel.png'), path.join(SP, 'notes.txt')]);
    await A.page.waitForSelector('.attach-chip:has-text("notes.txt")');
    await A.page.fill('#input', 'files for you');
    await A.page.click('#btn-send');
    await A.page.waitForSelector('#msg-list .msg.mine img', { timeout: 10000 });
    ok('image attachment rendered inline for sender', true);
    await B.page.waitForSelector('#msg-list .msg:not(.mine) img', { timeout: 8000 });
    await B.page.waitForSelector('#msg-list .file-chip:has-text("notes.txt")');
    ok('Bob sees image + file chip', true);

    const attachInfo = await apiCall(B.page, 'GET', `/api/conversations/${(await apiCall(B.page, 'GET', '/api/conversations')).json.conversations[0].id}/messages`);
    const att = attachInfo.json.messages.find((m) => m.attachments.length).attachments;
    const imgUrl = att.find((a) => a.is_image).url;
    const txtUrl = att.find((a) => !a.is_image).url;

    const fetchStatus = (page, url) => page.evaluate(async (u) => {
        const r = await fetch(u); return { status: r.status, cd: r.headers.get('content-disposition'), ct: r.headers.get('content-type'), csp: r.headers.get('content-security-policy') };
    }, url);
    const imgB = await fetchStatus(B.page, imgUrl);
    ok('member can fetch image (inline, image/png)', imgB.status === 200 && imgB.ct.includes('image/png') && /inline/.test(imgB.cd || ''), JSON.stringify(imgB));
    const txtB = await fetchStatus(B.page, txtUrl);
    ok('non-image forced to download with sandbox CSP', txtB.status === 200 && /attachment/.test(txtB.cd || '') && (txtB.csp || '').includes('sandbox'), JSON.stringify(txtB));
    const imgC = await fetchStatus(C.page, imgUrl);
    ok('NON-member (Carol) gets 403 on private attachment', imgC.status === 403, `status ${imgC.status}`);

    // blocked extension & html disguised
    await A.page.setInputFiles('#file-input', [path.join(SP, 'evil.php')]);
    await A.page.click('#btn-send');
    await A.page.waitForSelector('.toast.error', { timeout: 8000 });
    ok('.php upload blocked', (await A.page.textContent('.toast.error')).includes('not allowed'), await A.page.textContent('.toast.error'));
    await A.page.click('.attach-chip button');

    await A.page.setInputFiles('#file-input', [path.join(SP, 'xss.html')]);
    await A.page.click('#btn-send');
    await A.page.waitForSelector('#msg-list .file-chip:has-text("xss.html")', { timeout: 8000 });
    const xssMsgs = (await apiCall(A.page, 'GET', `/api/conversations/${(await apiCall(A.page, 'GET', '/api/conversations')).json.conversations[0].id}/messages`)).json.messages;
    const xssUrl = xssMsgs.flatMap((m) => m.attachments).find((a) => a.name === 'xss.html').url;
    const xssRes = await fetchStatus(A.page, xssUrl);
    ok('uploaded .html is served as octet-stream attachment (cannot execute)', xssRes.ct === 'application/octet-stream' && /attachment/.test(xssRes.cd || ''), JSON.stringify(xssRes));

    // XSS in message body
    await A.page.fill('#input', '<img src=x onerror=alert(1)><b>bold</b>');
    await A.page.keyboard.press('Enter');
    await A.page.waitForSelector('#msg-list .msg.mine:has-text("<b>bold</b>")');
    ok('HTML in message text is escaped (shown literally)', (await A.page.locator('#msg-list .bubble b').count()) === 0);

    // ---- Recall --------------------------------------------------------
    A.page.once('dialog', (d) => d.accept());
    await A.page.click('#msg-list .msg.mine:has-text("<b>bold</b>") .meta .link');
    await B.page.waitForSelector('#msg-list .bubble.deleted', { timeout: 8000 });
    ok('recalled message shows as recalled for the other user', true);

    // ---- Group chat ----------------------------------------------------
    await A.page.click('#btn-new-group');
    await A.page.fill('.modal input[type=text]', 'Project Team');
    await A.page.check('.modal input[name=members] >> nth=0');
    await A.page.check('.modal input[name=members] >> nth=1');
    await A.page.click('.modal button:has-text("Create group")');
    await A.page.waitForSelector('#chat-name:has-text("Project Team")');
    ok('group created and opened', true);
    await A.page.fill('#input', 'welcome to the team');
    await A.page.keyboard.press('Enter');

    await B.page.waitForSelector('#conv-list .row:has-text("Project Team")', { timeout: 8000 });
    await B.page.click('#conv-list .row:has-text("Project Team")');
    await B.page.waitForSelector('#msg-list .msg:has-text("welcome to the team")');
    ok('Bob sees group + message', true);
    await C.page.click('#conv-list .row:has-text("Project Team")', { timeout: 8000 });
    await C.page.fill('#input', 'thanks Alice!');
    await C.page.keyboard.press('Enter');
    await B.page.waitForSelector('#msg-list .msg:has-text("thanks Alice!")', { timeout: 8000 });
    ok('group message fan-out between members', true);
    ok('group shows sender name', (await B.page.textContent('#msg-list')).includes('Carol Le'));

    // group authorization
    const groupId = (await apiCall(A.page, 'GET', '/api/conversations')).json.conversations.find((c) => c.title === 'Project Team').id;
    const bobRename = await apiCall(B.page, 'PATCH', `/api/groups/${groupId}`, { name: 'Hacked' });
    ok('plain member cannot rename group (403)', bobRename.status === 403, `status ${bobRename.status}`);
    const bobPromote = await apiCall(B.page, 'PATCH', `/api/groups/${groupId}/members/${carolId}`, { role: 'admin' });
    ok('plain member cannot change roles (403)', bobPromote.status === 403, `status ${bobPromote.status}`);
    const bobDelete = await apiCall(B.page, 'DELETE', `/api/groups/${groupId}`);
    ok('plain member cannot delete group (403)', bobDelete.status === 403, `status ${bobDelete.status}`);

    // outsider access: create a 4th user not in the group
    const D = await register(browser, 'Dave Pham', 'dave');
    const dView = await apiCall(D.page, 'GET', `/api/conversations/${groupId}/messages`);
    ok('non-member cannot read group history (403)', dView.status === 403, `status ${dView.status}`);
    const dSend = await apiCall(D.page, 'POST', `/api/conversations/${groupId}/messages`, { body: 'let me in' });
    ok('non-member cannot post to group (403)', dSend.status === 403, `status ${dSend.status}`);
    const dDirect = await apiCall(D.page, 'POST', '/api/conversations/direct', { user_id: carolId });
    ok('cannot open a direct chat with a non-friend (403)', dDirect.status === 403, `status ${dDirect.status}`);
    const dAdd = await apiCall(A.page, 'POST', `/api/groups/${groupId}/members`, { user_ids: [(await apiCall(A.page, 'GET', '/api/users/search?q=dave')).json.users[0].id] });
    ok('owner cannot add a non-friend to a group (422)', dAdd.status === 422, `status ${dAdd.status}`);

    // admin management UI: promote Bob, check info modal
    await A.page.click('#btn-info');
    await A.page.waitForSelector('.modal .pick:has-text("Bob Tran")');
    await A.page.click('.modal .pick:has-text("Bob Tran") button:has-text("Make admin")');
    await A.page.waitForSelector('.modal .pick:has-text("Bob Tran") .role-tag:has-text("admin")');
    ok('owner promoted Bob to admin via UI', true);
    await A.page.keyboard.press('Escape');
    const bobRename2 = await apiCall(B.page, 'PATCH', `/api/groups/${groupId}`, { name: 'Project Team 2' });
    ok('admin can now rename group (200)', bobRename2.status === 200, `status ${bobRename2.status}`);
    const bobRemoveOwner = await apiCall(B.page, 'DELETE', `/api/groups/${groupId}/members/${(await apiCall(A.page, 'GET', '/api/bootstrap')).json.me.id}`);
    ok('admin cannot remove the owner (403)', bobRemoveOwner.status === 403, `status ${bobRemoveOwner.status}`);

    // remove Carol -> she's kicked live
    const rem = await apiCall(B.page, 'DELETE', `/api/groups/${groupId}/members/${carolId}`);
    ok('admin removed a member (200)', rem.status === 200, `status ${rem.status}`);
    await C.page.waitForFunction(() => !document.querySelector('#conv-list')?.textContent.includes('Project Team'), null, { timeout: 8000 });
    ok('removed member loses the group live', true);
    const carolHist = await apiCall(C.page, 'GET', `/api/conversations/${groupId}/messages`);
    ok('removed member can no longer read history (403)', carolHist.status === 403);

    // ---- Reconnect resync ----------------------------------------------
    await A.page.screenshot({ path: path.join(SP, 'shot-alice.png') });
    await B.page.screenshot({ path: path.join(SP, 'shot-bob.png') });

    // mobile layout
    const mctx = await browser.newContext({ viewport: { width: 390, height: 780 } });
    const mp = await mctx.newPage();
    await mp.goto(`${BASE}/login`);
    await mp.fill('input[name=login]', 'alice');
    await mp.fill('input[name=password]', 'Password1');
    await mp.click('button[type=submit]');
    await mp.waitForSelector('#conv-list .row');
    await mp.click('#conv-list .row >> nth=0');
    await mp.waitForSelector('#chat:not([hidden])');
    await mp.screenshot({ path: path.join(SP, 'shot-mobile.png') });
    ok('mobile layout opens chat full-screen', await mp.isVisible('#btn-back'));

    // ---- Logout --------------------------------------------------------
    await B.page.click('button[aria-label="Sign out"]');
    await B.page.waitForURL('**/login');
    const afterLogout = await B.page.evaluate(async () => (await fetch('/api/bootstrap', { headers: { Accept: 'application/json' } })).status);
    ok('after logout API returns 401', afterLogout === 401, `status ${afterLogout}`);

    for (const [n, u] of [['alice', A], ['bob', B], ['carol', C]]) {
        const errs = u.consoleErrors.filter((e) => !/401|403|422/.test(e));
        ok(`no unexpected JS/console errors (${n})`, errs.length === 0, errs.join(' | ').slice(0, 200));
    }

    await browser.close();
    const failed = results.filter((r) => r[0] === 'FAIL');
    console.log(`\n${results.length - failed.length}/${results.length} passed`);
    process.exit(failed.length ? 1 : 0);
})().catch((e) => { console.error('TEST CRASH', e); process.exit(2); });
