<?php
declare(strict_types=1);

namespace SeekmodoAiChatbot\Observers;

use base;

/**
 * Injects the storefront chatbot widget.
 *
 * Genesis / Numinix templates do not fire NOTIFY_FOOTER_SCRIPTS.
 * They do fire NOTIFY_HTML_HEAD_END (same hook Seekmodo uses), so that
 * is the primary event. NOTIFY_FOOTER_SCRIPTS remains for stock templates.
 */
class ChatbotWidgetObserver extends base
{
    private static bool $rendered = false;

    public function __construct()
    {
        $this->attach(
            $this,
            [
                'NOTIFY_HTML_HEAD_END',
                'NOTIFY_FOOTER_SCRIPTS',
            ]
        );
    }

    public function update(&$class, $eventID, &$param1 = null, &$param2 = null, &$param3 = null): void
    {
        if ($eventID === 'NOTIFY_HTML_HEAD_END' || $eventID === 'NOTIFY_FOOTER_SCRIPTS') {
            $this->renderWidget();
        }
    }

    private function renderWidget(): void
    {
        if (self::$rendered) {
            return;
        }
        if (!defined('SEEKMODO_AI_CHATBOT_ENABLED') || SEEKMODO_AI_CHATBOT_ENABLED !== 'true') {
            return;
        }
        if (
            class_exists('\\SeekmodoAiChatbot\\SeekmodoEntitlementGate')
            && !\SeekmodoAiChatbot\SeekmodoEntitlementGate::allowsStorefront()
        ) {
            return;
        }
        self::$rendered = true;

        $apiUrl = $this->chatApiUrl();
        $widgetGreeting = defined('SEEKMODO_AI_CHATBOT_WIDGET_GREETING')
            ? SEEKMODO_AI_CHATBOT_WIDGET_GREETING
            : 'Hi! How can I help you today?';
        $idleMinutes = defined('SEEKMODO_AI_CHATBOT_SESSION_IDLE_MINUTES')
            ? max(5, min(1440, (int) SEEKMODO_AI_CHATBOT_SESSION_IDLE_MINUTES))
            : 30;
        $botName = 'Assistant';
        $primary = self::sanitizeHexColor(
            defined('SEEKMODO_AI_CHATBOT_WIDGET_PRIMARY_COLOR') ? (string) SEEKMODO_AI_CHATBOT_WIDGET_PRIMARY_COLOR : '',
            '#10B981'
        );
        $secondary = self::sanitizeHexColor(
            defined('SEEKMODO_AI_CHATBOT_WIDGET_SECONDARY_COLOR') ? (string) SEEKMODO_AI_CHATBOT_WIDGET_SECONDARY_COLOR : '',
            '#06B6D4'
        );
        $launcher = [];
        if (defined('SEEKMODO_AI_CHATBOT_LAUNCHER_JSON') && (string) SEEKMODO_AI_CHATBOT_LAUNCHER_JSON !== '') {
            $decodedLauncher = json_decode((string) SEEKMODO_AI_CHATBOT_LAUNCHER_JSON, true);
            if (is_array($decodedLauncher)) {
                $launcher = $decodedLauncher;
            }
        }
        if (!empty($launcher['icon_color'])) {
            $primary = self::sanitizeHexColor((string) $launcher['icon_color'], $primary);
        }
        $pageType = 'other';
        $mainPage = isset($_GET['main_page']) ? (string) $_GET['main_page'] : '';
        if ($mainPage === 'index' && empty($_GET['cPath']) && empty($_GET['products_id'])) {
            $pageType = 'home';
        } elseif ($mainPage === 'product_info' || !empty($_GET['products_id'])) {
            $pageType = 'product';
        } elseif ($mainPage === 'index' && !empty($_GET['cPath'])) {
            $pageType = 'category';
        } elseif (in_array($mainPage, ['advanced_search_result', 'search', 'advanced_search'], true)) {
            $pageType = 'search';
        } elseif ($mainPage === 'shopping_cart') {
            $pageType = 'cart';
        } elseif (strpos($mainPage, 'checkout') === 0) {
            $pageType = 'checkout';
        } elseif (strpos($mainPage, 'account') === 0) {
            $pageType = 'account';
        }
        $agentIcon = self::widgetIconUrl();
        $launcherJsPath = dirname(__DIR__, 2) . '/javascript/seekmodo-chat-launcher.js';
        if (is_readable($launcherJsPath)) {
            echo '<script>' . file_get_contents($launcherJsPath) . '</script>';
        }
        ?>
<script>
(function() {
    const API_URL = <?php echo json_encode($apiUrl, JSON_UNESCAPED_SLASHES); ?>;
    const GREETING = <?php echo json_encode($widgetGreeting, JSON_UNESCAPED_UNICODE); ?>;
    const LAUNCHER = <?php echo json_encode($launcher, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
    const PAGE_TYPE = <?php echo json_encode($pageType); ?>;
    const BOT_NAME = <?php echo json_encode($botName, JSON_UNESCAPED_UNICODE); ?>;
    const AGENT_ICON = <?php echo json_encode($agentIcon, JSON_UNESCAPED_SLASHES); ?>;
    const STORAGE_KEY = 'seekmodo_ai_chatbot_v2';
    const STORAGE_KEY_V1 = 'seekmodo_ai_chatbot_v1';
    const MAX_STORED_MESSAGES = 40;
    const MAX_CHATS = 20;
    const IDLE_MS = <?php echo (int) ($idleMinutes * 60 * 1000); ?>;
    const EXPIRED_NOTICE = 'Your previous chat expired on the server. You can keep chatting here — earlier context may be unavailable.';
    const HANDED_OFF_NOTICE = 'Our team also replied by email. You can keep chatting here — email and live replies stay in this conversation.';
    const RESOLVED_NOTICE = 'This chat has been closed.';
    const LIVE_NOTICE = "You're chatting with our team.";
    const WELCOME_BLURB = 'We are online and happy to help. Say something to start a live chat, for example "Track my order?"';

    function loadRaw(key) {
        try {
            const raw = localStorage.getItem(key);
            if (!raw) return null;
            const data = JSON.parse(raw);
            return (data && typeof data === 'object') ? data : null;
        } catch (e) {
            return null;
        }
    }

    function saveState(state) {
        try {
            localStorage.setItem(STORAGE_KEY, JSON.stringify(state));
        } catch (e) { /* quota / private mode */ }
    }

    function newSessionId() {
        return 'sess_' + Date.now() + '_' + Math.random().toString(36).slice(2, 8);
    }

    function normalizeMessages(list) {
        if (!Array.isArray(list)) return [];
        return list.filter(function (m) {
            return m && (m.role === 'user' || m.role === 'bot' || m.role === 'admin') && typeof m.text === 'string' && m.text !== '';
        }).slice(-MAX_STORED_MESSAGES);
    }

    function serverMessagesToTranscript(list) {
        if (!Array.isArray(list)) return [];
        return list.filter(function (m) {
            return m && (m.role !== 'agent_note');
        }).map(function (m) {
            const role = m.role === 'user' ? 'user' : (m.role === 'admin' ? 'admin' : 'bot');
            return { role: role, text: String(m.content || '') };
        }).filter(function (m) { return m.text !== ''; });
    }

    function mergeTranscripts(local, server) {
        const localNorm = normalizeMessages(local);
        if (!server || !server.length) return localNorm;
        const serverNorm = serverMessagesToTranscript(server);
        if (!localNorm.length) return serverNorm.slice(-MAX_STORED_MESSAGES);
        const seen = new Set(localNorm.map(function (m) { return m.role + '\u0000' + m.text; }));
        const merged = localNorm.slice();
        serverNorm.forEach(function (m) {
            const key = m.role + '\u0000' + m.text;
            if (!seen.has(key)) {
                merged.push(m);
                seen.add(key);
            }
        });
        return merged.slice(-MAX_STORED_MESSAGES);
    }

    function snippet(text, maxLen) {
        const clean = String(text || '').replace(/\s+/g, ' ').trim();
        if (clean.length <= maxLen) return clean;
        return clean.slice(0, maxLen - 1) + '\u2026';
    }

    function chatTitle(messages) {
        for (let i = 0; i < messages.length; i++) {
            if (messages[i].role === 'user') {
                return snippet(messages[i].text, 48);
            }
        }
        return 'Conversation';
    }

    function chatPreview(messages) {
        for (let i = messages.length - 1; i >= 0; i--) {
            if (messages[i].text) {
                return snippet(messages[i].text, 56);
            }
        }
        return '';
    }

    function hasRealMessages(messages) {
        return normalizeMessages(messages).some(function (m) {
            return m.role === 'user' || (m.role === 'bot' && m.text !== GREETING && m.text !== EXPIRED_NOTICE);
        });
    }

    function relativeTime(ts) {
        if (typeof ts !== 'number' || !ts) return '';
        const diff = Math.max(0, Date.now() - ts);
        const sec = Math.floor(diff / 1000);
        if (sec < 60) return 'just now';
        const min = Math.floor(sec / 60);
        if (min < 60) return min === 1 ? '1 minute ago' : min + ' minutes ago';
        const hr = Math.floor(min / 60);
        if (hr < 24) return hr === 1 ? '1 hour ago' : hr + ' hours ago';
        const day = Math.floor(hr / 24);
        return day === 1 ? '1 day ago' : day + ' days ago';
    }

    function upsertChat(chats, chat) {
        const next = Array.isArray(chats) ? chats.slice() : [];
        const idx = next.findIndex(function (c) { return c.sessionId === chat.sessionId; });
        const entry = {
            sessionId: chat.sessionId,
            chatId: typeof chat.chatId === 'number' && chat.chatId
                ? chat.chatId
                : (idx >= 0 && next[idx].chatId ? next[idx].chatId : 0),
            title: chat.title || chatTitle(chat.messages || []),
            preview: chat.preview || chatPreview(chat.messages || []),
            updatedAt: typeof chat.updatedAt === 'number' ? chat.updatedAt : Date.now(),
            messages: normalizeMessages(chat.messages || []),
            status: chat.status || 'open',
            awaitingHuman: chat.awaitingHuman !== undefined
                ? !!chat.awaitingHuman
                : (idx >= 0 ? !!next[idx].awaitingHuman : false),
        };
        if (idx >= 0) {
            next[idx] = entry;
        } else {
            next.unshift(entry);
        }
        next.sort(function (a, b) { return (b.updatedAt || 0) - (a.updatedAt || 0); });
        return next.slice(0, MAX_CHATS);
    }

    function migrateFromV1(v1) {
        if (!v1 || typeof v1.sessionId !== 'string' || !/^[a-zA-Z0-9_-]+$/.test(v1.sessionId)) {
            return null;
        }
        const messages = normalizeMessages(v1.messages);
        const updatedAt = typeof v1.updatedAt === 'number' ? v1.updatedAt : Date.now();
        return {
            activeSessionId: v1.sessionId,
            open: !!v1.open,
            view: 'chat',
            chats: [{
                sessionId: v1.sessionId,
                title: chatTitle(messages),
                preview: chatPreview(messages),
                updatedAt: updatedAt,
                messages: messages,
            }],
        };
    }

    function loadState() {
        const v2 = loadRaw(STORAGE_KEY);
        if (v2 && Array.isArray(v2.chats)) {
            return {
                activeSessionId: typeof v2.activeSessionId === 'string' ? v2.activeSessionId : null,
                open: !!v2.open,
                view: v2.view === 'home' || v2.view === 'chat' ? v2.view : 'chat',
                chats: v2.chats.map(function (c) {
                    return {
                        sessionId: c.sessionId,
                        chatId: typeof c.chatId === 'number' ? c.chatId : 0,
                        title: c.title || chatTitle(c.messages || []),
                        preview: c.preview || chatPreview(c.messages || []),
                        updatedAt: typeof c.updatedAt === 'number' ? c.updatedAt : 0,
                        messages: normalizeMessages(c.messages),
                        status: c.status || 'open',
                        awaitingHuman: !!c.awaitingHuman,
                    };
                }).filter(function (c) {
                    return typeof c.sessionId === 'string' && /^[a-zA-Z0-9_-]+$/.test(c.sessionId);
                }).slice(0, MAX_CHATS),
            };
        }
        const migrated = migrateFromV1(loadRaw(STORAGE_KEY_V1));
        if (migrated) {
            saveState(migrated);
            try { localStorage.removeItem(STORAGE_KEY_V1); } catch (e) { /* ignore */ }
            return migrated;
        }
        return {
            activeSessionId: null,
            open: false,
            view: 'home',
            chats: [],
        };
    }

    function findChat(chats, sessionId) {
        return chats.find(function (c) { return c.sessionId === sessionId; }) || null;
    }

    function isIdle(chat) {
        if (!chat || typeof chat.updatedAt !== 'number') return false;
        return (Date.now() - chat.updatedAt) > IDLE_MS;
    }

    function mount() {
        if (document.getElementById('openai-chatbot-widget')) {
            return;
        }

        let state = loadState();
        let sessionId = state.activeSessionId;
        let transcript = [];
        let view = state.view || 'home';
        let loading = false;
        let chatStatus = 'open';
        let liveAgentName = '';
        let statusPollTimer = null;
        let assistantUnavailable = false;
        const pendingConnectBySession = {};
        let pendingContactEmail = '';

        const activeChat = sessionId ? findChat(state.chats, sessionId) : null;
        if (activeChat && isIdle(activeChat) && hasRealMessages(activeChat.messages)) {
            // Archive in place (already in chats) and return to home.
            view = 'home';
            sessionId = null;
            state.activeSessionId = null;
            state.view = 'home';
            saveState(state);
        } else if (activeChat) {
            transcript = normalizeMessages(activeChat.messages);
            chatStatus = activeChat.status || 'open';
        } else {
            sessionId = null;
            state.activeSessionId = null;
        }

        if (!state.chats.length) {
            view = 'chat';
        }

        const container = document.createElement('div');
        container.id = 'openai-chatbot-widget';
        container.innerHTML = `
        <div id="chatbot-toggle">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>
            </svg>
        </div>
        <div id="chatbot-panel">
            <div id="chatbot-header">
                <span id="chatbot-header-title">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>
                    </svg>
                    Chat
                </span>
                <div id="chatbot-header-actions">
                    <button type="button" id="chatbot-new" aria-label="New chat" title="New chat">New</button>
                    <button type="button" id="chatbot-close" aria-label="Close chat">&times;</button>
                </div>
            </div>
            <div id="chatbot-home" class="is-empty">
                <div id="chatbot-home-welcome"></div>
                <div id="chatbot-recent-card">
                    <div id="chatbot-recent-heading">Recent Conversations</div>
                    <p id="chatbot-recent-empty" class="chatbot-recent-empty" aria-hidden="true">No conversations yet. Ask about an order, product, or delivery.</p>
                    <div id="chatbot-recent-list"></div>
                    <button type="button" id="chatbot-start-chat" class="chatbot-start-cta">Start chat</button>
                </div>
                <div id="chatbot-tickets-card">
                    <div id="chatbot-tickets-heading">My tickets</div>
                    <p id="chatbot-tickets-empty" class="chatbot-recent-empty" aria-hidden="true">No tickets yet.</p>
                    <div id="chatbot-tickets-list"></div>
                </div>
            </div>
            <div id="chatbot-chat">
                <div id="chatbot-status-notice" class="chatbot-status-notice" hidden></div>
                <div id="chatbot-messages"></div>
                <div id="chatbot-contact-panel" class="chatbot-contact-panel" hidden>
                    <p id="chatbot-contact-help" class="chatbot-contact-help">Please leave your email so our team can reply.</p>
                    <div id="chatbot-contact-email-step">
                        <input type="email" id="chatbot-contact-email" placeholder="Email address" autocomplete="email">
                        <button type="button" id="chatbot-contact-send">Send code</button>
                    </div>
                    <div id="chatbot-contact-code-step" hidden>
                        <input type="text" id="chatbot-contact-code" placeholder="6-digit code" inputmode="numeric" autocomplete="one-time-code" maxlength="6">
                        <button type="button" id="chatbot-contact-verify">Verify</button>
                    </div>
                    <p id="chatbot-contact-status" class="chatbot-contact-status" hidden></p>
                </div>
                <div id="chatbot-ticket-confirm" class="chatbot-ticket-confirm" hidden>
                    <p class="chatbot-contact-help">Open a support ticket for an agent to follow up?</p>
                    <div class="chatbot-ticket-confirm-actions">
                        <button type="button" id="chatbot-ticket-yes" class="chatbot-ticket-btn chatbot-ticket-btn-primary">Yes, open a ticket</button>
                        <button type="button" id="chatbot-ticket-no" class="chatbot-ticket-btn">No thanks</button>
                    </div>
                    <p id="chatbot-ticket-status" class="chatbot-contact-status" hidden></p>
                </div>
                <div id="chatbot-input-area">
                    <input type="text" id="chatbot-input" placeholder="Type a message..." autocomplete="off">
                    <button type="button" id="chatbot-send" aria-label="Send message">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <line x1="22" y1="2" x2="11" y2="13"/>
                            <polygon points="22 2 15 22 11 13 2 9 22 2"/>
                        </svg>
                    </button>
                </div>
            </div>
            <nav id="chatbot-bottom-nav" aria-label="Chat navigation">
                <button type="button" id="chatbot-nav-home" class="chatbot-nav-btn" aria-label="Home">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M3 10.5L12 3l9 7.5"/>
                        <path d="M5 10v10h14V10"/>
                    </svg>
                    <span>Home</span>
                </button>
                <button type="button" id="chatbot-nav-chat" class="chatbot-nav-btn" aria-label="Chat">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>
                    </svg>
                    <span>Chat</span>
                </button>
            </nav>
        </div>
    `;
        document.body.appendChild(container);

        const toggle = document.getElementById('chatbot-toggle');
        const panel = document.getElementById('chatbot-panel');
        const close = document.getElementById('chatbot-close');
        const newChatBtn = document.getElementById('chatbot-new');
        const input = document.getElementById('chatbot-input');
        const send = document.getElementById('chatbot-send');
        const messages = document.getElementById('chatbot-messages');
        let stickToBottom = true;
        let paintingTranscript = false;
        function nearBottom() {
            return messages.scrollHeight - messages.scrollTop - messages.clientHeight <= 96;
        }
        function scrollMessagesToBottom(force) {
            if (force || stickToBottom) {
                stickToBottom = true;
                messages.scrollTop = messages.scrollHeight;
            }
        }
        messages.addEventListener('scroll', function () {
            if (paintingTranscript) return;
            stickToBottom = nearBottom();
        }, { passive: true });
        const homeEl = document.getElementById('chatbot-home');
        const chatEl = document.getElementById('chatbot-chat');
        const welcomeEl = document.getElementById('chatbot-home-welcome');
        const recentList = document.getElementById('chatbot-recent-list');
        const recentHeading = document.getElementById('chatbot-recent-heading');
        const recentEmpty = document.getElementById('chatbot-recent-empty');
        const ticketsCard = document.getElementById('chatbot-tickets-card');
        const ticketsList = document.getElementById('chatbot-tickets-list');
        const ticketsEmpty = document.getElementById('chatbot-tickets-empty');
        const startChatBtn = document.getElementById('chatbot-start-chat');
        const navHome = document.getElementById('chatbot-nav-home');
        const navChat = document.getElementById('chatbot-nav-chat');
        const statusNotice = document.getElementById('chatbot-status-notice');
        const contactPanel = document.getElementById('chatbot-contact-panel');
        const contactHelp = document.getElementById('chatbot-contact-help');
        const contactEmailStep = document.getElementById('chatbot-contact-email-step');
        const contactCodeStep = document.getElementById('chatbot-contact-code-step');
        const contactEmailInput = document.getElementById('chatbot-contact-email');
        const contactCodeInput = document.getElementById('chatbot-contact-code');
        const contactSendBtn = document.getElementById('chatbot-contact-send');
        const contactVerifyBtn = document.getElementById('chatbot-contact-verify');
        const contactStatus = document.getElementById('chatbot-contact-status');
        const ticketConfirm = document.getElementById('chatbot-ticket-confirm');
        const ticketYesBtn = document.getElementById('chatbot-ticket-yes');
        const ticketNoBtn = document.getElementById('chatbot-ticket-no');
        const ticketStatus = document.getElementById('chatbot-ticket-status');

        welcomeEl.textContent = GREETING || WELCOME_BLURB;

        function setContactStatus(text, isError) {
            if (!text) {
                contactStatus.hidden = true;
                contactStatus.textContent = '';
                return;
            }
            contactStatus.hidden = false;
            contactStatus.textContent = text;
            contactStatus.classList.toggle('is-error', !!isError);
        }

        function setTicketStatus(text, isError) {
            if (!ticketStatus) return;
            if (!text) {
                ticketStatus.hidden = true;
                ticketStatus.textContent = '';
                return;
            }
            ticketStatus.hidden = false;
            ticketStatus.textContent = text;
            ticketStatus.classList.toggle('is-error', !!isError);
        }

        function showTicketConfirm(show) {
            if (!ticketConfirm) return;
            ticketConfirm.hidden = !show;
            if (show) {
                ticketYesBtn.disabled = false;
                ticketNoBtn.disabled = false;
                setTicketStatus('');
            }
        }

        function showContactPanel(needsEmail) {
            if (!needsEmail) {
                contactPanel.hidden = true;
                return;
            }
            contactPanel.hidden = false;
            contactEmailStep.hidden = false;
            contactCodeStep.hidden = true;
            contactHelp.textContent = 'Please leave and verify your email so we can open a support ticket for an agent.';
            setContactStatus('');
        }

        function applyUnavailableResponse(data) {
            assistantUnavailable = true;
            if (data.reply) {
                addMessage(data.reply, 'bot');
            }
            const needsConfirm = !!data.needs_ticket_confirm;
            const needsEmail = !!data.needs_email;
            showTicketConfirm(needsConfirm);
            showContactPanel(!needsConfirm && needsEmail);
            if (data.ticket_opened && data.contact_email) {
                showTicketConfirm(false);
                showContactPanel(false);
                setContactStatus('Support ticket opened — we will email you at ' + data.contact_email + '.', false);
                contactPanel.hidden = false;
                contactEmailStep.hidden = true;
                contactCodeStep.hidden = true;
            } else if (!needsConfirm && !needsEmail && data.contact_email) {
                setContactStatus('We will email you at ' + data.contact_email + '.', false);
            }
            persist();
        }

        function setChatStatus(status) {
            const prev = chatStatus;
            chatStatus = status || 'open';
            if (sessionId) {
                state.chats = upsertChat(state.chats, {
                    sessionId: sessionId,
                    messages: transcript,
                    updatedAt: Date.now(),
                    status: chatStatus,
                });
            }
            applyStatusUi();
            persist();
            if (prev !== chatStatus && view === 'chat') {
                startStatusPolling();
            }
        }

        function setLoading(isLoading) {
            loading = !!isLoading;
            applyStatusUi();
        }

        function applyStatusUi() {
            const handedOff = chatStatus === 'handed_off';
            const resolved = chatStatus === 'resolved';
            const live = chatStatus === 'live';
            // Resolved stays locked; handed-off can continue (reopens on send).
            // Also lock while a request is in flight so state stays consistent.
            const contactOpen = contactPanel && !contactPanel.hidden
                && contactCodeStep && !contactCodeStep.hidden;
            const locked = resolved;
            input.disabled = locked;
            send.disabled = locked;
            if (handedOff) {
                statusNotice.hidden = false;
                statusNotice.textContent = HANDED_OFF_NOTICE;
                statusNotice.classList.remove('is-live');
            } else if (resolved) {
                statusNotice.hidden = false;
                statusNotice.textContent = RESOLVED_NOTICE;
                statusNotice.classList.remove('is-live');
            } else if (live) {
                statusNotice.hidden = false;
                statusNotice.textContent = liveAgentName
                    ? (LIVE_NOTICE + ' ' + liveAgentName)
                    : LIVE_NOTICE;
                statusNotice.classList.add('is-live');
            } else {
                statusNotice.hidden = true;
                statusNotice.textContent = '';
                statusNotice.classList.remove('is-live');
            }
            // Prefer the OTP field when verifying email so paste/focus is not "blocked"
            // on the main chat box.
            if (contactOpen && document.activeElement === input) {
                try { contactCodeInput.focus(); } catch (e) { /* ignore */ }
            }
        }

        async function apiPost(payload, signal) {
            const resp = await fetch(API_URL, {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                credentials: 'same-origin',
                body: JSON.stringify(payload),
                signal: signal,
            });
            const text = await resp.text();
            let data = null;
            try {
                data = text ? JSON.parse(text) : null;
            } catch (e) {
                data = null;
            }
            if (!resp.ok) {
                const err = (data && data.error) ? data.error : ('http_' + resp.status);
                return Object.assign({}, data || {}, { error: err, http_status: resp.status });
            }
            return data || {};
        }

        async function refreshChatStatus(id) {
            if (!id) return;
            try {
                const controller = typeof AbortController !== 'undefined' ? new AbortController() : null;
                const timer = controller ? setTimeout(function () { controller.abort(); }, 8000) : null;
                const resp = await fetch(API_URL, {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    credentials: 'same-origin',
                    body: JSON.stringify({ action: 'chat_status', session_id: id }),
                    signal: controller ? controller.signal : undefined,
                });
                if (timer) clearTimeout(timer);
                const data = await resp.json();
                if (data && data.error) return;
                if (typeof data.agent_name === 'string' && data.agent_name) {
                    liveAgentName = data.agent_name;
                } else if (data.status && data.status !== 'live') {
                    liveAgentName = '';
                }
                if (data.status) {
                    setChatStatus(data.status);
                }
                if (Array.isArray(data.messages)) {
                    transcript = mergeTranscripts(transcript, data.messages);
                    rerenderTranscript();
                }
                if (data.escalation_timed_out || data.needs_ticket_confirm || data.needs_email) {
                    if (data.recovery_message && typeof data.recovery_message === 'string') {
                        const already = transcript.some(function (m) {
                            return m && m.role === 'assistant' && m.content === data.recovery_message;
                        });
                        if (!already) {
                            addMessage(data.recovery_message, 'bot');
                        }
                    }
                    showTicketConfirm(!!data.needs_ticket_confirm);
                    if (!data.needs_ticket_confirm && data.needs_email) {
                        showContactPanel(true);
                    }
                    persist();
                }
            } catch (e) { /* ignore */ }
        }

        async function syncServerChats() {
            try {
                const [activeData, ticketData] = await Promise.all([
                    apiPost({ action: 'list_chats' }),
                    apiPost({ action: 'list_tickets' }),
                ]);
                const rows = [];
                if (activeData && Array.isArray(activeData.chats)) {
                    activeData.chats.forEach(function (row) { rows.push(row); });
                }
                if (ticketData && Array.isArray(ticketData.tickets)) {
                    ticketData.tickets.forEach(function (row) { rows.push(row); });
                }
                rows.forEach(function (row) {
                    if (!row || !row.session_id) return;
                    const existing = findChat(state.chats, row.session_id);
                    const mergedMessages = mergeTranscripts(
                        existing ? existing.messages : [],
                        row.messages || []
                    );
                    state.chats = upsertChat(state.chats, {
                        sessionId: row.session_id,
                        chatId: row.chat_id || (existing && existing.chatId) || 0,
                        preview: row.preview || chatPreview(mergedMessages),
                        updatedAt: row.updated_at ? Date.parse(String(row.updated_at).replace(' ', 'T')) || Date.now() : Date.now(),
                        messages: mergedMessages,
                        status: row.status || 'open',
                        awaitingHuman: !!row.awaiting_human,
                    });
                });
                persist();
                if (view === 'home') {
                    renderRecentList();
                    renderTicketsList();
                }
            } catch (e) { /* ignore */ }
        }

        function statusLabel(status) {
            if (status === 'resolved') return 'Resolved';
            if (status === 'handed_off') return 'Email';
            if (status === 'live') return 'Live';
            return 'Open';
        }

        async function reopenTicket(sessionIdToReopen) {
            if (!sessionIdToReopen) return;
            try {
                const data = await apiPost({ action: 'reopen_chat', session_id: sessionIdToReopen });
                if (!data || data.error || !data.ok) return;
                const existing = findChat(state.chats, sessionIdToReopen);
                state.chats = upsertChat(state.chats, {
                    sessionId: sessionIdToReopen,
                    chatId: data.chat_id || (existing && existing.chatId) || 0,
                    messages: existing ? existing.messages : [],
                    preview: existing ? existing.preview : '',
                    updatedAt: Date.now(),
                    status: data.status || 'open',
                    awaitingHuman: false,
                });
                persist();
                openChat(sessionIdToReopen);
            } catch (e) { /* ignore */ }
        }

        function renderTicketsList() {
            if (!ticketsList || !ticketsCard) return;
            const tickets = (state.chats || []).filter(function (c) {
                return hasRealMessages(c.messages);
            }).slice(0, 20);
            ticketsList.innerHTML = '';
            if (!tickets.length) {
                ticketsEmpty.setAttribute('aria-hidden', 'false');
                ticketsList.style.display = 'none';
                return;
            }
            ticketsEmpty.setAttribute('aria-hidden', 'true');
            ticketsList.style.display = 'block';
            tickets.forEach(function (c) {
                const row = document.createElement('div');
                row.className = 'chatbot-ticket-item';
                row.setAttribute('data-session-id', c.sessionId);
                const status = c.status || 'open';
                const chips = '<span class="chatbot-ticket-chip chatbot-ticket-status-' + escapeHtml(status) + '">'
                    + escapeHtml(statusLabel(status)) + '</span>'
                    + (c.awaitingHuman ? '<span class="chatbot-ticket-chip chatbot-ticket-awaiting">Awaiting reply</span>' : '');
                const actions = status === 'resolved'
                    ? '<button type="button" class="chatbot-ticket-reopen">Reopen</button>'
                    : '<span class="chatbot-recent-chevron" aria-hidden="true">&#8250;</span>';
                row.innerHTML =
                    '<button type="button" class="chatbot-ticket-open">'
                    + '<div class="chatbot-recent-meta">'
                    + '<span class="chatbot-recent-name">' + escapeHtml(BOT_NAME) + '</span>'
                    + '<span class="chatbot-recent-time">' + escapeHtml(relativeTime(c.updatedAt)) + '</span>'
                    + '</div>'
                    + '<div class="chatbot-ticket-chips">' + chips + '</div>'
                    + '<div class="chatbot-recent-preview">' + escapeHtml(c.preview || c.title || 'Ticket') + '</div>'
                    + '</button>'
                    + actions;
                const openBtn = row.querySelector('.chatbot-ticket-open');
                openBtn.addEventListener('click', function () {
                    openChat(c.sessionId);
                });
                const reopenBtn = row.querySelector('.chatbot-ticket-reopen');
                if (reopenBtn) {
                    reopenBtn.addEventListener('click', function (ev) {
                        ev.preventDefault();
                        ev.stopPropagation();
                        reopenTicket(c.sessionId);
                    });
                }
                ticketsList.appendChild(row);
            });
        }

        function rerenderTranscript() {
            const shouldStick = stickToBottom;
            const prevTop = messages.scrollTop;
            paintingTranscript = true;
            clearMessagesUi();
            transcript.forEach(function (m) {
                addMessage(m.text, m.role, false, { skipScroll: true });
            });
            paintingTranscript = false;
            if (shouldStick) scrollMessagesToBottom(true);
            else messages.scrollTop = prevTop;
            applyStatusUi();
        }

        function startStatusPolling() {
            stopStatusPolling();
            if (!sessionId || view !== 'chat') return;
            // Live human: snappy. AI/open chats: still poll often enough that
            // admin replies appear quickly after handoff without hammering.
            const intervalMs = chatStatus === 'live' ? 2000 : (chatStatus === 'handed_off' ? 5000 : 10000);
            refreshChatStatus(sessionId);
            statusPollTimer = setInterval(function () {
                refreshChatStatus(sessionId);
            }, intervalMs);
        }

        function stopStatusPolling() {
            if (statusPollTimer) {
                clearInterval(statusPollTimer);
                statusPollTimer = null;
            }
        }

        async function resolveCurrentChat() {
            if (!sessionId) return;
            try {
                await apiPost({ action: 'resolve_chat', session_id: sessionId });
            } catch (e) { /* ignore */ }
            setChatStatus('resolved');
        }

        function persist() {
            if (sessionId && transcript.length) {
                state.chats = upsertChat(state.chats, {
                    sessionId: sessionId,
                    messages: transcript,
                    updatedAt: Date.now(),
                    status: chatStatus,
                });
            }
            state.activeSessionId = sessionId;
            state.view = view;
            state.open = panel.classList.contains('open');
            saveState(state);
        }

        function adoptServerSessionId(data) {
            if (!data || typeof data.session_id !== 'string' || !data.session_id) {
                return;
            }
            if (data.session_id === sessionId) {
                return;
            }
            const oldId = sessionId;
            sessionId = data.session_id;
            if (oldId) {
                state.chats = state.chats.filter(function (c) { return c.sessionId !== oldId; });
            }
        }

        function escapeHtml(text) {
            return String(text)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#39;');
        }

        function safeUrl(url) {
            const trimmed = String(url || '').trim();
            if (!/^https?:\/\//i.test(trimmed)) {
                return '';
            }
            // Encode # so Converted_to_pdf filenames like A8#1#123#Name.pdf are not
            // treated as URL fragments (which drops the user on the homepage).
            try {
                const parsed = new URL(trimmed);
                parsed.pathname = parsed.pathname.split('/').map(function (seg) {
                    return encodeURIComponent(decodeURIComponent(seg));
                }).join('/');
                return parsed.toString().replace(/"/g, '%22');
            } catch (e) {
                return trimmed.replace(/#/g, '%23').replace(/"/g, '%22');
            }
        }

        function formatMessage(text) {
            let html = escapeHtml(text);
            html = html.replace(/!\[([^\]]*)\]\((https?:\/\/[^)\s]+)\)/g, function (_, alt, url) {
                const href = safeUrl(url);
                if (!href) return _;
                return '<a class="chatbot-image-link" href="' + href + '" target="_blank" rel="noopener noreferrer">'
                    + '<img class="chatbot-product-image" src="' + href + '" alt="' + alt + '" loading="lazy" '
                    + 'onerror="this.onerror=null;var a=this.closest(\'a\');if(a){a.remove();}else{this.remove();}">'
                    + '</a>';
            });
            html = html.replace(/\[([^\]]+)\]\((https?:\/\/[^)\s]+)\)/g, function (_, label, url) {
                const href = safeUrl(url);
                if (!href) return _;
                return '<a href="' + href + '" target="_blank" rel="noopener noreferrer">' + label + '</a>';
            });
            html = html.replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>');
            html = html.replace(/(^|<br>|\n)(\d+)\.\s+/g, '$1<span class="chatbot-product-num">$2.</span> ');
            html = html.replace(/\n{2,}/g, '</p><p class="chatbot-gap">');
            html = html.replace(/\n/g, '<br>');
            return '<p>' + html + '</p>';
        }

        function addMessage(text, role, shouldPersist, opts) {
            const msg = document.createElement('div');
            const displayRole = role === 'admin' ? 'admin' : role;
            msg.className = 'chatbot-msg ' + displayRole;
            if (role === 'bot' || role === 'admin') {
                msg.innerHTML = formatMessage(text);
            } else {
                msg.textContent = text;
            }
            messages.appendChild(wrapAgentMessage(msg, role));
            if (!(opts && opts.skipScroll)) {
                scrollMessagesToBottom(!!(opts && opts.forceScroll) || role === 'user');
            }
            if (shouldPersist !== false) {
                transcript.push({role: role, text: text});
                if (transcript.length > MAX_STORED_MESSAGES) {
                    transcript = transcript.slice(-MAX_STORED_MESSAGES);
                }
                persist();
            }
        }

        function clearMessagesUi() {
            messages.innerHTML = '';
        }

        function renderRecentList() {
            const chats = (state.chats || []).filter(function (c) {
                return hasRealMessages(c.messages) && (c.status || 'open') !== 'resolved';
            });
            recentList.innerHTML = '';
            const isEmpty = !chats.length;
            homeEl.classList.toggle('is-empty', isEmpty);
            if (isEmpty) {
                recentHeading.textContent = 'Get started';
                recentEmpty.setAttribute('aria-hidden', 'false');
                startChatBtn.style.display = 'block';
                recentList.style.display = 'none';
                return;
            }
            recentHeading.textContent = 'Recent Conversations';
            recentEmpty.setAttribute('aria-hidden', 'true');
            startChatBtn.style.display = 'none';
            recentList.style.display = 'block';
            chats.forEach(function (c) {
                const btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'chatbot-recent-item';
                btn.setAttribute('data-session-id', c.sessionId);
                btn.innerHTML =
                    '<div class="chatbot-recent-meta">'
                    + '<span class="chatbot-recent-name">' + escapeHtml(BOT_NAME) + '</span>'
                    + '<span class="chatbot-recent-time">' + escapeHtml(relativeTime(c.updatedAt)) + '</span>'
                    + '</div>'
                    + '<div class="chatbot-recent-preview">' + escapeHtml(c.preview || c.title || 'Conversation') + '</div>'
                    + '<span class="chatbot-recent-chevron" aria-hidden="true">&#8250;</span>';
                btn.addEventListener('click', function () {
                    openChat(c.sessionId);
                });
                recentList.appendChild(btn);
            });
        }

        function applyView() {
            const onHome = view === 'home';
            homeEl.classList.toggle('active', onHome);
            chatEl.classList.toggle('active', !onHome);
            newChatBtn.style.display = onHome ? 'none' : '';
            navHome.classList.toggle('active', onHome);
            navChat.classList.toggle('active', !onHome);
            if (onHome) {
                renderRecentList();
                renderTicketsList();
            }
        }

        function showHome() {
            stopStatusPolling();
            if (sessionId && transcript.length) {
                state.chats = upsertChat(state.chats, {
                    sessionId: sessionId,
                    messages: transcript,
                    updatedAt: Date.now(),
                    status: chatStatus,
                });
            }
            view = 'home';
            applyView();
            persist();
        }

        function openChat(id, options) {
            options = options || {};
            stopStatusPolling();
            const existing = id ? findChat(state.chats, id) : null;
            if (existing) {
                sessionId = existing.sessionId;
                transcript = normalizeMessages(existing.messages);
                chatStatus = existing.status || 'open';
            } else {
                sessionId = newSessionId();
                transcript = [];
                chatStatus = 'open';
            }
            assistantUnavailable = false;
            pendingContactEmail = '';
            contactPanel.hidden = true;
            contactEmailStep.hidden = false;
            contactCodeStep.hidden = true;
            contactEmailInput.value = '';
            contactCodeInput.value = '';
            setContactStatus('');
            showTicketConfirm(false);
            setTicketStatus('');
            view = 'chat';
            stickToBottom = true;
            paintingTranscript = true;
            clearMessagesUi();
            if (transcript.length === 0) {
                if (options.notice) {
                    addMessage(options.notice, 'bot', undefined, { skipScroll: true });
                }
                addMessage(GREETING, 'bot', undefined, { skipScroll: true });
            } else {
                transcript.forEach(function (m) {
                    addMessage(m.text, m.role, false, { skipScroll: true });
                });
                if (options.notice) {
                    addMessage(options.notice, 'bot', undefined, { skipScroll: true });
                }
            }
            paintingTranscript = false;
            scrollMessagesToBottom(true);
            applyView();
            applyStatusUi();
            persist();
            refreshChatStatus(sessionId).then(function () {
                startStatusPolling();
            });
            if (panel.classList.contains('open')) {
                input.focus();
            }
        }

        function startNewChat(notice) {
            stopStatusPolling();
            if (sessionId && hasRealMessages(transcript)) {
                state.chats = upsertChat(state.chats, {
                    sessionId: sessionId,
                    messages: transcript,
                    updatedAt: Date.now(),
                    status: chatStatus,
                });
            }
            openChat(null, { notice: notice || null });
        }

        function wrapAgentMessage(msg, role) {
            if (!AGENT_ICON || (role !== 'bot' && role !== 'admin')) {
                return msg;
            }
            const row = document.createElement('div');
            row.className = 'chatbot-msg-row';
            const avatar = document.createElement('img');
            avatar.className = 'chatbot-avatar';
            avatar.src = AGENT_ICON;
            avatar.alt = '';
            avatar.setAttribute('aria-hidden', 'true');
            avatar.loading = 'lazy';
            row.appendChild(avatar);
            row.appendChild(msg);
            return row;
        }

        function showTyping() {
            const el = document.createElement('div');
            el.className = 'chatbot-msg bot typing';
            el.id = 'chatbot-typing';
            el.textContent = '...';
            messages.appendChild(wrapAgentMessage(el, 'bot'));
            scrollMessagesToBottom(false);
        }

        function hideTyping() {
            const el = document.getElementById('chatbot-typing');
            if (!el) return;
            const row = el.closest('.chatbot-msg-row');
            (row || el).remove();
        }

        // Initial view
        if (view === 'home' && state.chats.some(function (c) { return hasRealMessages(c.messages); })) {
            applyView();
            persist();
        } else if (sessionId && transcript.length) {
            view = 'chat';
            clearMessagesUi();
            transcript.forEach(function (m) {
                addMessage(m.text, m.role, false);
            });
            applyView();
            applyStatusUi();
            persist();
            refreshChatStatus(sessionId).then(function () {
                startStatusPolling();
            });
        } else {
            startNewChat();
        }

        if (state.open) {
            panel.classList.add('open');
        }

        // Deep link: ?seekmodo_tickets=1 or #seekmodo-tickets (+ optional session_id / chat_id).
        // Theme header example: <a href="/?seekmodo_tickets=1">Tickets</a>
        function applyTicketsDeepLink() {
            let openTickets = false;
            let targetSession = '';
            let targetChatId = 0;
            try {
                const params = new URLSearchParams(window.location.search || '');
                if (params.get('seekmodo_tickets') === '1') {
                    openTickets = true;
                }
                targetSession = params.get('session_id') || params.get('seekmodo_session') || '';
                targetChatId = parseInt(params.get('chat_id') || params.get('seekmodo_chat') || '0', 10) || 0;
            } catch (e) { /* ignore */ }
            if ((window.location.hash || '') === '#seekmodo-tickets') {
                openTickets = true;
            }
            if (!openTickets && !targetSession && !targetChatId) {
                return;
            }
            panel.classList.add('open');
            showHome();
            syncServerChats().then(function () {
                if (targetSession) {
                    const found = findChat(state.chats, targetSession);
                    if (found) {
                        if ((found.status || 'open') === 'resolved') {
                            reopenTicket(targetSession);
                        } else {
                            openChat(targetSession);
                        }
                        return;
                    }
                }
                if (targetChatId > 0) {
                    const byId = (state.chats || []).find(function (c) {
                        return Number(c.chatId) === targetChatId;
                    });
                    if (byId) {
                        if ((byId.status || 'open') === 'resolved') {
                            reopenTicket(byId.sessionId);
                        } else {
                            openChat(byId.sessionId);
                        }
                        return;
                    }
                }
                if (ticketsCard) {
                    ticketsCard.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                }
            });
        }
        applyTicketsDeepLink();

        toggle.addEventListener('click', () => {
            panel.classList.toggle('open');
            persist();
            if (panel.classList.contains('open')) {
                if (window.SeekmodoChatLauncher) {
                    window.SeekmodoChatLauncher.markOpened(window.sessionStorage, 'manual');
                }
                syncServerChats();
                if (view === 'chat') {
                    input.focus();
                    startStatusPolling();
                } else {
                    renderRecentList();
                    renderTicketsList();
                }
            } else {
                stopStatusPolling();
            }
        });
        (function applyLauncher() {
            const L = window.SeekmodoChatLauncher;
            window.SeekmodoChatPage = { pageType: PAGE_TYPE };
            if (!L || !LAUNCHER) return;
            L.ensureFlairStyles();
            const flair = L.createFlair(toggle);
            L.applyAppearance(toggle, flair, LAUNCHER);
            if (flair) {
                flair.addEventListener('click', function () {
                    L.markOpened(window.sessionStorage, 'manual');
                    if (!panel.classList.contains('open')) toggle.click();
                });
            }
            const started = Date.now();
            function context(seconds) {
                const query = {};
                new URLSearchParams(window.location.search).forEach(function (value, key) { query[key] = value; });
                return {
                    path: window.location.pathname,
                    query: query,
                    pageType: PAGE_TYPE,
                    device: window.matchMedia('(max-width: 768px)').matches ? 'mobile' : 'desktop',
                    visitor: L.visitorKind(window.localStorage),
                    secondsOnPage: seconds,
                    now: new Date(),
                };
            }
            function check() {
                if (L.alreadyOpened(window.sessionStorage)) return;
                const result = L.evaluate(LAUNCHER, context((Date.now() - started) / 1000), new Date());
                if (!result) return;
                if (result.waitMs > 0) {
                    window.setTimeout(check, result.waitMs);
                    return;
                }
                L.markOpened(window.sessionStorage, result.automation.id || '1');
                openChat(null, { notice: result.automation.message || '' });
                if (!panel.classList.contains('open')) panel.classList.add('open');
            }
            check();
        })();

        document.addEventListener('visibilitychange', function () {
            if (document.visibilityState !== 'visible') return;
            if (!panel.classList.contains('open') || view !== 'chat' || !sessionId) return;
            refreshChatStatus(sessionId);
        });

        close.addEventListener('click', async () => {
            if (view === 'chat' && sessionId && hasRealMessages(transcript) && chatStatus !== 'resolved') {
                await resolveCurrentChat();
            }
            stopStatusPolling();
            panel.classList.remove('open');
            showHome();
        });

        newChatBtn.addEventListener('click', () => {
            if (loading) return;
            startNewChat();
        });

        startChatBtn.addEventListener('click', () => {
            if (loading) return;
            startNewChat();
        });

        navHome.addEventListener('click', () => {
            showHome();
        });

        navChat.addEventListener('click', () => {
            if (loading) return;
            // Already in an open conversation — keep focus on the input.
            if (view === 'chat' && sessionId) {
                input.focus();
                return;
            }
            // From Home (or no active chat): always start fresh. Continuing an
            // existing thread is done by tapping that conversation in the list.
            startNewChat();
        });

        // Opportunistic idle check while panel is open
        setInterval(function () {
            if (!sessionId || view !== 'chat') return;
            const live = findChat(state.chats, sessionId);
            const updatedAt = live ? live.updatedAt : Date.now();
            if (isIdle({ updatedAt: updatedAt }) && hasRealMessages(transcript)) {
                state.chats = upsertChat(state.chats, {
                    sessionId: sessionId,
                    messages: transcript,
                    updatedAt: updatedAt,
                });
                sessionId = null;
                state.activeSessionId = null;
                view = 'home';
                applyView();
                persist();
            }
        }, 60000);

        const SEND_GROUP_MS = 2000;
        let pendingTexts = [];
        let sendTimer = null;
        let chatAbort = null;

        function sendMessage() {
            const text = input.value.trim();
            if (!text || chatStatus === 'resolved') return;
            input.value = '';
            addMessage(text, 'user');
            pendingTexts.push(text);
            if (chatAbort) {
                chatAbort.abort();
                chatAbort = null;
                hideTyping();
            }
            if (sendTimer) clearTimeout(sendTimer);
            sendTimer = setTimeout(flushPending, SEND_GROUP_MS);
        }

        async function flushPending() {
            sendTimer = null;
            if (!pendingTexts.length || chatStatus === 'resolved') return;
            const batch = pendingTexts.join('\n');
            pendingTexts = [];
            if (!sessionId) {
                sessionId = newSessionId();
            }
            showTyping();
            const controller = typeof AbortController !== 'undefined' ? new AbortController() : null;
            chatAbort = controller;
            try {
                const data = await apiPost({
                    message: batch,
                    session_id: sessionId,
                }, controller ? controller.signal : undefined);
                if (controller && controller.signal.aborted) return;
                hideTyping();
                if (data.session_reset) {
                    // Server rotated the session (unowned localStorage leftover).
                    // Keep only the message just sent — do not stitch the reply
                    // onto an unrelated prior transcript.
                    adoptServerSessionId(data);
                    const keptUser = [];
                    for (let i = transcript.length - 1; i >= 0; i--) {
                        if (transcript[i] && transcript[i].role === 'user') {
                            keptUser.unshift(transcript[i]);
                            break;
                        }
                    }
                    transcript = keptUser;
                    clearMessagesUi();
                    keptUser.forEach(function (m) {
                        addMessage(m.text, m.role, false);
                    });
                    if (data.assistant_unavailable) {
                        applyUnavailableResponse(data);
                    } else if (data.reply) {
                        addMessage(data.reply, 'bot');
                    } else if (data.error) {
                        addMessage('Sorry, I encountered an error: ' + data.error, 'bot');
                    }
                    persist();
                } else if (data.assistant_unavailable) {
                    applyUnavailableResponse(data);
                } else if (data.error) {
                    addMessage('Sorry, I encountered an error: ' + data.error, 'bot');
                } else if (data.live_agent || data.status === 'live') {
                    setChatStatus('live');
                    // Human took over — pull any admin replies that arrived while we waited.
                    refreshChatStatus(sessionId);
                } else if (data.reply) {
                    addMessage(data.reply, 'bot');
                }
                if (data.status && data.status !== 'live') {
                    setChatStatus(data.status);
                }
                if (data.escalation_timed_out || data.needs_ticket_confirm || data.needs_email) {
                    if (data.recovery_message && typeof data.recovery_message === 'string') {
                        const already = transcript.some(function (m) {
                            return m && m.role === 'assistant' && m.content === data.recovery_message;
                        });
                        if (!already) {
                            addMessage(data.recovery_message, 'bot');
                        }
                    }
                    showTicketConfirm(!!data.needs_ticket_confirm);
                    if (!data.needs_ticket_confirm && data.needs_email) {
                        showContactPanel(true);
                    }
                    persist();
                }
            } catch (e) {
                if (e && e.name === 'AbortError') return;
                hideTyping();
                pendingConnectBySession[sessionId] = batch;
                applyUnavailableResponse({
                    reply: 'I\'m sorry — our chatbot isn\'t available right now, and no one is immediately available in chat. Would you like us to open a support ticket so an agent can follow up?',
                    needs_ticket_confirm: true,
                    assistant_unavailable: true,
                });
            }
            if (chatAbort === controller) {
                chatAbort = null;
            }
            if (!input.disabled) {
                try { input.focus(); } catch (e) { /* ignore */ }
            }
        }

        async function sendContactCode() {
            if (loading) return;
            const email = (contactEmailInput.value || '').trim();
            if (!email) {
                setContactStatus('Please enter your email address.', true);
                return;
            }
            if (!sessionId) {
                sessionId = newSessionId();
            }
            setLoading(true);
            contactSendBtn.disabled = true;
            setContactStatus('Sending verification code…', false);
            try {
                const data = await apiPost({
                    action: 'leave_contact_send',
                    session_id: sessionId,
                    email: email,
                });
                if (data && typeof data.session_id === 'string' && data.session_id) {
                    adoptServerSessionId(data);
                }
                if (data && data.ok) {
                    pendingContactEmail = email;
                    contactEmailStep.hidden = true;
                    contactCodeStep.hidden = false;
                    contactHelp.textContent = 'Enter the 6-digit code we emailed to ' + email + '.';
                    setContactStatus('Code sent. Check your inbox.', false);
                    try { contactCodeInput.focus(); } catch (e) { /* ignore */ }
                } else {
                    setContactStatus((data && (data.message || data.error)) || 'Could not send the code. Please try again.', true);
                }
            } catch (e) {
                setContactStatus('Could not send the code. Please try again.', true);
            }
            contactSendBtn.disabled = false;
            setLoading(false);
            if (contactCodeStep && !contactCodeStep.hidden) {
                try { contactCodeInput.focus(); } catch (e) { /* ignore */ }
            }
        }

        async function verifyContactCode() {
            if (loading) return;
            const code = (contactCodeInput.value || '').replace(/\D+/g, '');
            const email = pendingContactEmail || (contactEmailInput.value || '').trim();
            if (!email || code.length !== 6) {
                setContactStatus('Enter the 6-digit code from your email.', true);
                return;
            }
            if (!sessionId) {
                sessionId = newSessionId();
            }
            setLoading(true);
            contactVerifyBtn.disabled = true;
            setContactStatus('Verifying…', false);
            try {
                const data = await apiPost({
                    action: 'leave_contact_verify',
                    session_id: sessionId,
                    email: email,
                    code: code,
                });
                if (data && typeof data.session_id === 'string' && data.session_id) {
                    adoptServerSessionId(data);
                }
                if (data && data.ok) {
                    assistantUnavailable = true;
                    showTicketConfirm(false);
                    showContactPanel(false);
                    contactPanel.hidden = false;
                    contactEmailStep.hidden = true;
                    contactCodeStep.hidden = true;
                    if (data.reply) {
                        addMessage(data.reply, 'bot');
                    }
                    setContactStatus('Support ticket opened — we will email you at ' + email + '.', false);
                    persist();
                } else {
                    setContactStatus((data && (data.message || data.error)) || 'That code did not match. Try again.', true);
                }
            } catch (e) {
                setContactStatus('Could not verify the code. Please try again.', true);
            }
            contactVerifyBtn.disabled = false;
            setLoading(false);
            if (contactCodeStep && !contactCodeStep.hidden) {
                try { contactCodeInput.focus(); } catch (e) { /* ignore */ }
            }
        }

        async function confirmOfflineTicket(openTicket) {
            if (loading) return;
            if (!sessionId) {
                sessionId = newSessionId();
            }
            setLoading(true);
            ticketYesBtn.disabled = true;
            ticketNoBtn.disabled = true;
            setTicketStatus(openTicket ? 'Opening ticket…' : 'Updating…', false);
            try {
                const data = await apiPost({
                    action: openTicket ? 'confirm_offline_ticket' : 'decline_offline_ticket',
                    session_id: sessionId,
                    message: pendingConnectBySession[sessionId] || '',
                });
                delete pendingConnectBySession[sessionId];
                if (data && typeof data.session_id === 'string' && data.session_id) {
                    adoptServerSessionId(data);
                }
                if (data && data.ok) {
                    showTicketConfirm(false);
                    if (data.reply) {
                        addMessage(data.reply, 'bot');
                    }
                    if (openTicket && data.contact_email) {
                        contactPanel.hidden = false;
                        contactEmailStep.hidden = true;
                        contactCodeStep.hidden = true;
                        setContactStatus('Support ticket opened — we will email you at ' + data.contact_email + '.', false);
                    } else {
                        showContactPanel(false);
                    }
                    persist();
                } else if (data && data.needs_email) {
                    showTicketConfirm(false);
                    showContactPanel(true);
                    setTicketStatus((data.message || 'Please leave and verify your email so we can open a support ticket.'), false);
                } else {
                    setTicketStatus((data && (data.message || data.error)) || 'Could not update. Please try again.', true);
                    ticketYesBtn.disabled = false;
                    ticketNoBtn.disabled = false;
                }
            } catch (e) {
                setTicketStatus('Could not update. Please try again.', true);
                ticketYesBtn.disabled = false;
                ticketNoBtn.disabled = false;
            }
            setLoading(false);
        }

        send.addEventListener('click', sendMessage);
        input.addEventListener('keydown', (e) => {
            if (e.key === 'Enter') sendMessage();
        });
        contactSendBtn.addEventListener('click', sendContactCode);
        contactVerifyBtn.addEventListener('click', verifyContactCode);
        ticketYesBtn.addEventListener('click', function () { confirmOfflineTicket(true); });
        ticketNoBtn.addEventListener('click', function () { confirmOfflineTicket(false); });
        contactEmailInput.addEventListener('keydown', (e) => {
            if (e.key === 'Enter') sendContactCode();
        });
        contactCodeInput.addEventListener('keydown', (e) => {
            if (e.key === 'Enter') verifyContactCode();
        });

        syncServerChats();
        applyStatusUi();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', mount);
    } else {
        mount();
    }
})();
</script>
<style>
:root {
    --chatbot-primary: <?php echo htmlspecialchars($primary, ENT_QUOTES); ?>;
    --chatbot-secondary: <?php echo htmlspecialchars($secondary, ENT_QUOTES); ?>;
}
@import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap');
#openai-chatbot-widget {
    position: fixed;
    bottom: 20px;
    right: 20px;
    z-index: 99999;
    font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
}
#chatbot-toggle {
    width: 56px; height: 56px;
    background: linear-gradient(135deg, var(--chatbot-primary) 0%, var(--chatbot-secondary) 100%);
    color: #fff;
    border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    cursor: pointer; box-shadow: 0 4px 16px color-mix(in srgb, var(--chatbot-primary) 35%, transparent);
    transition: transform 0.2s;
}
#chatbot-toggle:hover { transform: scale(1.08); }
#chatbot-panel {
    display: none;
    position: absolute;
    bottom: 68px; right: 0;
    width: 380px; max-height: 560px;
    height: 520px;
    background: #fff; border-radius: 12px;
    box-shadow: 0 4px 24px rgba(17,24,39,0.18);
    flex-direction: column; overflow: hidden;
}
#chatbot-panel.open { display: flex; }
#chatbot-header {
    background: linear-gradient(135deg, #111827 0%, var(--chatbot-primary) 55%, var(--chatbot-secondary) 100%);
    color: #fff;
    padding: 14px 18px;
    display: flex; justify-content: space-between; align-items: center;
    flex-shrink: 0;
}
#chatbot-header-title {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    font-size: 12px;
    font-weight: 600;
    line-height: 1;
}
#chatbot-header-title svg { flex-shrink: 0; }
#chatbot-header-actions { display: flex; align-items: center; gap: 8px; }
#chatbot-new {
    background: rgba(255,255,255,0.18);
    border: 1px solid rgba(255,255,255,0.45);
    color: #fff;
    font-size: 12px;
    font-weight: 600;
    line-height: 1;
    padding: 6px 10px;
    border-radius: 999px;
    cursor: pointer;
}
#chatbot-new:hover { background: rgba(255,255,255,0.28); }
#chatbot-close { background: none; border: none; color: #fff; font-size: 22px; cursor: pointer; }

#chatbot-home {
    display: none;
    flex: 1;
    flex-direction: column;
    overflow-y: auto;
    /* Blue band sized to the welcome area only — avoids a hard mid-panel colour break. */
    background: linear-gradient(180deg, var(--chatbot-primary) 0%, var(--chatbot-secondary) 108px, #f3f4f6 108px, #f3f4f6 100%);
    padding: 8px 16px 16px;
    min-height: 0;
}
#chatbot-home.active { display: flex; }
#chatbot-home-welcome {
    color: #fff;
    font-size: 15px;
    line-height: 1.45;
    text-align: center;
    padding: 12px 8px 20px;
    font-weight: 500;
    flex-shrink: 0;
}
#chatbot-recent-card {
    background: #fff;
    border-radius: 12px;
    box-shadow: 0 2px 12px rgba(0,0,0,0.08);
    padding: 14px 12px;
}
#chatbot-tickets-card {
    background: #fff;
    border-radius: 12px;
    box-shadow: 0 2px 12px rgba(0,0,0,0.08);
    padding: 14px 12px;
    margin-top: 12px;
}
#chatbot-tickets-heading {
    font-size: 15px;
    font-weight: 700;
    color: #1a1a1a;
    margin: 0 4px 10px;
}
.chatbot-ticket-item {
    display: flex;
    align-items: stretch;
    gap: 6px;
    border-top: 1px solid #eef0f3;
}
.chatbot-ticket-item:first-child { border-top: none; }
.chatbot-ticket-open {
    flex: 1;
    text-align: left;
    background: none;
    border: none;
    cursor: pointer;
    padding: 12px 4px;
    font: inherit;
}
.chatbot-ticket-open:hover { background: #f7f9fc; border-radius: 8px; }
.chatbot-ticket-chips {
    display: flex;
    flex-wrap: wrap;
    gap: 4px;
    margin: 4px 0 2px;
}
.chatbot-ticket-chip {
    display: inline-block;
    font-size: 10px;
    font-weight: 700;
    padding: 2px 6px;
    border-radius: 999px;
    background: #f3f4f6;
    color: #4b5563;
}
.chatbot-ticket-status-open { background: #ecfeff; color: #0e7490; }
.chatbot-ticket-status-live { background: #ecfdf5; color: #047857; }
.chatbot-ticket-status-handed_off { background: #fff7ed; color: #c2410c; }
.chatbot-ticket-status-resolved { background: #f3f4f6; color: #4b5563; }
.chatbot-ticket-awaiting { background: #fef3c7; color: #b45309; }
.chatbot-ticket-reopen {
    align-self: center;
    margin-right: 4px;
    border: 1px solid #d1d5db;
    background: #fff;
    color: #374151;
    border-radius: 8px;
    padding: 6px 10px;
    font-size: 12px;
    font-weight: 600;
    cursor: pointer;
    white-space: nowrap;
}
.chatbot-ticket-reopen:hover {
    background: #f9fafb;
    border-color: var(--chatbot-primary);
    color: var(--chatbot-primary);
}
#chatbot-home.is-empty #chatbot-recent-card {
    flex: 1;
    display: flex;
    flex-direction: column;
    align-items: stretch;
    justify-content: center;
    min-height: 220px;
    text-align: center;
    padding: 28px 20px;
}
#chatbot-recent-heading {
    font-size: 15px;
    font-weight: 700;
    color: #1a1a1a;
    margin: 0 4px 10px;
}
#chatbot-home.is-empty #chatbot-recent-heading {
    margin: 0 0 8px;
    font-size: 17px;
}
.chatbot-recent-empty {
    display: none;
    margin: 0 0 18px;
    font-size: 13px;
    line-height: 1.45;
    color: #667085;
}
#chatbot-home.is-empty .chatbot-recent-empty { display: block; }
#chatbot-recent-list { display: block; }
.chatbot-recent-item {
    position: relative;
    width: 100%;
    text-align: left;
    background: none;
    border: none;
    border-top: 1px solid #eef0f3;
    padding: 12px 28px 12px 4px;
    cursor: pointer;
    font: inherit;
}
.chatbot-recent-item:first-child { border-top: none; }
.chatbot-recent-item:hover { background: #f7f9fc; border-radius: 8px; }
.chatbot-recent-meta {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
    align-items: baseline;
    margin-bottom: 4px;
}
.chatbot-recent-name {
    font-size: 13px;
    font-weight: 600;
    color: #555;
}
.chatbot-recent-time {
    font-size: 12px;
    color: #888;
}
.chatbot-recent-preview {
    font-size: 13px;
    color: #333;
    line-height: 1.35;
    padding-right: 8px;
}
.chatbot-recent-chevron {
    position: absolute;
    right: 8px;
    top: 50%;
    transform: translateY(-50%);
    color: #aaa;
    font-size: 22px;
    line-height: 1;
}
.chatbot-start-cta {
    display: none;
    width: 100%;
    margin-top: 4px;
    background: linear-gradient(135deg, var(--chatbot-primary) 0%, var(--chatbot-secondary) 100%);
    color: #fff;
    border: none;
    border-radius: 999px;
    padding: 12px 16px;
    font-size: 14px;
    font-weight: 600;
    cursor: pointer;
}
#chatbot-home.is-empty .chatbot-start-cta {
    margin-top: 0;
    max-width: 260px;
    align-self: center;
}
.chatbot-start-cta:hover { background: linear-gradient(135deg, #059669 0%, #0891b2 100%); }

#chatbot-chat {
    display: none;
    flex: 1;
    flex-direction: column;
    min-height: 0;
    overflow: hidden;
}
#chatbot-chat.active { display: flex; }
#chatbot-messages {
    flex: 1; overflow-y: auto; padding: 14px;
    display: flex; flex-direction: column; gap: 8px;
    min-height: 0;
}
.chatbot-msg {
    max-width: 92%; padding: 10px 14px;
    border-radius: 16px; line-height: 1.45; font-size: 14px;
    word-wrap: break-word;
}
.chatbot-msg.bot {
    background: #f0f2f5; color: #1a1a1a; align-self: flex-start;
    border-bottom-left-radius: 4px;
}
.chatbot-msg.user {
    background: linear-gradient(135deg, var(--chatbot-primary) 0%, var(--chatbot-secondary) 100%);
    color: #fff; align-self: end;
    border-bottom-right-radius: 4px;
    white-space: pre-wrap;
}
.chatbot-msg.admin {
    background: #ecfdf5; color: #111827; align-self: flex-start;
    border-bottom-left-radius: 4px;
    border: 1px solid #6ee7b7;
}
.chatbot-status-notice {
    margin: 10px 14px 0;
    padding: 10px 12px;
    border-radius: 10px;
    background: #fff4e5;
    border: 1px solid #f0d2a6;
    color: #7a4b00;
    font-size: 13px;
    line-height: 1.4;
}
.chatbot-status-notice.is-live {
    background: #ecfdf5;
    border-color: #6ee7b7;
    color: #065f46;
}
.chatbot-msg.bot p { margin: 0 0 0.55em; }
.chatbot-msg.admin p { margin: 0 0 0.55em; }
.chatbot-msg.bot p:last-child { margin-bottom: 0; }
.chatbot-msg.bot a {
    color: #1a5fb4;
    text-decoration: underline;
    font-weight: 600;
}
.chatbot-msg.bot a:hover { color: #0f3f7a; }
.chatbot-product-num { font-weight: 700; color: #333; }
.chatbot-gap { margin-top: 0.75em; }
.chatbot-image-link { display: inline-block; margin: 0.45em 0 0.15em; }
.chatbot-product-image {
    display: block;
    width: 100%;
    max-width: 160px;
    height: auto;
    border-radius: 8px;
    border: 1px solid #e4e7ec;
    background: #fff;
}
.chatbot-msg.typing { background: #f0f2f5; color: #999; font-style: italic; }
.chatbot-msg-row {
    display: flex;
    align-items: flex-end;
    gap: 8px;
    align-self: flex-start;
    max-width: 96%;
}
.chatbot-msg-row .chatbot-msg {
    flex: 1;
    min-width: 0;
    max-width: none;
}
.chatbot-avatar {
    width: 28px;
    height: 28px;
    border-radius: 50%;
    object-fit: cover;
    flex-shrink: 0;
    background: #fff;
    box-shadow: 0 1px 3px rgba(17,24,39,0.18);
}
.chatbot-contact-panel {
    border-top: 1px solid #e6e8ec;
    background: #f5f8fc;
    padding: 12px 14px;
    flex-shrink: 0;
}
.chatbot-ticket-confirm {
    border-top: 1px solid #e6e8ec;
    background: #f5f8fc;
    padding: 12px 14px;
    flex-shrink: 0;
}
.chatbot-ticket-confirm-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
}
.chatbot-ticket-btn {
    border: 1px solid #ccd5e0;
    background: #fff;
    color: #334;
    border-radius: 8px;
    padding: 8px 12px;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
}
.chatbot-ticket-btn:hover { background: #eef2f7; }
.chatbot-ticket-btn-primary {
    border: none;
    background: var(--chatbot-primary);
    color: #fff;
}
.chatbot-ticket-btn-primary:hover { background: var(--chatbot-secondary); }
.chatbot-ticket-btn:disabled { opacity: 0.6; cursor: default; }
.chatbot-contact-help {
    margin: 0 0 8px;
    font-size: 13px;
    line-height: 1.4;
    color: #445;
}
#chatbot-contact-email-step:not([hidden]),
#chatbot-contact-code-step:not([hidden]) {
    display: flex;
    gap: 8px;
    align-items: center;
}
#chatbot-contact-email,
#chatbot-contact-code {
    flex: 1;
    border: 1px solid #ccd5e0;
    border-radius: 8px;
    padding: 8px 10px;
    font-size: 13px;
    outline: none;
}
#chatbot-contact-email:focus,
#chatbot-contact-code:focus { border-color: var(--chatbot-primary); }
#chatbot-contact-send,
#chatbot-contact-verify {
    border: none;
    background: var(--chatbot-primary);
    color: #fff;
    border-radius: 8px;
    padding: 8px 12px;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    white-space: nowrap;
}
#chatbot-contact-send:hover,
#chatbot-contact-verify:hover { background: #059669; }
#chatbot-contact-send:disabled,
#chatbot-contact-verify:disabled { opacity: 0.6; cursor: default; }
.chatbot-contact-status {
    margin: 8px 0 0;
    font-size: 12px;
    color: #2f6b3a;
}
.chatbot-contact-status.is-error { color: #a33; }
#chatbot-input-area {
    display: flex; border-top: 1px solid #e0e0e0; padding: 10px;
    background: #fafafa;
    flex-shrink: 0;
}
#chatbot-input {
    flex: 1; border: 1px solid #ddd; border-radius: 20px;
    padding: 8px 14px; font-size: 14px; outline: none;
}
#chatbot-input:focus { border-color: var(--chatbot-primary); }
#chatbot-send {
    background: none; border: none; color: var(--chatbot-primary);
    cursor: pointer; padding: 0 10px; display: flex; align-items: center;
}
#chatbot-send:hover { color: var(--chatbot-secondary); }

#chatbot-bottom-nav {
    display: flex;
    border-top: 1px solid #e6e8ec;
    background: #fff;
    flex-shrink: 0;
}
.chatbot-nav-btn {
    flex: 1;
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 2px;
    padding: 8px 4px 10px;
    background: none;
    border: none;
    color: #777;
    cursor: pointer;
    font-size: 11px;
    font-weight: 600;
}
.chatbot-nav-btn.active { color: var(--chatbot-primary); }
.chatbot-nav-btn:hover { color: var(--chatbot-primary); }

@media (max-width: 420px) {
    #chatbot-panel { width: calc(100vw - 32px); right: -4px; height: min(520px, calc(100vh - 100px)); }
}
</style>
<?php
    }


    private static function sanitizeHexColor(string $value, string $fallback): string
    {
        $value = trim($value);
        if (preg_match('/^#([A-Fa-f0-9]{6})$/', $value)) {
            return strtoupper($value);
        }
        if (preg_match('/^#([A-Fa-f0-9]{3})$/', $value, $m)) {
            $h = $m[1];
            return strtoupper('#' . $h[0] . $h[0] . $h[1] . $h[1] . $h[2] . $h[2]);
        }
        return $fallback;
    }

    /**
     * Resolve SEEKMODO_AI_CHATBOT_WIDGET_ICON (catalog-relative path or
     * absolute URL) into a storefront-safe URL. Empty when unset or the
     * relative file no longer exists.
     */
    private static function widgetIconUrl(): string
    {
        $icon = defined('SEEKMODO_AI_CHATBOT_WIDGET_ICON')
            ? trim((string) SEEKMODO_AI_CHATBOT_WIDGET_ICON)
            : '';
        if ($icon === '') {
            return '';
        }
        if (preg_match('#^https?://#i', $icon)) {
            return $icon;
        }
        $rel = ltrim(str_replace('\\', '/', $icon), '/');
        if ($rel === '' || strpos($rel, '..') !== false || !defined('DIR_FS_CATALOG')) {
            return '';
        }
        $fsPath = rtrim((string) DIR_FS_CATALOG, '/\\') . '/' . $rel;
        if (!is_file($fsPath)) {
            return '';
        }
        $server = defined('HTTPS_SERVER') ? HTTPS_SERVER : (defined('HTTP_SERVER') ? HTTP_SERVER : '');
        $catalog = defined('DIR_WS_CATALOG') ? DIR_WS_CATALOG : '/';
        return rtrim($server, '/') . rtrim($catalog, '/') . '/' . $rel;
    }

    private function chatApiUrl(): string
    {
        $server = defined('HTTPS_SERVER') ? HTTPS_SERVER : (defined('HTTP_SERVER') ? HTTP_SERVER : '');
        $catalog = defined('DIR_WS_CATALOG') ? DIR_WS_CATALOG : '/';
        return rtrim($server, '/') . rtrim($catalog, '/') . '/seekmodo_ai_chat.php';
    }
}
