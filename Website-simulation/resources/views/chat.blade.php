<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Chatter</title>
    <link rel="stylesheet" href="{{ asset('css/app.css') }}">
</head>
<body class="chat-body">
    <div id="app" data-config="{{ json_encode($config) }}">
        <aside class="side">
            <header class="me-bar">
                <div class="avatar" id="me-avatar"></div>
                <div class="me-text">
                    <strong id="me-name"></strong>
                    <small id="me-username" class="muted"></small>
                </div>
                <span id="conn-dot" class="conn-dot off" title="Connecting…"></span>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button class="icon-btn" title="Sign out" aria-label="Sign out" type="submit">⎋</button>
                </form>
            </header>

            <nav class="tabs" role="tablist">
                <button class="tab active" data-tab="chats" role="tab">Chats <span class="badge" id="badge-chats" hidden></span></button>
                <button class="tab" data-tab="friends" role="tab">Friends <span class="badge" id="badge-friends" hidden></span></button>
            </nav>

            <section id="panel-chats" class="panel">
                <div class="panel-tools">
                    <input id="chat-filter" type="search" placeholder="Search conversations" autocomplete="off">
                    <button id="btn-new-group" class="btn small" title="Create a group">+ Group</button>
                </div>
                <ul id="conv-list" class="list"></ul>
            </section>

            <section id="panel-friends" class="panel" hidden>
                <div class="panel-tools">
                    <input id="user-search" type="search" placeholder="Find people by @username or email" autocomplete="off">
                </div>
                <div id="search-results"></div>
                <div id="friend-sections"></div>
            </section>
        </aside>

        <main class="main" id="main">
            <div class="empty-state" id="empty-state">
                <div class="brand-mark big">C</div>
                <h2>Pick a conversation</h2>
                <p class="muted">Choose a chat on the left, or add a friend to start talking.</p>
            </div>

            <section class="chat" id="chat" hidden>
                <header class="chat-head">
                    <button class="icon-btn back" id="btn-back" aria-label="Back">←</button>
                    <div class="avatar" id="chat-avatar"></div>
                    <div class="chat-title">
                        <strong id="chat-name"></strong>
                        <small id="chat-sub" class="muted"></small>
                    </div>
                    <button class="icon-btn" id="btn-info" title="Group info" aria-label="Group info" hidden>ⓘ</button>
                </header>

                <div class="messages" id="messages" tabindex="0">
                    <button id="btn-older" class="btn small ghost center-btn" hidden>Load earlier messages</button>
                    <div id="msg-list"></div>
                </div>

                <div class="typing" id="typing" hidden></div>
                <div class="attach-bar" id="attach-bar" hidden></div>

                <form class="composer" id="composer" autocomplete="off">
                    <label class="icon-btn" title="Attach files" aria-label="Attach files">
                        📎
                        <input type="file" id="file-input" multiple hidden>
                    </label>
                    <textarea id="input" rows="1" placeholder="Type a message" maxlength="4000"></textarea>
                    <button class="btn primary" id="btn-send" type="submit">Send</button>
                </form>
                <div class="drop-hint" id="drop-hint" hidden>Drop files to attach</div>
            </section>
        </main>
    </div>

    <div id="modal-root"></div>
    <div id="toasts" aria-live="polite"></div>

    <script src="{{ asset('js/chat.js') }}" defer></script>
</body>
</html>
