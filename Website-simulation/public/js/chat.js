/* Chatter web client. No build step; the page CSP forbids inline script/style,
 * so all DOM is built with textContent / createElement (never innerHTML with user data). */
(() => {
    'use strict';

    const app = document.getElementById('app');
    const cfg = JSON.parse(app.dataset.config);
    const csrf = document.querySelector('meta[name="csrf-token"]').content;
    const $ = (sel, root = document) => root.querySelector(sel);

    /* ------------------------------------------------------------------ */
    /* State                                                                */
    /* ------------------------------------------------------------------ */
    const state = {
        me: cfg.me,
        convs: new Map(),          // id -> summary
        store: new Map(),          // id -> { list: Message[], hasMore, loaded }
        friends: [], incoming: [], outgoing: [],
        online: new Set(),
        activeId: null,
        tab: 'chats',
        filter: '',
        pendingFiles: [],
        typing: new Map(),         // convId -> Map(userId -> {name, timer})
        sends: new Map(),          // client_id -> body (restore on failure)
    };

    /* ------------------------------------------------------------------ */
    /* Helpers                                                              */
    /* ------------------------------------------------------------------ */
    function h(tag, attrs, ...kids) {
        const el = document.createElement(tag);
        for (const [k, v] of Object.entries(attrs || {})) {
            if (v == null || v === false) continue;
            if (k === 'class') el.className = v;
            else if (k.startsWith('on')) el.addEventListener(k.slice(2), v);
            else el.setAttribute(k, v === true ? '' : v);
        }
        for (const kid of kids.flat(Infinity)) {
            if (kid == null || kid === false) continue;
            el.append(kid instanceof Node ? kid : document.createTextNode(String(kid)));
        }
        return el;
    }

    function colorFor(id) {
        return `hsl(${(Number(id) * 137.5) % 360} 55% 48%)`;
    }
    function initials(name) {
        const parts = String(name || '?').trim().split(/\s+/);
        return ((parts[0]?.[0] || '?') + (parts.length > 1 ? parts[parts.length - 1][0] : '')).toUpperCase();
    }
    function avatar(user, { small = false, online = false, group = false, seed } = {}) {
        const el = h('div', { class: `avatar${small ? ' sm' : ''}${online ? ' online' : ''}` }, group ? '👥' : initials(user?.name));
        el.style.background = colorFor(seed ?? user?.id ?? 0);
        return el;
    }
    function fmtTime(iso) {
        const d = new Date(iso);
        const now = new Date();
        if (d.toDateString() === now.toDateString()) return d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
        const diff = (now - d) / 86400000;
        if (diff < 7) return d.toLocaleDateString([], { weekday: 'short' });
        return d.toLocaleDateString([], { day: '2-digit', month: '2-digit' });
    }
    function fmtDay(iso) {
        const d = new Date(iso);
        const now = new Date();
        if (d.toDateString() === now.toDateString()) return 'Today';
        const y = new Date(now - 86400000);
        if (d.toDateString() === y.toDateString()) return 'Yesterday';
        return d.toLocaleDateString([], { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });
    }
    function fmtBytes(n) {
        if (n < 1024) return `${n} B`;
        if (n < 1048576) return `${(n / 1024).toFixed(1)} KB`;
        return `${(n / 1048576).toFixed(1)} MB`;
    }
    function fileIcon(mime = '') {
        if (mime.startsWith('image/')) return '🖼️';
        if (mime.startsWith('audio/')) return '🎵';
        if (mime.startsWith('video/')) return '🎞️';
        if (mime === 'application/pdf') return '📕';
        if (/zip|compressed|tar|rar|7z/.test(mime)) return '🗜️';
        return '📄';
    }
    function debounce(fn, ms) {
        let t;
        return (...a) => { clearTimeout(t); t = setTimeout(() => fn(...a), ms); };
    }
    function toast(message, isError = false) {
        const el = h('div', { class: `toast${isError ? ' error' : ''}`, role: 'status' }, message);
        $('#toasts').append(el);
        setTimeout(() => el.remove(), isError ? 5000 : 3200);
    }

    async function api(method, url, body) {
        const opts = {
            method,
            credentials: 'same-origin',
            headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf, 'X-Requested-With': 'XMLHttpRequest' },
        };
        if (body instanceof FormData) {
            opts.body = body;
        } else if (body !== undefined) {
            opts.headers['Content-Type'] = 'application/json';
            opts.body = JSON.stringify(body);
        }

        let res;
        try {
            res = await fetch(url, opts);
        } catch {
            throw new Error('Network error. Check your connection.');
        }
        if (res.status === 401) { location.href = '/login'; throw new Error('Signed out'); }

        let data = null;
        try { data = await res.json(); } catch { /* non-JSON error page */ }

        if (!res.ok) {
            if (res.status === 419) throw new Error('Your session expired or the upload was too large. Reload the page.');
            if (res.status === 413) throw new Error('That upload is too large for the server.');
            if (res.status === 429) throw new Error('You are doing that too fast. Wait a moment.');
            const msg = data?.errors ? Object.values(data.errors).flat()[0] : data?.message;
            throw new Error(msg || `Request failed (${res.status})`);
        }
        return data;
    }

    /* ------------------------------------------------------------------ */
    /* Store updates                                                        */
    /* ------------------------------------------------------------------ */
    function sortedConvs() {
        const q = state.filter.trim().toLowerCase();
        return [...state.convs.values()]
            .filter((c) => !q || c.title.toLowerCase().includes(q))
            .sort((a, b) => new Date(b.last_message_at) - new Date(a.last_message_at) || b.id - a.id);
    }

    function totalUnread() {
        let n = 0;
        for (const c of state.convs.values()) n += c.unread || 0;
        return n;
    }

    function upsertMessage(m) {
        const s = state.store.get(m.conversation_id);
        if (!s) return;
        const i = s.list.findIndex((x) => x.id === m.id);
        if (i >= 0) { s.list[i] = m; return; }
        s.list.push(m);
        s.list.sort((a, b) => a.id - b.id);
    }

    function previewOf(conv) {
        const m = conv.last_message;
        if (!m) return conv.type === 'group' ? 'Group created' : 'Say hello 👋';
        if (m.deleted) return 'Message recalled';
        if (m.type === 'system') return m.body;
        const mine = m.user?.id === state.me.id;
        const who = mine ? 'You: ' : conv.type === 'group' && m.user ? `${m.user.name.split(' ')[0]}: ` : '';
        let text = m.body;
        if (!text && m.attachments?.length) {
            const a = m.attachments[0];
            text = a.is_image ? '🖼️ Photo' : `📎 ${a.name}`;
            if (m.attachments.length > 1) text += ` +${m.attachments.length - 1}`;
        }
        return who + (text || '');
    }

    /** Apply a message coming from REST, an ack, or a push. */
    function handleIncoming(m) {
        const conv = state.convs.get(m.conversation_id);
        if (!conv) {
            // First message of a chat/group we don't know yet (or just got added to).
            refreshConversations();
            return;
        }

        upsertMessage(m);
        const wasNewer = !conv.last_message || m.id >= conv.last_message.id;
        if (wasNewer) {
            conv.last_message = m;
            conv.last_message_at = m.created_at;
        }

        const mine = m.user?.id === state.me.id;
        const viewing = state.activeId === conv.id && document.visibilityState === 'visible';
        if (!mine && m.type !== 'system' && !viewing && wasNewer && !conv._seen?.has(m.id)) {
            conv.unread = (conv.unread || 0) + 1;
        }
        (conv._seen ||= new Set()).add(m.id);

        if (m.type === 'system' && conv.type === 'group') refreshConversationsSoon(); // name / member count may have changed

        if (state.activeId === conv.id) {
            renderMessages({ stick: true });
            if (!mine) clearTyping(conv.id, m.user?.id);
            markReadSoon();
        }
        renderConvList();
        updateTitle();
    }

    function applyDeleted(convId, messageId) {
        const s = state.store.get(convId);
        const m = s?.list.find((x) => x.id === messageId);
        if (m) { m.deleted = true; m.body = null; m.attachments = []; }
        const conv = state.convs.get(convId);
        if (conv?.last_message?.id === messageId) {
            conv.last_message = { ...conv.last_message, deleted: true, body: null, attachments: [] };
        }
        if (state.activeId === convId) renderMessages();
        renderConvList();
    }

    /* ------------------------------------------------------------------ */
    /* Data loading                                                         */
    /* ------------------------------------------------------------------ */
    function setConversations(list) {
        const next = new Map();
        for (const c of list) {
            const old = state.convs.get(c.id);
            if (old?._seen) c._seen = old._seen;
            next.set(c.id, c);
        }
        // Keep an opened-but-still-empty direct chat (the server hides those from the list).
        const active = state.convs.get(state.activeId);
        if (active && !next.has(active.id) && !active._removed) next.set(active.id, active);
        state.convs = next;
    }

    async function refreshConversations() {
        try {
            const data = await api('GET', '/api/conversations');
            setConversations(data.conversations);
            if (state.activeId && !state.convs.has(state.activeId)) closeChat();
            renderConvList();
            if (state.activeId) renderChatHeader();
            updateTitle();
        } catch (e) { /* silent; next push retries */ }
    }
    const refreshConversationsSoon = debounce(refreshConversations, 300);

    async function refreshFriends() {
        try {
            const data = await api('GET', '/api/friends');
            state.friends = data.friends;
            state.incoming = data.incoming;
            state.outgoing = data.outgoing;
            renderFriends();
            renderConvList();
            if (state.activeId) renderChatHeader();
        } catch (e) { /* ignore */ }
    }

    async function bootstrap() {
        const data = await api('GET', '/api/bootstrap');
        state.me = { ...state.me, ...data.me };
        setConversations(data.conversations);
        state.friends = data.friends.friends;
        state.incoming = data.friends.incoming;
        state.outgoing = data.friends.outgoing;
        renderMe();
        renderConvList();
        renderFriends();
        updateTitle();
    }

    async function loadMessages(convId, beforeId = null) {
        const q = new URLSearchParams();
        if (beforeId) q.set('before_id', beforeId);
        const data = await api('GET', `/api/conversations/${convId}/messages?${q}`);
        let s = state.store.get(convId);
        if (!s) { s = { list: [], hasMore: false, loaded: false }; state.store.set(convId, s); }

        const known = new Set(s.list.map((m) => m.id));
        s.list = [...data.messages.filter((m) => !known.has(m.id)), ...s.list].sort((a, b) => a.id - b.id);
        if (beforeId || !s.loaded) s.hasMore = data.has_more;
        s.loaded = true;
        return data;
    }

    /* ------------------------------------------------------------------ */
    /* Rendering: sidebar                                                   */
    /* ------------------------------------------------------------------ */
    function renderMe() {
        const av = $('#me-avatar');
        av.textContent = initials(state.me.name);
        av.style.background = colorFor(state.me.id);
        $('#me-name').textContent = state.me.name;
        $('#me-username').textContent = `@${state.me.username}`;
    }

    function updateTitle() {
        const n = totalUnread();
        document.title = n ? `(${n}) Chatter` : 'Chatter';
        const badge = $('#badge-chats');
        badge.hidden = n === 0;
        badge.textContent = n > 99 ? '99+' : n;

        const fb = $('#badge-friends');
        fb.hidden = state.incoming.length === 0;
        fb.textContent = state.incoming.length;
    }

    function renderConvList() {
        const list = $('#conv-list');
        list.replaceChildren();
        const items = sortedConvs();

        if (!items.length) {
            list.append(h('li', { class: 'empty-note' },
                state.filter ? 'No conversations match.' : 'No chats yet. Add friends in the Friends tab, or create a group.'));
            return;
        }

        for (const c of items) {
            const other = c.other_user;
            const isOnline = other && state.online.has(other.id);
            const row = h('button', {
                class: `row${c.id === state.activeId ? ' active' : ''}${c.unread ? ' unread' : ''}`,
                type: 'button',
                onclick: () => openConversation(c.id),
            },
            avatar(other, { online: isOnline, group: c.type === 'group', seed: c.type === 'group' ? c.id + 1000 : other?.id }),
            h('div', { class: 'row-text' },
                h('div', { class: 'row-top' }, h('strong', {}, c.title), h('small', {}, fmtTime(c.last_message_at))),
                h('div', { class: 'row-sub' }, h('span', {}, previewOf(c)),
                    c.unread ? h('span', { class: 'badge' }, c.unread > 99 ? '99+' : c.unread) : null)));
            list.append(h('li', {}, row));
        }
    }

    function personRow(user, actions, onclick) {
        const el = h(onclick ? 'button' : 'div', { class: 'row', type: onclick ? 'button' : null, onclick },
            avatar(user, { online: state.online.has(user.id) }),
            h('div', { class: 'row-text' }, h('strong', {}, user.name), h('small', { class: 'muted' }, `@${user.username}`)),
            actions ? h('div', { class: 'row-actions' }, actions) : null);
        return el;
    }

    function stop(fn) { return (e) => { e.stopPropagation(); fn(e); }; }

    async function act(fn, okMsg) {
        try { await fn(); if (okMsg) toast(okMsg); } catch (e) { toast(e.message, true); }
    }

    function renderFriends() {
        updateTitle();
        const box = $('#friend-sections');
        box.replaceChildren();

        if (state.incoming.length) {
            box.append(h('div', { class: 'section-title' }, `Friend requests (${state.incoming.length})`));
            for (const u of state.incoming) {
                box.append(personRow(u, [
                    h('button', { class: 'btn small primary', onclick: () => act(async () => {
                        await api('POST', `/api/friends/requests/${u.friendship_id}/accept`);
                        await refreshFriends();
                    }, `You and ${u.name} are now friends`) }, 'Accept'),
                    h('button', { class: 'btn small', onclick: () => act(async () => {
                        await api('DELETE', `/api/friends/requests/${u.friendship_id}`);
                        await refreshFriends();
                    }) }, 'Decline'),
                ]));
            }
        }

        if (state.outgoing.length) {
            box.append(h('div', { class: 'section-title' }, 'Sent requests'));
            for (const u of state.outgoing) {
                box.append(personRow(u, h('button', { class: 'btn small', onclick: () => act(async () => {
                    await api('DELETE', `/api/friends/requests/${u.friendship_id}`);
                    await refreshFriends();
                }) }, 'Cancel')));
            }
        }

        box.append(h('div', { class: 'section-title' }, `Friends (${state.friends.length})`));
        if (!state.friends.length) {
            box.append(h('div', { class: 'empty-note' }, 'No friends yet. Search above to find people.'));
        }
        for (const u of state.friends) {
            box.append(personRow(u,
                h('button', { class: 'btn small danger ghost', title: 'Remove friend', onclick: stop(() => {
                    if (!confirm(`Remove ${u.name} from your friends?`)) return;
                    act(async () => { await api('DELETE', `/api/friends/${u.id}`); await refreshFriends(); });
                }) }, 'Remove'),
                () => startDirect(u.id)));
        }
    }

    const runSearch = debounce(async () => {
        const q = $('#user-search').value.trim();
        const box = $('#search-results');
        if (q.length < 2) { box.replaceChildren(); return; }
        try {
            const data = await api('GET', `/api/users/search?q=${encodeURIComponent(q)}`);
            box.replaceChildren(h('div', { class: 'section-title' }, 'Search results'));
            if (!data.users.length) box.append(h('div', { class: 'empty-note' }, 'Nobody found.'));
            for (const u of data.users) {
                let action;
                if (u.relation === 'none') {
                    action = h('button', { class: 'btn small primary', onclick: (e) => act(async () => {
                        e.target.disabled = true;
                        await api('POST', '/api/friends/requests', { user_id: u.id });
                        await refreshFriends(); runSearch();
                    }, 'Friend request sent') }, 'Add');
                } else if (u.relation === 'pending_in') {
                    action = h('button', { class: 'btn small primary', onclick: () => act(async () => {
                        await api('POST', `/api/friends/requests/${u.friendship_id}/accept`);
                        await refreshFriends(); runSearch();
                    }) }, 'Accept');
                } else if (u.relation === 'pending_out') {
                    action = h('small', { class: 'muted' }, 'Requested');
                } else {
                    action = h('button', { class: 'btn small', onclick: () => startDirect(u.id) }, 'Message');
                }
                box.append(personRow(u, action));
            }
        } catch (e) { box.replaceChildren(h('div', { class: 'empty-note' }, e.message)); }
    }, 300);

    /* ------------------------------------------------------------------ */
    /* Rendering: chat pane                                                 */
    /* ------------------------------------------------------------------ */
    function renderChatHeader() {
        const c = state.convs.get(state.activeId);
        if (!c) return;
        const host = $('#chat-avatar');
        host.replaceWith(Object.assign(avatar(c.other_user, {
            online: c.other_user && state.online.has(c.other_user.id),
            group: c.type === 'group',
            seed: c.type === 'group' ? c.id + 1000 : c.other_user?.id,
        }), { id: 'chat-avatar' }));
        $('#chat-name').textContent = c.title;
        $('#chat-sub').textContent = c.type === 'group'
            ? `${c.members_count} members`
            : (state.online.has(c.other_user?.id) ? 'Online' : 'Offline');
        $('#btn-info').hidden = c.type !== 'group';
    }

    function messageNode(m, prev, conv) {
        const mine = m.user?.id === state.me.id;
        const cont = prev && prev.type !== 'system' && prev.user?.id === m.user?.id
            && new Date(m.created_at) - new Date(prev.created_at) < 5 * 60000
            && new Date(m.created_at).toDateString() === new Date(prev.created_at).toDateString();

        const content = [];
        if (m.deleted) {
            content.push(h('div', { class: 'bubble deleted' }, mine ? 'You recalled a message' : 'Message recalled'));
        } else {
            const flagged = m.spam_label === 'spam' || m.spam_label === 'scam';
            const bubble = h('div', { class: `bubble${flagged ? ' flagged' : ''}` });
            if (m.attachments?.length) {
                const wrap = h('div', { class: 'attachments' });
                for (const a of m.attachments) {
                    if (a.is_image) {
                        wrap.append(h('img', { src: a.url, alt: a.name, loading: 'lazy', onclick: () => openLightbox(a.url) }));
                    } else {
                        wrap.append(h('a', { class: 'file-chip', href: `${a.url}?download=1`, download: a.name },
                            h('span', { class: 'ico' }, fileIcon(a.mime)),
                            h('span', {}, h('span', { class: 'fname' }, a.name), h('small', { class: 'muted' }, fmtBytes(a.size)))));
                    }
                }
                bubble.append(wrap);
            }
            if (m.body) bubble.append(document.createTextNode(m.body));
            content.push(bubble);
            if (flagged) content.push(h('div', { class: 'meta' }, `⚠️ Flagged as possible ${m.spam_label}`));
        }

        const meta = h('div', { class: 'meta' }, fmtTime(m.created_at));
        if (mine && !m.deleted) {
            meta.append(h('button', { class: 'link', type: 'button', onclick: () => recall(m) }, 'Recall'));
        }
        content.push(meta);

        const wrap = h('div', { class: 'bubble-wrap' },
            conv.type === 'group' && !mine && !cont ? h('div', { class: 'sender' }, m.user?.name || 'Unknown') : null,
            content);

        return h('div', { class: `msg${mine ? ' mine' : ''}${cont ? ' cont' : ''}`, 'data-id': m.id },
            mine ? null : (cont ? h('div', { class: 'avatar-gap' }) : avatar(m.user || { id: 0, name: '?' }, { small: true })),
            wrap);
    }

    function renderMessages({ stick = false, keepFrom = null } = {}) {
        const conv = state.convs.get(state.activeId);
        const s = state.store.get(state.activeId);
        if (!conv || !s) return;

        const box = $('#messages');
        const nearBottom = box.scrollHeight - box.scrollTop - box.clientHeight < 120;
        const prevHeight = box.scrollHeight;

        const list = $('#msg-list');
        list.replaceChildren();
        let prev = null;
        for (const m of s.list) {
            if (!prev || new Date(m.created_at).toDateString() !== new Date(prev.created_at).toDateString()) {
                list.append(h('div', { class: 'day' }, fmtDay(m.created_at)));
                prev = null;
            }
            if (m.type === 'system') {
                list.append(h('div', { class: 'system' }, m.body));
            } else {
                list.append(messageNode(m, prev, conv));
            }
            prev = m;
        }

        // "Seen" marker under my last message in a 1:1 chat.
        if (conv.type === 'direct' && conv.peer_read_id) {
            const lastMine = [...s.list].reverse().find((m) => m.user?.id === state.me.id && !m.deleted);
            if (lastMine && conv.peer_read_id >= lastMine.id) {
                const node = list.querySelector(`.msg[data-id="${lastMine.id}"] .meta`);
                node?.append(h('span', {}, '✓ Seen'));
            }
        }

        $('#btn-older').hidden = !s.hasMore;

        if (keepFrom !== null) box.scrollTop = box.scrollHeight - prevHeight + keepFrom;
        else if (stick || nearBottom) box.scrollTop = box.scrollHeight;
    }

    function renderTyping() {
        const el = $('#typing');
        const t = state.typing.get(state.activeId);
        const names = t ? [...t.values()].map((x) => x.name.split(' ')[0]) : [];
        el.hidden = names.length === 0;
        el.textContent = names.length === 1 ? `${names[0]} is typing…`
            : names.length > 1 ? `${names.join(', ')} are typing…` : '';
    }
    function clearTyping(convId, userId) {
        const t = state.typing.get(convId);
        if (t?.has(userId)) { clearTimeout(t.get(userId).timer); t.delete(userId); }
        if (convId === state.activeId) renderTyping();
    }

    function renderAttachBar() {
        const bar = $('#attach-bar');
        bar.replaceChildren();
        bar.hidden = state.pendingFiles.length === 0;
        state.pendingFiles.forEach((f, i) => {
            bar.append(h('div', { class: 'attach-chip' },
                h('span', {}, `${fileIcon(f.type)} ${f.name} (${fmtBytes(f.size)})`),
                h('button', { type: 'button', 'aria-label': `Remove ${f.name}`, onclick: () => {
                    state.pendingFiles.splice(i, 1); renderAttachBar();
                } }, '×')));
        });
    }

    /* ------------------------------------------------------------------ */
    /* Conversation actions                                                 */
    /* ------------------------------------------------------------------ */
    async function openConversation(id) {
        const conv = state.convs.get(id);
        if (!conv) return;

        state.activeId = id;
        state.pendingFiles = [];
        renderAttachBar();
        app.classList.add('has-chat');
        $('#empty-state').hidden = true;
        $('#chat').hidden = false;
        renderChatHeader();
        renderConvList();
        renderTyping();

        const s = state.store.get(id);
        if (!s?.loaded) {
            $('#msg-list').replaceChildren();
            try { await loadMessages(id); } catch (e) { toast(e.message, true); }
            if (state.activeId !== id) return; // user switched meanwhile
        }
        renderMessages({ stick: true });
        markRead();
        $('#input').focus({ preventScroll: true });
    }

    function closeChat() {
        state.activeId = null;
        app.classList.remove('has-chat');
        $('#chat').hidden = true;
        $('#empty-state').hidden = false;
        renderConvList();
    }

    async function startDirect(userId) {
        try {
            const data = await api('POST', '/api/conversations/direct', { user_id: userId });
            const c = data.conversation;
            const old = state.convs.get(c.id);
            if (old?._seen) c._seen = old._seen;
            state.convs.set(c.id, c);
            setTab('chats');
            await openConversation(c.id);
        } catch (e) { toast(e.message, true); }
    }

    async function recall(m) {
        if (!confirm('Recall this message for everyone?')) return;
        try {
            await api('DELETE', `/api/messages/${m.id}`);
            applyDeleted(m.conversation_id, m.id);
        } catch (e) { toast(e.message, true); }
    }

    function markRead() {
        const conv = state.convs.get(state.activeId);
        const s = state.store.get(state.activeId);
        if (!conv || !s?.list.length || document.visibilityState !== 'visible') return;
        const lastId = s.list[s.list.length - 1].id;
        if (!conv.unread && conv._readUpTo === lastId) return;
        conv._readUpTo = lastId;
        conv.unread = 0;
        renderConvList();
        updateTitle();
        if (socket.ready) socket.send({ type: 'read', conversation_id: conv.id, message_id: lastId });
        else api('POST', `/api/conversations/${conv.id}/read`, { message_id: lastId }).catch(() => {});
    }
    const markReadSoon = debounce(markRead, 250);

    /* ------------------------------------------------------------------ */
    /* Sending                                                              */
    /* ------------------------------------------------------------------ */
    function addFiles(fileList) {
        const max = cfg.maxFiles;
        for (const f of fileList) {
            if (state.pendingFiles.length >= max) { toast(`You can attach up to ${max} files per message.`, true); break; }
            if (f.size > cfg.maxUploadKb * 1024) { toast(`${f.name} is larger than ${Math.round(cfg.maxUploadKb / 1024)} MB.`, true); continue; }
            state.pendingFiles.push(f);
        }
        renderAttachBar();
    }

    async function submitComposer() {
        const conv = state.convs.get(state.activeId);
        const input = $('#input');
        const body = input.value.trim();
        const files = state.pendingFiles;
        if (!conv || (!body && !files.length)) return;

        if (files.length) {
            const fd = new FormData();
            if (body) fd.append('body', body);
            files.forEach((f) => fd.append('files[]', f));
            const btn = $('#btn-send');
            btn.disabled = true; btn.textContent = 'Uploading…';
            try {
                const data = await api('POST', `/api/conversations/${conv.id}/messages`, fd);
                input.value = ''; autosize();
                state.pendingFiles = []; renderAttachBar();
                handleIncoming(data.message);
            } catch (e) { toast(e.message, true); }
            finally { btn.disabled = false; btn.textContent = 'Send'; }
            return;
        }

        input.value = ''; autosize();
        if (socket.ready) {
            const clientId = `${Date.now().toString(36)}${Math.random().toString(36).slice(2, 6)}`;
            state.sends.set(clientId, body);
            socket.send({ type: 'send', conversation_id: conv.id, body, client_id: clientId });
        } else {
            try {
                const data = await api('POST', `/api/conversations/${conv.id}/messages`, { body });
                handleIncoming(data.message);
            } catch (e) { input.value = body; toast(e.message, true); }
        }
    }

    function autosize() {
        const el = $('#input');
        el.style.height = 'auto';
        el.style.height = `${Math.min(el.scrollHeight, 140)}px`;
    }

    /* ------------------------------------------------------------------ */
    /* Real-time socket                                                     */
    /* ------------------------------------------------------------------ */
    const socket = {
        ws: null, ready: false, retry: 0, timer: null, first: true, lastTyping: 0,

        setStatus(on) {
            const dot = $('#conn-dot');
            dot.classList.toggle('off', !on);
            dot.title = on ? 'Connected' : 'Reconnecting…';
        },

        send(obj) {
            if (this.ws && this.ws.readyState === WebSocket.OPEN) this.ws.send(JSON.stringify(obj));
        },

        async connect() {
            clearTimeout(this.timer);
            this.setStatus(false);
            let tok;
            try { tok = await api('POST', '/api/realtime/token'); } catch { return this.schedule(); }

            const proto = location.protocol === 'https:' ? 'wss' : 'ws';
            let ws;
            try { ws = new WebSocket(`${proto}://${location.hostname}:${tok.port}`); } catch { return this.schedule(); }
            this.ws = ws;

            ws.onopen = () => ws.send(JSON.stringify({ type: 'auth', token: tok.token }));
            ws.onmessage = (e) => { try { this.onMessage(JSON.parse(e.data)); } catch { /* ignore junk */ } };
            ws.onclose = () => {
                if (this.ws !== ws) return;
                this.ready = false;
                this.setStatus(false);
                this.schedule();
            };
            ws.onerror = () => { /* onclose follows */ };
        },

        schedule() {
            clearTimeout(this.timer);
            const delay = Math.min(15000, 1000 * 2 ** Math.min(this.retry++, 4)) + Math.random() * 500;
            this.timer = setTimeout(() => this.connect(), delay);
        },

        async onMessage(d) {
            switch (d.type) {
                case 'auth_ok': {
                    this.ready = true;
                    this.retry = 0;
                    this.setStatus(true);
                    state.online = new Set(d.online_friend_ids);
                    if (!this.first) await resync(); // catch up on anything missed while offline
                    this.first = false;
                    renderConvList(); renderFriends();
                    if (state.activeId) renderChatHeader();
                    break;
                }
                case 'ack':
                    state.sends.delete(d.client_id);
                    handleIncoming(d.message);
                    break;
                case 'message':
                    handleIncoming(d.message);
                    break;
                case 'presence':
                    d.online ? state.online.add(d.user_id) : state.online.delete(d.user_id);
                    renderConvList(); renderFriends();
                    if (state.activeId) renderChatHeader();
                    break;
                case 'typing': {
                    let t = state.typing.get(d.conversation_id);
                    if (!t) { t = new Map(); state.typing.set(d.conversation_id, t); }
                    const prev = t.get(d.user.id);
                    if (prev) clearTimeout(prev.timer);
                    t.set(d.user.id, { name: d.user.name, timer: setTimeout(() => clearTyping(d.conversation_id, d.user.id), 4000) });
                    if (d.conversation_id === state.activeId) renderTyping();
                    break;
                }
                case 'event':
                    onEvent(d.event, d.data || {});
                    break;
                case 'error':
                    if (d.client_id && state.sends.has(d.client_id)) {
                        const input = $('#input');
                        if (!input.value) { input.value = state.sends.get(d.client_id); autosize(); }
                        state.sends.delete(d.client_id);
                    }
                    if (d.code !== 'unauthenticated') toast(d.message, true);
                    if (d.code === 'invalid_token') { try { this.ws.close(); } catch { /* ignore */ } }
                    break;
                default:
                    break;
            }
        },
    };

    async function resync() {
        try {
            await Promise.all([refreshConversations(), refreshFriends()]);
            if (state.activeId) {
                const s = state.store.get(state.activeId);
                if (s) { s.loaded = false; s.list = []; }
                await loadMessages(state.activeId);
                renderMessages({ stick: true });
                markRead();
            }
        } catch { /* next reconnect will retry */ }
    }

    function onEvent(name, data) {
        switch (name) {
            case 'friend_request':
                toast(`${data.from?.name || 'Someone'} sent you a friend request`);
                refreshFriends();
                break;
            case 'friend_accepted':
                toast(`${data.by?.name || 'Someone'} accepted your friend request`);
                refreshFriends();
                break;
            case 'friend_request_cancelled':
            case 'friend_removed':
                refreshFriends();
                break;
            case 'message_deleted':
                applyDeleted(data.conversation_id, data.message_id);
                break;
            case 'read': {
                const conv = state.convs.get(data.conversation_id);
                if (conv && conv.type === 'direct' && data.user_id !== state.me.id) {
                    conv.peer_read_id = Math.max(conv.peer_read_id || 0, data.message_id);
                    if (state.activeId === conv.id) renderMessages();
                }
                break;
            }
            case 'removed_from_conversation': {
                const conv = state.convs.get(data.conversation_id);
                if (conv) {
                    conv._removed = true;
                    state.convs.delete(conv.id);
                    state.store.delete(conv.id);
                    if (state.activeId === conv.id) { closeChat(); toast(`You are no longer in "${conv.title}"`); }
                    closeModal();
                    renderConvList(); updateTitle();
                }
                break;
            }
            default: break;
        }
    }

    /* ------------------------------------------------------------------ */
    /* Modals                                                               */
    /* ------------------------------------------------------------------ */
    function closeModal() { $('#modal-root').replaceChildren(); }

    function modal(title, body, footer) {
        const root = $('#modal-root');
        const back = h('div', { class: 'modal-back', onmousedown: (e) => { if (e.target === back) closeModal(); } },
            h('div', { class: 'modal', role: 'dialog', 'aria-modal': 'true', 'aria-label': title },
                h('div', { class: 'modal-head' }, h('h3', {}, title),
                    h('button', { class: 'icon-btn', 'aria-label': 'Close', onclick: closeModal }, '✕')),
                h('div', { class: 'modal-body' }, body),
                footer ? h('div', { class: 'modal-foot' }, footer) : null));
        root.replaceChildren(back);
        return back;
    }

    function friendPicker(candidates, name) {
        const list = h('div', { class: 'pick-list' });
        if (!candidates.length) list.append(h('div', { class: 'empty-note' }, 'No friends available to add.'));
        for (const u of candidates) {
            list.append(h('label', { class: 'pick' },
                h('input', { type: 'checkbox', name, value: u.id }),
                avatar(u, { small: true }),
                h('span', {}, u.name, ' ', h('small', { class: 'muted' }, `@${u.username}`))));
        }
        return list;
    }
    const picked = (root, name) => [...root.querySelectorAll(`input[name="${name}"]:checked`)].map((i) => Number(i.value));

    function openNewGroup() {
        const nameInput = h('input', { type: 'text', maxlength: '80', placeholder: 'Group name', 'aria-label': 'Group name' });
        const picker = friendPicker(state.friends, 'members');
        const create = h('button', { class: 'btn primary', onclick: async () => {
            const name = nameInput.value.trim();
            const ids = picked(picker, 'members');
            if (!name) return toast('Give the group a name.', true);
            if (!ids.length) return toast('Pick at least one friend.', true);
            create.disabled = true;
            try {
                const data = await api('POST', '/api/groups', { name, member_ids: ids });
                closeModal();
                state.convs.set(data.conversation.id, data.conversation);
                await refreshConversations();
                openConversation(data.conversation.id);
            } catch (e) { toast(e.message, true); create.disabled = false; }
        } }, 'Create group');

        modal('New group', [h('div', {}, h('div', { class: 'field-label' }, 'Name'), nameInput),
            h('div', {}, h('div', { class: 'field-label' }, 'Add friends'), picker)],
        [h('button', { class: 'btn', onclick: closeModal }, 'Cancel'), create]);
        nameInput.focus();
    }

    async function openGroupInfo() {
        const id = state.activeId;
        try {
            renderGroupInfo(await api('GET', `/api/conversations/${id}`));
        } catch (e) { toast(e.message, true); }
    }

    function renderGroupInfo(d) {
        const myRole = d.my_role;
        const canManage = myRole === 'owner' || myRole === 'admin';
        const isOwner = myRole === 'owner';
        const update = async (fn) => {
            try {
                const data = await fn();
                if (data?.id) { renderGroupInfo(data); const c = state.convs.get(data.id); if (c) { c.title = data.title; c.members_count = data.members_count; } renderConvList(); renderChatHeader(); }
                else closeModal();
            } catch (e) { toast(e.message, true); }
        };

        const body = [];
        if (canManage) {
            const nameInput = h('input', { type: 'text', value: d.title, maxlength: '80', 'aria-label': 'Group name' });
            body.push(h('div', {}, h('div', { class: 'field-label' }, 'Group name'),
                h('div', { class: 'panel-tools' }, nameInput,
                    h('button', { class: 'btn small', onclick: () => {
                        const v = nameInput.value.trim();
                        if (v && v !== d.title) update(() => api('PATCH', `/api/groups/${d.id}`, { name: v }));
                    } }, 'Rename'))));
        }

        const members = h('div', {});
        members.append(h('div', { class: 'field-label' }, `Members (${d.members.length})`));
        for (const m of d.members) {
            const mine = m.id === state.me.id;
            const actions = [];
            const canRemove = !mine && m.role !== 'owner' && (isOwner || (myRole === 'admin' && m.role === 'member'));
            if (isOwner && !mine) {
                actions.push(h('button', { class: 'btn small', onclick: () => update(() => api('PATCH', `/api/groups/${d.id}/members/${m.id}`, { role: m.role === 'admin' ? 'member' : 'admin' })) },
                    m.role === 'admin' ? 'Demote' : 'Make admin'));
                actions.push(h('button', { class: 'btn small', onclick: () => {
                    if (confirm(`Make ${m.name} the group owner? You will become an admin.`)) update(() => api('POST', `/api/groups/${d.id}/transfer`, { user_id: m.id }));
                } }, 'Make owner'));
            }
            if (canRemove) {
                actions.push(h('button', { class: 'btn small danger', onclick: () => {
                    if (confirm(`Remove ${m.name} from the group?`)) update(() => api('DELETE', `/api/groups/${d.id}/members/${m.id}`));
                } }, 'Remove'));
            }
            members.append(h('div', { class: 'pick' }, avatar(m, { small: true, online: state.online.has(m.id) }),
                h('span', { class: 'row-text' }, h('span', {}, m.name, mine ? ' (you)' : '', m.role !== 'member' ? h('span', { class: 'role-tag' }, m.role) : null),
                    h('small', { class: 'muted' }, `@${m.username}`)),
                h('span', { class: 'row-actions' }, actions)));
        }
        body.push(members);

        const footer = [];
        if (canManage) {
            const inGroup = new Set(d.members.map((m) => m.id));
            const candidates = state.friends.filter((f) => !inGroup.has(f.id));
            if (candidates.length) {
                const picker = friendPicker(candidates, 'add');
                body.push(h('div', {}, h('div', { class: 'field-label' }, 'Add friends'), picker,
                    h('div', { class: 'modal-foot' }, h('button', { class: 'btn small primary', onclick: () => {
                        const ids = picked(picker, 'add');
                        if (!ids.length) return toast('Pick at least one friend.', true);
                        update(() => api('POST', `/api/groups/${d.id}/members`, { user_ids: ids }));
                    } }, 'Add selected'))));
            }
        }
        if (isOwner) {
            footer.push(h('button', { class: 'btn danger', onclick: () => {
                if (confirm('Delete this group and all its messages for everyone? This cannot be undone.')) update(() => api('DELETE', `/api/groups/${d.id}`));
            } }, 'Delete group'));
        }
        if (!isOwner || d.members.length === 1) {
            if (!isOwner) footer.push(h('button', { class: 'btn danger', onclick: async () => {
                if (!confirm('Leave this group?')) return;
                try {
                    await api('POST', `/api/groups/${d.id}/leave`);
                    state.convs.delete(d.id); state.store.delete(d.id);
                    closeModal(); closeChat(); renderConvList(); updateTitle();
                } catch (e) { toast(e.message, true); }
            } }, 'Leave group'));
        } else {
            body.push(h('small', { class: 'muted' }, 'As owner, make someone else the owner before you can leave, or delete the group.'));
        }
        footer.push(h('button', { class: 'btn', onclick: closeModal }, 'Close'));
        modal(d.title, body, footer);
    }

    function openLightbox(url) {
        const box = h('div', { class: 'lightbox', onclick: () => box.remove() }, h('img', { src: url, alt: '' }));
        document.body.append(box);
    }

    /* ------------------------------------------------------------------ */
    /* Wiring                                                               */
    /* ------------------------------------------------------------------ */
    function setTab(tab) {
        state.tab = tab;
        document.querySelectorAll('.tab').forEach((t) => t.classList.toggle('active', t.dataset.tab === tab));
        $('#panel-chats').hidden = tab !== 'chats';
        $('#panel-friends').hidden = tab !== 'friends';
    }

    function wire() {
        document.querySelectorAll('.tab').forEach((t) => t.addEventListener('click', () => setTab(t.dataset.tab)));
        $('#chat-filter').addEventListener('input', (e) => { state.filter = e.target.value; renderConvList(); });
        $('#user-search').addEventListener('input', runSearch);
        $('#btn-new-group').addEventListener('click', openNewGroup);
        $('#btn-info').addEventListener('click', openGroupInfo);
        $('#btn-back').addEventListener('click', closeChat);
        $('#btn-older').addEventListener('click', async () => {
            const s = state.store.get(state.activeId);
            if (!s?.list.length) return;
            const box = $('#messages');
            const before = box.scrollHeight - box.scrollTop;
            try {
                await loadMessages(state.activeId, s.list[0].id);
                renderMessages({ keepFrom: 0 });
                box.scrollTop = box.scrollHeight - before;
            } catch (e) { toast(e.message, true); }
        });

        const input = $('#input');
        $('#composer').addEventListener('submit', (e) => { e.preventDefault(); submitComposer(); });
        input.addEventListener('input', () => {
            autosize();
            const now = Date.now();
            if (state.activeId && input.value && now - socket.lastTyping > 2000) {
                socket.lastTyping = now;
                socket.send({ type: 'typing', conversation_id: state.activeId });
            }
        });
        input.addEventListener('keydown', (e) => {
            // Don't send while an IME (e.g. Vietnamese Telex/VNI) is composing a character.
            if (e.key === 'Enter' && !e.shiftKey && !e.isComposing && e.keyCode !== 229) {
                e.preventDefault();
                submitComposer();
            }
        });
        input.addEventListener('paste', (e) => {
            const files = [...(e.clipboardData?.files || [])];
            if (files.length) { e.preventDefault(); addFiles(files); }
        });

        $('#file-input').addEventListener('change', (e) => { addFiles(e.target.files); e.target.value = ''; });

        const chat = $('#chat');
        const hint = $('#drop-hint');
        let depth = 0;
        chat.addEventListener('dragenter', (e) => { if (e.dataTransfer?.types?.includes('Files')) { depth++; hint.hidden = false; } });
        chat.addEventListener('dragleave', () => { if (--depth <= 0) { depth = 0; hint.hidden = true; } });
        chat.addEventListener('dragover', (e) => e.preventDefault());
        chat.addEventListener('drop', (e) => {
            e.preventDefault(); depth = 0; hint.hidden = true;
            if (e.dataTransfer?.files?.length) addFiles(e.dataTransfer.files);
        });

        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') { closeModal(); document.querySelector('.lightbox')?.remove(); }
        });
        document.addEventListener('visibilitychange', () => { if (document.visibilityState === 'visible') markReadSoon(); });
        window.addEventListener('focus', markReadSoon);
    }

    /* ------------------------------------------------------------------ */
    /* Boot                                                                 */
    /* ------------------------------------------------------------------ */
    wire();
    renderMe();
    bootstrap()
        .catch((e) => toast(e.message, true))
        .finally(() => socket.connect());
})();
