/**
 * Seekmodo AI Chatbot — admin live console (progressive enhancement).
 *
 * Vanilla JS, no build step, no jQuery. This script is a *no-op* until the
 * page that includes it defines `window.SeekmodoChatConsoleConfig` (below)
 * AND provides at least some of the DOM hooks described in "DOM CONTRACT".
 * Every feature independently checks for its own DOM hooks before wiring up,
 * so this file is safe to include on any admin page — it will simply skip
 * whatever markup isn't present (e.g. the classic table-based Chat Inbox
 * list in seekmodo_ai_chatbot_chats.php, which keeps its own inline script
 * and is untouched by this file).
 *
 * -------------------------------------------------------------------------
 * CONFIG (set by PHP before this script tag, as a plain <script> block):
 *
 *   window.SeekmodoChatConsoleConfig = {
 *     endpoints: {
 *       list:         'https://.../seekmodo_ai_chatbot_console.php?ajax=list',
 *       conversation: 'https://.../seekmodo_ai_chatbot_console.php?ajax=conversation',
 *       poll:         'https://.../seekmodo_ai_chatbot_chats.php?ajax=inbox_poll',
 *       post:         'https://.../seekmodo_ai_chatbot_console.php'
 *     },
 *     securityToken: '...',
 *     selectedChatId: 123 /* or null *\/,
 *     filters: { q, status, awaiting, archived, guest, date_from, date_to, sort, dir, page },
 *     i18n: { ... see I18N DEFAULTS below for every key this file understands ... },
 *     pollMs: 3000
 *   };
 *
 * Every i18n key is optional — English fallbacks are baked in, so the page
 * can supply only the strings it wants localized/overridden.
 *
 * -------------------------------------------------------------------------
 * RESPONSE SHAPES this file consumes (already defined by the backend):
 *
 *   ajax=list          -> { ok, total, page, pages, per_page, awaiting_count,
 *                            rows: [{ chat_id, customer_name, customer_email,
 *                              customers_id, is_guest, preview, message_count,
 *                              status, awaiting_human, present, date_updated,
 *                              date_created, archived_at, assigned_admin_id,
 *                              initials }] }
 *
 *   ajax=conversation   -> { ok, chat: { chat_id, session_id, customer_name,
 *                              customer_email, customers_id, is_guest,
 *                              email_verified, status, message_count,
 *                              date_created, date_updated, assigned_admin_id,
 *                              archived_at, awaiting_human, present,
 *                              can_email_reply, account_url,
 *                              messages: [{ role, content, date, admin_name,
 *                                seq, channel }],
 *                              actions: { can_claim, can_live_reply,
 *                                can_email_reply, can_resume_ai, can_resolve,
 *                                can_reopen, can_archive, can_unarchive,
 *                                can_delete } }, flash?: [{type, text}] }
 *
 *   inbox_poll (ajax=inbox_poll) -> { ok, awaiting_count, awaiting_ids,
 *                              open_count, items, list_url, ts }
 *
 *   POST (ajax=1 / Accept: application/json) -> { ok, messages:
 *                              [{type, text}], chat_id?, deleted?, archived? }
 *
 * -------------------------------------------------------------------------
 * DOM CONTRACT — every hook is optional; a feature only activates once its
 * root element exists. IDs are the primary hook; a couple of features also
 * accept a `data-*` attribute alternative noted inline.
 *
 *   Conversation list (left pane)
 *     #ogc-console-list-body      scroll container; JS renders
 *                                  `.ogc-conv-item[data-chat-id]` rows into it
 *                                  and sets role="listbox" / role="option".
 *     #ogc-console-list-count     optional text node updated with "N chats"
 *     #ogc-console-filter-form    optional <form>; if present its submit is
 *                                  intercepted to re-fetch the list via fetch
 *                                  instead of a full page navigation.
 *
 *   Right pane (transcript + composer)
 *     #ogc-console-empty          shown when no chat is selected
 *     #ogc-console-chat           wrapper shown once a chat is loaded
 *     #ogc-console-header-info    JS renders avatar + name + meta pills here
 *     #ogc-console-context-bar    JS renders claim/resolve/reopen/etc buttons
 *     #ogc-console-transcript     scroll container; JS renders `.ogc-msg-row`
 *                                  items into it and auto-scrolls to bottom
 *
 *   Composer dock
 *     #ogc-console-composer       root; if missing, composer is skipped
 *       .ogc-composer-segmented   segmented control root (tablist)
 *         [data-composer-mode="live|email|note"]   one button per mode
 *       .ogc-composer-panel[data-composer-panel="live|email|note"]
 *         [data-role="composer-textarea"]           the reply/note textarea
 *         [data-role="composer-submit"]             the send/save button
 *         (optional) may be wrapped in a real <form> with hidden
 *         securityToken/action/chat_id inputs for the true no-JS fallback —
 *         when present, that form is submitted classically if fetch fails.
 *
 *   Awaiting banner (shared with the classic list page's own markup/inline
 *   script — reused here so both pages behave the same way)
 *     #ogc-awaiting-banner        container, toggled hidden / .is-active
 *     #ogc-awaiting-banner-text   text node updated with the current count
 *
 * None of these IDs are invented backend contracts — they are purely
 * front-end DOM hooks for this progressive-enhancement layer.
 */
(function () {
  'use strict';

  var cfg = window.SeekmodoChatConsoleConfig;
  if (!cfg || typeof cfg !== 'object' || !cfg.endpoints) {
    return; // Nothing to enhance on this page.
  }

  /* ------------------------------------------------------------------ *
   * I18N DEFAULTS — every key cfg.i18n may override.
   * ------------------------------------------------------------------ */
  var I18N = Object.assign({
    guest: 'Guest',
    noEmail: 'No email on file',
    verified: 'Verified',
    unverified: 'Unverified',
    accountLink: 'Customer account',
    presenceOnline: 'Online in widget',
    presenceOffline: 'Offline — use email',
    awaitingPill: 'Awaiting reply',
    needsClaimPill: 'Needs claim',
    statusLabels: {
      open: 'Open',
      live: 'Live',
      handed_off: 'Handed off',
      resolved: 'Resolved'
    },
    roleLabels: {
      user: 'Shopper',
      assistant: 'Assistant',
      admin: 'Support team',
      agent_note: 'Internal note'
    },
    channelLabels: {
      email: 'email',
      live: 'live'
    },
    emptyListTitle: 'No chats match your filters',
    emptyListBody: 'Storefront chats appear here automatically.',
    emptyChatTitle: 'Select a chat',
    emptyChatBody: 'Choose a conversation from the list to view the transcript and reply.',
    loadError: 'Could not load this chat. Please try again.',
    composerLive: 'Live',
    composerEmail: 'Email',
    composerNote: 'Note',
    composerLivePlaceholder: 'Write a live reply…',
    composerEmailPlaceholder: 'Write an email reply…',
    composerNotePlaceholder: 'Add an internal note…',
    sendLiveReply: 'Send live reply',
    sendEmailReply: 'Send email reply',
    saveNote: 'Save note',
    liveDisabledHint: 'Live reply is unavailable for this chat.',
    emailDisabledHint: 'Email reply needs a verified customer email — use Live reply instead.',
    claim: 'Claim live',
    resumeAi: 'Resume AI',
    resolve: 'Resolve',
    reopen: 'Reopen',
    archive: 'Archive',
    unarchive: 'Unarchive',
    delete: 'Delete',
    deleteConfirm: 'Delete this chat permanently?',
    awaitingBannerOne: '1 chat needs a human — claim live or reply now.',
    awaitingBannerMany: '%d chats need a human — claim live or reply now.',
    postFailedFallback: 'Could not reach the server. Submitting the classic form instead…',
    postFailedNoFallback: 'Could not reach the server. Please try again.',
    pagerPrev: '‹ Prev',
    pagerNext: 'Next ›',
    pagerPage: 'Page %d / %d',
    resultsLabel: 'chats'
  }, cfg.i18n || {});
  if (cfg.i18n && cfg.i18n.statusLabels) I18N.statusLabels = Object.assign({}, I18N.statusLabels, cfg.i18n.statusLabels);
  if (cfg.i18n && cfg.i18n.roleLabels) I18N.roleLabels = Object.assign({}, I18N.roleLabels, cfg.i18n.roleLabels);
  if (cfg.i18n && cfg.i18n.channelLabels) I18N.channelLabels = Object.assign({}, I18N.channelLabels, cfg.i18n.channelLabels);

  var POLL_MS = parseInt(cfg.pollMs, 10) || 3000;
  var PRESENCE_MS = 60000;

  /* ------------------------------------------------------------------ *
   * Small DOM / string utilities
   * ------------------------------------------------------------------ */
  function qs(id) { return document.getElementById(id); }
  function qsa(root, sel) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }

  function escapeHtml(value) {
    var s = value === null || value === undefined ? '' : String(value);
    var div = document.createElement('div');
    div.textContent = s;
    return div.innerHTML;
  }

  function safeMarkdownUrl(url) {
    var trimmed = String(url || '').trim();
    if (!/^https?:\/\//i.test(trimmed)) return '';
    try {
      var parsed = new URL(trimmed);
      parsed.pathname = parsed.pathname.split('/').map(function (seg) {
        return encodeURIComponent(decodeURIComponent(seg));
      }).join('/');
      return parsed.toString().replace(/"/g, '%22');
    } catch (e) {
      return trimmed.replace(/#/g, '%23').replace(/"/g, '%22');
    }
  }

  /** Match storefront widget formatting: bold, links, product images. */
  function formatMessageMarkdown(text) {
    var html = escapeHtml(text);
    html = html.replace(/!\[([^\]]*)\]\((https?:\/\/[^)\s]+)\)/g, function (_, alt, url) {
      var href = safeMarkdownUrl(url);
      if (!href) return _;
      return '<a class="ogc-msg-image-link" href="' + href + '" target="_blank" rel="noopener noreferrer">'
        + '<img class="ogc-msg-product-image" src="' + href + '" alt="' + alt + '" loading="lazy" '
        + 'onerror="this.onerror=null;var a=this.closest(\'a\');if(a){a.remove();}else{this.remove();}">'
        + '</a>';
    });
    html = html.replace(/\[([^\]]+)\]\((https?:\/\/[^)\s]+)\)/g, function (_, label, url) {
      var href = safeMarkdownUrl(url);
      if (!href) return _;
      return '<a href="' + href + '" target="_blank" rel="noopener noreferrer">' + label + '</a>';
    });
    html = html.replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>');
    html = html.replace(/\n{2,}/g, '</p><p class="ogc-msg-gap">');
    html = html.replace(/\n/g, '<br>');
    return '<p class="ogc-msg-para">' + html + '</p>';
  }

  function initialsFor(name) {
    var s = (name || '').trim();
    if (s === '') return '?';
    var parts = s.split(/\s+/).filter(Boolean);
    if (parts.length === 1) return parts[0].slice(0, 2).toUpperCase();
    return (parts[0][0] + parts[parts.length - 1][0]).toUpperCase();
  }

  function formatDate(raw) {
    var s = (raw || '').toString();
    if (s.length >= 16) return s.slice(0, 16);
    return s;
  }

  function customerLabel(row) {
    var name = (row.customer_name || '').trim();
    if (name !== '') return name;
    var cid = parseInt(row.customers_id, 10) || 0;
    if (cid > 0) return '#' + cid;
    return I18N.guest;
  }

  function sprintf1(template, n) {
    return String(template).replace('%d', String(n));
  }

  function sprintf2(template, a, b) {
    var i = 0;
    var args = [a, b];
    return String(template).replace(/%d/g, function () {
      var v = args[i++];
      return v === undefined || v === null ? '' : String(v);
    });
  }

  function debounce(fn, wait) {
    var t = null;
    return function () {
      var args = arguments;
      var ctx = this;
      clearTimeout(t);
      t = setTimeout(function () { fn.apply(ctx, args); }, wait);
    };
  }

  /* ------------------------------------------------------------------ *
   * HTTP helpers
   * ------------------------------------------------------------------ */
  function buildUrl(base, params) {
    var url;
    try {
      url = new URL(base, window.location.href);
    } catch (e) {
      return base;
    }
    Object.keys(params || {}).forEach(function (key) {
      var val = params[key];
      if (val === null || val === undefined || val === '') return;
      url.searchParams.set(key, val);
    });
    return url.toString();
  }

  function getJSON(url) {
    return fetch(url, {
      method: 'GET',
      credentials: 'same-origin',
      headers: { Accept: 'application/json' }
    }).then(function (r) {
      if (!r.ok) throw new Error('HTTP ' + r.status);
      return r.json();
    });
  }

  function postJSON(url, formData) {
    return fetch(url, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { Accept: 'application/json' },
      body: formData
    }).then(function (r) {
      if (!r.ok) throw new Error('HTTP ' + r.status);
      return r.json();
    });
  }

  /* ------------------------------------------------------------------ *
   * State
   * ------------------------------------------------------------------ */
  var state = {
    filters: Object.assign({ q: '', status: '', awaiting: '', assigned_admin_id: '', archived: '', guest: '', date_from: '', date_to: '', sort: 'updated', dir: 'desc', page: 1 }, cfg.filters || {}),
    selectedChatId: cfg.selectedChatId ? parseInt(cfg.selectedChatId, 10) : null,
    lastAwaitingSignature: '',
    conversation: null,
    composerMode: 'live'
  };

  /* ------------------------------------------------------------------ *
   * Awaiting banner + title blink (shared pattern with the classic list
   * page's inline script; safe no-op if the banner markup is absent).
   * ------------------------------------------------------------------ */
  var baseTitle = document.title;
  var blinkTimer = null;

  function setTitleAlert(n) {
    if (blinkTimer) { clearInterval(blinkTimer); blinkTimer = null; }
    if (n < 1) { document.title = baseTitle; return; }
    var on = true;
    blinkTimer = setInterval(function () {
      document.title = on ? ('(' + n + ') ' + baseTitle) : baseTitle;
      on = !on;
    }, 1200);
  }

  function updateAwaitingBanner(count) {
    var n = parseInt(count, 10) || 0;
    setTitleAlert(n);
    var banner = qs('ogc-awaiting-banner');
    var text = qs('ogc-awaiting-banner-text');
    if (!banner) return;
    if (n < 1) {
      banner.hidden = true;
      banner.setAttribute('hidden', '');
      banner.classList.remove('is-active');
      if (text) text.textContent = '';
      return;
    }
    banner.hidden = false;
    banner.removeAttribute('hidden');
    banner.classList.add('is-active');
    if (text) text.textContent = n === 1 ? I18N.awaitingBannerOne : sprintf1(I18N.awaitingBannerMany, n);
    var link = banner.querySelector('a.nmx-btn');
    if (link && cfg.awaitingListUrl) {
      link.setAttribute('href', cfg.awaitingListUrl);
    }
  }

  /* ------------------------------------------------------------------ *
   * Flash messages (from POST responses / conversation.flash)
   * ------------------------------------------------------------------ */
  function renderFlash(messages) {
    if (!messages || !messages.length) return;
    var host = qs('ogc-console-flash') || document.querySelector('.nmx-module');
    if (!host) return;
    messages.forEach(function (m) {
      var div = document.createElement('div');
      var type = (m && m.type) || 'info';
      div.className = 'nmx-alert nmx-alert-' + escapeHtml(type);
      div.textContent = (m && m.text) || '';
      host.insertBefore(div, host.firstChild);
      setTimeout(function () {
        if (div.parentNode) div.parentNode.removeChild(div);
      }, 6000);
    });
  }

  /* ================================================================== *
   * CONVERSATION LIST (left pane)
   * ================================================================== */
  var listBody = qs('ogc-console-list-body');
  var listCount = qs('ogc-console-list-count');
  var filterForm = qs('ogc-console-filter-form');

  function renderConvItem(row) {
    var item = document.createElement('div');
    item.className = 'ogc-conv-item';
    item.setAttribute('role', 'option');
    item.setAttribute('tabindex', '-1');
    item.dataset.chatId = String(row.chat_id);

    var awaiting = !!row.awaiting_human;
    var unclaimed = row.status === 'live' && !parseInt(row.assigned_admin_id, 10);
    var assigneeLabel = row.assigned_admin_name
      || row.assigned_seekmodo_name
      || '';
    if (!assigneeLabel && parseInt(row.assigned_admin_id, 10)) {
      assigneeLabel = 'Admin #' + row.assigned_admin_id;
    }
    if (awaiting) item.classList.add('is-awaiting');
    if (parseInt(row.chat_id, 10) === state.selectedChatId) {
      item.classList.add('is-selected');
      item.setAttribute('aria-selected', 'true');
    } else {
      item.setAttribute('aria-selected', 'false');
    }

    var name = customerLabel(row);
    var initials = row.initials || initialsFor(name);
    var statusLabel = I18N.statusLabels[row.status] || row.status;
    var presenceClass = row.present ? 'is-online' : '';

    item.innerHTML =
      '<span class="nmx-avatar nmx-avatar-sm" aria-hidden="true">' + escapeHtml(initials) + '</span>' +
      '<span class="ogc-conv-body">' +
        '<span class="ogc-conv-top">' +
          '<span class="ogc-conv-name">' + escapeHtml(name) + '</span>' +
          '<span class="ogc-conv-time">' + escapeHtml(formatDate(row.date_updated)) + '</span>' +
        '</span>' +
        '<span class="ogc-conv-preview">' + escapeHtml(row.preview || '') + '</span>' +
        '<span class="ogc-conv-footer">' +
          '<span class="nmx-pill ogc-status-' + escapeHtml(row.status || 'open') + '">' + escapeHtml(statusLabel) + '</span>' +
          (awaiting ? '<span class="nmx-pill ogc-awaiting">' + escapeHtml(unclaimed ? I18N.needsClaimPill : I18N.awaitingPill) + '</span>' : '') +
          (assigneeLabel ? '<span class="nmx-pill">' + escapeHtml(assigneeLabel) + '</span>' : '') +
          '<span class="ogc-presence-dot ' + presenceClass + '" title="' + escapeHtml(row.present ? I18N.presenceOnline : I18N.presenceOffline) + '"></span>' +
        '</span>' +
      '</span>';

    item.addEventListener('keydown', function (ev) {
      if (ev.key === 'Enter' || ev.key === ' ') {
        ev.preventDefault();
        selectConversation(row.chat_id, true);
      }
    });
    return item;
  }

  function renderSkeletonRows(count) {
    if (!listBody) return;
    listBody.innerHTML = '';
    for (var i = 0; i < count; i++) {
      var row = document.createElement('div');
      row.className = 'ogc-conv-item is-skeleton';
      row.innerHTML =
        '<span class="nmx-skeleton nmx-skeleton-avatar"></span>' +
        '<span class="ogc-conv-body" style="width:100%">' +
          '<span class="nmx-skeleton nmx-skeleton-line" style="width:60%"></span>' +
          '<span class="nmx-skeleton nmx-skeleton-line is-short"></span>' +
        '</span>';
      listBody.appendChild(row);
    }
  }

  function renderPager(data) {
    var pager = qs('ogc-console-pager');
    if (!pager) return;
    var page = parseInt((data && data.page) || state.filters.page || 1, 10) || 1;
    var pages = parseInt((data && data.pages) || 1, 10) || 1;
    state.filters.page = page;
    pager.dataset.page = String(page);
    pager.dataset.pages = String(pages);
    var html = '';
    if (page > 1) {
      html += '<button type="button" class="nmx-btn nmx-btn-default nmx-btn-sm" data-ogc-page="' + (page - 1) + '">' + escapeHtml(I18N.pagerPrev) + '</button>';
    }
    html += '<span id="ogc-console-pager-label">' + escapeHtml(sprintf2(I18N.pagerPage, page, pages)) + '</span>';
    if (page < pages) {
      html += '<button type="button" class="nmx-btn nmx-btn-default nmx-btn-sm" data-ogc-page="' + (page + 1) + '">' + escapeHtml(I18N.pagerNext) + '</button>';
    }
    pager.innerHTML = html;
  }

  function renderList(data) {
    if (!listBody) return;
    listBody.innerHTML = '';
    var rows = (data && data.rows) || [];
    if (rows.length === 0) {
      var empty = document.createElement('div');
      empty.className = 'nmx-empty-state';
      empty.innerHTML =
        '<div class="nmx-empty-state-icon">&#128172;</div>' +
        '<div class="nmx-empty-state-title">' + escapeHtml(I18N.emptyListTitle) + '</div>' +
        '<div class="nmx-empty-state-body">' + escapeHtml(I18N.emptyListBody) + '</div>';
      listBody.appendChild(empty);
    } else {
      rows.forEach(function (row) { listBody.appendChild(renderConvItem(row)); });
    }
    if (listCount) {
      var total = (data && data.total != null) ? data.total : rows.length;
      listCount.textContent = String(total) + ' ' + I18N.resultsLabel;
    }
    renderPager(data || {});
    if (data && typeof data.awaiting_count !== 'undefined') updateAwaitingBanner(data.awaiting_count);
  }

  function currentListParams() {
    return Object.assign({ ajax: 'list' }, state.filters);
  }

  function fetchList(showSkeleton) {
    if (!listBody) return Promise.resolve();
    if (showSkeleton) renderSkeletonRows(6);
    listBody.setAttribute('role', 'listbox');
    listBody.setAttribute('aria-label', 'Conversations');
    var url = buildUrl(cfg.endpoints.list, currentListParams());
    return getJSON(url).then(function (data) {
      if (data && data.ok) renderList(data);
    }).catch(function () {
      /* Keep whatever was last rendered; polling will retry. */
    });
  }

  if (filterForm) {
    filterForm.addEventListener('submit', function (ev) {
      ev.preventDefault();
      var formData = new FormData(filterForm);
      formData.forEach(function (value, key) { state.filters[key] = value; });
      state.filters.page = 1;
      fetchList(true);
    });
  }

  if (listBody) {
    // Delegate clicks so SSR <a class="ogc-conv-item"> rows work before/without
    // waiting for Ajax list replacement, and survive re-renders.
    listBody.addEventListener('click', function (ev) {
      var item = ev.target && ev.target.closest ? ev.target.closest('.ogc-conv-item[data-chat-id]') : null;
      if (!item || !listBody.contains(item)) return;
      if (item.classList.contains('is-skeleton')) return;
      ev.preventDefault();
      selectConversation(item.getAttribute('data-chat-id'), true);
    });
    listBody.addEventListener('keydown', function (ev) {
      if (ev.key !== 'ArrowDown' && ev.key !== 'ArrowUp') return;
      var items = qsa(listBody, '.ogc-conv-item[data-chat-id]');
      if (!items.length) return;
      var idx = items.findIndex(function (el) { return el === document.activeElement; });
      var next;
      if (ev.key === 'ArrowDown') next = items[Math.min(items.length - 1, idx + 1)];
      else next = items[Math.max(0, idx - 1)];
      if (next) { ev.preventDefault(); next.focus(); }
    });
  }

  /* ================================================================== *
   * RIGHT PANE — header, transcript, context bar, composer
   * ================================================================== */
  var emptyPane = qs('ogc-console-empty');
  var chatPane = qs('ogc-console-chat');
  var headerInfo = qs('ogc-console-header-info');
  var contextBar = qs('ogc-console-context-bar');
  var transcriptEl = qs('ogc-console-transcript');
  var composerRoot = qs('ogc-console-composer');
  var stickToBottom = true;
  var prevSelectedForScroll = null;
  function transcriptNearBottom() {
    if (!transcriptEl) return true;
    return transcriptEl.scrollHeight - transcriptEl.scrollTop - transcriptEl.clientHeight <= 96;
  }
  if (transcriptEl) {
    transcriptEl.addEventListener('scroll', function () {
      stickToBottom = transcriptNearBottom();
    }, { passive: true });
  }

  function showEmptyPane() {
    if (emptyPane) {
      emptyPane.hidden = false;
      emptyPane.setAttribute('aria-hidden', 'false');
      emptyPane.innerHTML =
        '<div class="nmx-empty-state">' +
          '<div class="nmx-empty-state-icon">&#128172;</div>' +
          '<div class="nmx-empty-state-title">' + escapeHtml(I18N.emptyChatTitle) + '</div>' +
          '<div class="nmx-empty-state-body">' + escapeHtml(I18N.emptyChatBody) + '</div>' +
        '</div>';
    }
    if (chatPane) {
      chatPane.hidden = true;
      chatPane.setAttribute('aria-hidden', 'true');
    }
  }

  function showChatPane() {
    if (emptyPane) {
      emptyPane.hidden = true;
      emptyPane.setAttribute('aria-hidden', 'true');
    }
    if (chatPane) {
      chatPane.hidden = false;
      chatPane.setAttribute('aria-hidden', 'false');
    }
  }

  function renderHeader(chat) {
    if (!headerInfo) return;
    var name = (chat.customer_name || '').trim() || (chat.customers_id ? '#' + chat.customers_id : I18N.guest);
    var initials = initialsFor(name);
    var statusLabel = I18N.statusLabels[chat.status] || chat.status;
    var presenceLabel = chat.present ? I18N.presenceOnline : I18N.presenceOffline;
    var emailHtml = '';
    if (chat.customer_email) {
      emailHtml = '<a href="mailto:' + escapeHtml(chat.customer_email) + '">' + escapeHtml(chat.customer_email) + '</a> ' +
        '<span class="nmx-pill">' + escapeHtml(chat.can_email_reply ? I18N.verified : I18N.unverified) + '</span>';
    } else {
      emailHtml = '<span class="ogc-sub">' + escapeHtml(I18N.noEmail) + '</span>';
    }
    var accountHtml = chat.account_url
      ? ' &middot; <a href="' + escapeHtml(chat.account_url) + '">' + escapeHtml(I18N.accountLink) + '</a>'
      : '';

    headerInfo.innerHTML =
      '<span class="nmx-avatar" aria-hidden="true">' + escapeHtml(initials) + '</span>' +
      '<span class="ogc-console-header-text">' +
        '<span class="ogc-console-header-name">' + escapeHtml(name) +
          (chat.is_guest ? ' <span class="nmx-pill">' + escapeHtml(I18N.guest) + '</span>' : '') +
          '<span class="nmx-pill ogc-status-' + escapeHtml(chat.status || 'open') + '">' + escapeHtml(statusLabel) + '</span>' +
          '<span class="nmx-pill ' + (chat.present ? 'ogc-presence-online' : 'ogc-presence-offline') + '">' + escapeHtml(presenceLabel) + '</span>' +
        '</span>' +
        '<span class="ogc-console-header-sub">' + emailHtml + accountHtml + '</span>' +
      '</span>';
  }

  function actionButton(label, action, variant, confirmMsg) {
    var btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'nmx-btn nmx-btn-sm ' + (variant || 'nmx-btn-default');
    btn.textContent = label;
    btn.addEventListener('click', function () {
      if (confirmMsg && !window.confirm(confirmMsg)) return;
      runChatAction(action, {});
    });
    return btn;
  }

  function renderContextBar(chat) {
    if (!contextBar) return;
    contextBar.innerHTML = '';
    var actions = chat.actions || {};
    if (actions.can_claim) contextBar.appendChild(actionButton(I18N.claim, 'claim_chat', 'nmx-btn-primary'));
    if (actions.can_resume_ai) contextBar.appendChild(actionButton(I18N.resumeAi, 'resume_ai'));
    if (actions.can_resolve) contextBar.appendChild(actionButton(I18N.resolve, 'mark_resolved'));
    if (actions.can_reopen) contextBar.appendChild(actionButton(I18N.reopen, 'reopen_chat'));
    if (actions.can_archive) contextBar.appendChild(actionButton(I18N.archive, 'archive_chat'));
    if (actions.can_unarchive) contextBar.appendChild(actionButton(I18N.unarchive, 'unarchive_chat'));
    // Email follow-up lives in Seekmodo Tickets; hand the transcript over.
    if (cfg.ticketsEnabled) contextBar.appendChild(actionButton(I18N.convertToTicket || 'Convert to ticket', 'convert_to_ticket'));
    if (actions.can_delete) contextBar.appendChild(actionButton(I18N.delete, 'delete', 'nmx-btn-danger', I18N.deleteConfirm));
  }

  function renderMessage(m, chat) {
    var role = ['user', 'assistant', 'admin', 'agent_note'].indexOf(m.role) === -1 ? 'user' : m.role;
    var outgoing = role === 'admin' || role === 'assistant';
    var label = m.author_label || I18N.roleLabels[role] || role;
    if (role === 'user' && chat && chat.shopper_label) {
      label = chat.shopper_label;
    } else if (role === 'user' && chat) {
      label = customerLabel(chat);
    }
    if ((role === 'admin' || role === 'agent_note') && m.admin_name) {
      label = (I18N.roleLabels[role] || role) + ' — ' + m.admin_name;
    }
    if (role === 'admin' && m.channel && I18N.channelLabels[m.channel]) {
      label += ' (' + I18N.channelLabels[m.channel] + ')';
    }

    var row = document.createElement('div');
    row.className = 'ogc-msg-row' + (outgoing ? ' is-outgoing' : '');

    var avatarClass = 'nmx-avatar nmx-avatar-sm';
    if (role === 'assistant') avatarClass += ' nmx-avatar-muted';
    if (role === 'agent_note') avatarClass += ' nmx-avatar-note';

    row.innerHTML =
      '<span class="' + avatarClass + '" aria-hidden="true">' + escapeHtml(initialsFor(label)) + '</span>' +
      '<span class="ogc-msg ogc-msg-' + escapeHtml(role) + '">' +
        '<span class="ogc-msg-head">' +
          '<span class="ogc-msg-role">' + escapeHtml(label) + '</span>' +
          '<span class="ogc-msg-time">' + escapeHtml(formatDate(m.date)) + '</span>' +
        '</span>' +
        '<span class="ogc-msg-body ogc-msg-body-md">' + formatMessageMarkdown(m.content || '') + '</span>' +
      '</span>';
    return row;
  }

  function renderTranscript(chat) {
    if (!transcriptEl) return;
    var chatId = chat && chat.chat_id != null ? parseInt(chat.chat_id, 10) : null;
    var selectedChanged = chatId !== prevSelectedForScroll;
    prevSelectedForScroll = chatId;
    if (selectedChanged) stickToBottom = true;
    var shouldStick = stickToBottom;
    var prevTop = transcriptEl.scrollTop;
    var wrap = document.createElement('div');
    wrap.className = 'ogc-transcript';
    (chat.messages || []).forEach(function (m) { wrap.appendChild(renderMessage(m, chat)); });
    transcriptEl.innerHTML = '';
    transcriptEl.appendChild(wrap);
    requestAnimationFrame(function () {
      if (shouldStick) {
        stickToBottom = true;
        transcriptEl.scrollTop = transcriptEl.scrollHeight;
      } else {
        transcriptEl.scrollTop = prevTop;
      }
    });
  }

  /* ---- Composer (segmented Live / Note) --------------------------- */
  var composerSegmented = composerRoot ? composerRoot.querySelector('.ogc-composer-segmented') : null;
  var composerModeButtons = composerSegmented ? qsa(composerSegmented, '[data-composer-mode]') : [];
  var composerPanels = composerRoot ? qsa(composerRoot, '.ogc-composer-panel[data-composer-panel]') : [];

  var MODE_ACTION = { live: 'live_reply', email: 'admin_reply', note: 'agent_note' };
  var MODE_FIELD = { live: 'reply_text', email: 'reply_text', note: 'note_text' };

  function panelFor(mode) {
    return composerPanels.filter(function (p) { return p.getAttribute('data-composer-panel') === mode; })[0];
  }

  function setComposerMode(mode) {
    state.composerMode = mode;
    composerModeButtons.forEach(function (btn) {
      var isActive = btn.getAttribute('data-composer-mode') === mode;
      btn.classList.toggle('is-active', isActive);
      btn.setAttribute('aria-selected', isActive ? 'true' : 'false');
    });
    composerPanels.forEach(function (panel) {
      var isActive = panel.getAttribute('data-composer-panel') === mode;
      panel.classList.toggle('is-active', isActive);
      panel.hidden = !isActive;
    });
    var panel = panelFor(mode);
    var textarea = panel ? panel.querySelector('[data-role="composer-textarea"]') : null;
    if (textarea) textarea.focus();
  }

  function applyComposerAvailability(chat) {
    if (!composerRoot) return;
    var actions = chat.actions || {};
    composerModeButtons.forEach(function (btn) {
      var mode = btn.getAttribute('data-composer-mode');
      var enabled = mode === 'note' ? true : (mode === 'live' ? !!actions.can_live_reply : !!actions.can_email_reply);
      btn.disabled = !enabled;
      btn.setAttribute('aria-disabled', enabled ? 'false' : 'true');
      var panel = panelFor(mode);
      var textarea = panel ? panel.querySelector('[data-role="composer-textarea"]') : null;
      var submit = panel ? panel.querySelector('[data-role="composer-submit"]') : null;
      if (textarea) textarea.disabled = !enabled;
      if (submit) submit.disabled = !enabled;
      var hint = panel ? panel.querySelector('.ogc-composer-hint') : null;
      if (hint) {
        if (!enabled && mode === 'live') hint.textContent = I18N.liveDisabledHint;
        else if (!enabled && mode === 'email') hint.textContent = I18N.emailDisabledHint;
      }
    });
    // Email replies moved to Seekmodo Tickets; only modes with a rendered button count.
    var rendered = composerModeButtons.map(function (b) { return b.getAttribute('data-composer-mode'); });
    var preferred = ['live', 'note'].filter(function (mode) {
      return rendered.indexOf(mode) !== -1 && (mode === 'note' || actions.can_live_reply);
    });
    setComposerMode(preferred.indexOf(state.composerMode) !== -1 ? state.composerMode : (preferred[0] || 'note'));
  }

  composerModeButtons.forEach(function (btn) {
    btn.setAttribute('role', 'tab');
    btn.addEventListener('click', function () {
      if (btn.disabled) return;
      setComposerMode(btn.getAttribute('data-composer-mode'));
    });
  });
  if (composerSegmented) composerSegmented.setAttribute('role', 'tablist');
  composerPanels.forEach(function (panel) { panel.setAttribute('role', 'tabpanel'); });

  var ASSIGNEES = Array.isArray(cfg.assignees) ? cfg.assignees : [];
  var CURRENT_ADMIN_ID = parseInt(cfg.currentAdminId, 10) || 0;

  function matchAssignee(query) {
    var q = String(query || '').trim().toLowerCase();
    if (q === '' || q === 'me' || q === 'self') {
      return ASSIGNEES.filter(function (a) { return parseInt(a.id, 10) === CURRENT_ADMIN_ID; })[0] || null;
    }
    var exact = ASSIGNEES.filter(function (a) {
      return String(a.name || '').toLowerCase() === q || String(a.email || '').toLowerCase() === q;
    })[0];
    if (exact) return exact;
    var starts = ASSIGNEES.filter(function (a) {
      return String(a.name || '').toLowerCase().indexOf(q) === 0;
    });
    return starts.length === 1 ? starts[0] : null;
  }

  function showCommandsHelp() {
    renderFlash([{
      type: 'info',
      text: 'Commands: /assign <name> — assign chat; /note <text> — internal note; /help — this list.'
    }]);
  }

  function trySlashCommand(mode, text, textarea, formEl) {
    if (text.charAt(0) !== '/') return false;
    var parts = text.slice(1).trim().split(/\s+/);
    var cmd = (parts[0] || '').toLowerCase();
    var rest = text.replace(/^\/\S+\s*/, '').trim();
    if (cmd === 'help' || cmd === 'commands') {
      showCommandsHelp();
      if (textarea) textarea.value = '';
      return true;
    }
    if (cmd === 'note') {
      if (rest === '') {
        renderFlash([{ type: 'warning', text: 'Usage: /note <text>' }]);
        return true;
      }
      runChatAction('agent_note', { note_text: rest }, formEl).then(function (ok) {
        if (ok && textarea) textarea.value = '';
      });
      return true;
    }
    if (cmd === 'assign') {
      var who = matchAssignee(rest);
      if (!who) {
        var names = ASSIGNEES.map(function (a) { return a.name; }).filter(Boolean).slice(0, 12).join(', ');
        renderFlash([{
          type: 'warning',
          text: rest === ''
            ? ('Usage: /assign <name>. Assignees: ' + (names || 'none'))
            : ('No unique match for "' + rest + '". Try: ' + (names || 'full name'))
        }]);
        return true;
      }
      runChatAction('assign_chat', { assigned_admin_id: String(who.id) }, formEl).then(function (ok) {
        if (ok && textarea) textarea.value = '';
      });
      return true;
    }
    return false;
  }

  function wireComposerPanel(panel) {
    var mode = panel.getAttribute('data-composer-panel');
    var textarea = panel.querySelector('[data-role="composer-textarea"]');
    var submit = panel.querySelector('[data-role="composer-submit"]');
    var formEl = panel.querySelector('form');
    if (!textarea || !submit) return;

    function submitPanel() {
      var text = textarea.value.trim();
      if (text === '' || !state.selectedChatId) return;
      if (trySlashCommand(mode, text, textarea, formEl)) return;
      var fields = {};
      fields[MODE_FIELD[mode]] = text;
      submit.disabled = true;
      runChatAction(MODE_ACTION[mode], fields, formEl).then(function (ok) {
        submit.disabled = false;
        if (ok) textarea.value = '';
      });
    }

    textarea.addEventListener('keydown', function (ev) {
      if (ev.key === 'Enter' && !ev.shiftKey) {
        ev.preventDefault();
        submitPanel();
      }
    });
    submit.addEventListener('click', function (ev) {
      ev.preventDefault();
      submitPanel();
    });
    if (formEl) {
      formEl.addEventListener('submit', function (ev) {
        ev.preventDefault();
        submitPanel();
      });
    }
  }
  composerPanels.forEach(wireComposerPanel);

  /* Commands help control near composer */
  if (composerRoot) {
    var helpBtn = document.createElement('button');
    helpBtn.type = 'button';
    helpBtn.className = 'nmx-btn nmx-btn-ghost nmx-btn-sm';
    helpBtn.textContent = 'Commands';
    helpBtn.title = 'Slash commands';
    helpBtn.style.marginLeft = '6px';
    helpBtn.addEventListener('click', showCommandsHelp);
    var segmented = composerRoot.querySelector('.ogc-composer-segmented');
    if (segmented && segmented.parentNode) {
      segmented.parentNode.insertBefore(helpBtn, segmented.nextSibling);
    } else {
      composerRoot.appendChild(helpBtn);
    }
  }

  /* ---- POST actions (context bar buttons + composer submits) ------ */
  function runChatAction(action, extraFields, fallbackForm) {
    if (!state.selectedChatId) return Promise.resolve(false);
    var formData = new FormData();
    formData.append('securityToken', cfg.securityToken || '');
    formData.append('action', action);
    formData.append('chat_id', String(state.selectedChatId));
    formData.append('ajax', '1');
    Object.keys(extraFields || {}).forEach(function (key) { formData.append(key, extraFields[key]); });

    return postJSON(cfg.endpoints.post, formData).then(function (data) {
      if (data && data.redirect) {
        window.location.href = data.redirect;
        return true;
      }
      if (data && data.messages) renderFlash(data.messages);
      if (data && data.ok) {
        if (action === 'delete' || data.deleted) {
          state.selectedChatId = null;
          showEmptyPane();
        } else {
          fetchConversation(state.selectedChatId, false);
        }
        fetchList(false);
      }
      return !!(data && data.ok);
    }).catch(function () {
      if (fallbackForm && typeof fallbackForm.submit === 'function') {
        renderFlash([{ type: 'warning', text: I18N.postFailedFallback }]);
        fallbackForm.submit();
      } else {
        renderFlash([{ type: 'danger', text: I18N.postFailedNoFallback }]);
      }
      return false;
    });
  }

  /* ---- Conversation loading ---------------------------------------- */
  function syncComposerChatIds(chatId) {
    if (!composerRoot) return;
    qsa(composerRoot, 'input[name="chat_id"]').forEach(function (input) {
      input.value = String(chatId || 0);
    });
  }

  function markListSelection(chatId) {
    qsa(document, '.ogc-conv-item[data-chat-id]').forEach(function (el) {
      var isSelected = parseInt(el.dataset.chatId, 10) === chatId;
      el.classList.toggle('is-selected', isSelected);
      el.setAttribute('aria-selected', isSelected ? 'true' : 'false');
    });
  }

  function fetchConversation(chatId, updateHistory) {
    if (!chatId) { showEmptyPane(); return Promise.resolve(); }
    var url = buildUrl(cfg.endpoints.conversation, { ajax: 'conversation', chat_id: chatId });
    return getJSON(url).then(function (data) {
      if (!data || !data.ok || !data.chat) {
        renderFlash([{ type: 'danger', text: I18N.loadError }]);
        state.selectedChatId = null;
        state.conversation = null;
        markListSelection(0);
        showEmptyPane();
        return;
      }
      state.selectedChatId = chatId;
      state.conversation = data.chat;
      showChatPane();
      renderHeader(data.chat);
      renderContextBar(data.chat);
      renderTranscript(data.chat);
      syncComposerChatIds(chatId);
      applyComposerAvailability(data.chat);
      if (data.flash) renderFlash(data.flash);
      if (updateHistory !== false) updateUrlForChat(chatId);
      markListSelection(chatId);
    }).catch(function () {
      renderFlash([{ type: 'danger', text: I18N.loadError }]);
      state.selectedChatId = null;
      state.conversation = null;
      markListSelection(0);
      showEmptyPane();
    });
  }

  function updateUrlForChat(chatId) {
    try {
      var url = new URL(window.location.href);
      url.searchParams.set('action', 'view');
      url.searchParams.set('chat_id', String(chatId));
      window.history.replaceState({}, '', url.toString());
    } catch (e) { /* older browser; ignore */ }
  }

  function selectConversation(chatId, force) {
    var id = parseInt(chatId, 10);
    if (!id) return;
    var paneHidden = chatPane && chatPane.hidden;
    if (!force && id === state.selectedChatId && state.conversation && !paneHidden) return;
    state.selectedChatId = id;
    markListSelection(id);
    fetchConversation(id, true);
  }

  /* ================================================================== *
   * POLLING (awaiting banner + list refresh + live conversation refresh)
   * ================================================================== */
  function poll() {
    if (!cfg.endpoints.poll) return;
    getJSON(cfg.endpoints.poll).then(function (data) {
      if (!data || !data.ok) return;
      updateAwaitingBanner(data.awaiting_count);
      var sig = (data.awaiting_ids || []).join(',');
      var changed = state.lastAwaitingSignature !== '' && sig !== state.lastAwaitingSignature;
      state.lastAwaitingSignature = sig;
      if (changed && listBody) fetchList(false);
      if (state.selectedChatId && state.conversation && state.conversation.status === 'live') {
        fetchConversation(state.selectedChatId, false);
      }
    }).catch(function () { /* retry on next tick */ });
  }

  /* ================================================================== *
   * PRESENCE HEARTBEAT
   * ================================================================== */
  function beatPresence() {
    try {
      fetch(window.location.href.split('#')[0], {
        method: 'GET',
        credentials: 'same-origin',
        headers: { 'X-Seekmodo-Admin-Presence': '1' }
      }).catch(function () { /* ignore */ });
    } catch (e) { /* ignore */ }
  }

  /* ================================================================== *
   * INIT
   * ================================================================== */
  function init() {
    if (listBody) fetchList(true);
    if (state.selectedChatId) {
      fetchConversation(state.selectedChatId, false);
    } else {
      showEmptyPane();
    }
    wirePager();
    wireSplitter();
    // Fix any double-escaped awaiting banner link from SSR.
    if (cfg.awaitingListUrl) {
      var awaitingLink = document.querySelector('#ogc-awaiting-banner a.nmx-btn');
      if (awaitingLink) awaitingLink.setAttribute('href', cfg.awaitingListUrl);
    }
    beatPresence();
    setInterval(beatPresence, PRESENCE_MS);
    setInterval(poll, POLL_MS);
    poll();
  }

  function wirePager() {
    var pager = qs('ogc-console-pager');
    if (!pager) return;
    pager.addEventListener('click', function (ev) {
      var btn = ev.target.closest('[data-ogc-page]');
      if (!btn) return;
      ev.preventDefault();
      var page = parseInt(btn.getAttribute('data-ogc-page'), 10);
      if (!page || page < 1) return;
      state.filters.page = page;
      fetchList(true);
    });
  }

  function wireSplitter() {
    var splitter = qs('ogc-console-splitter');
    var consoleEl = qs('ogc-console');
    if (!splitter || !consoleEl) return;
    var dragging = false;
    var startX = 0;
    var startWidth = 420;

    function onMove(ev) {
      if (!dragging) return;
      var clientX = ev.touches ? ev.touches[0].clientX : ev.clientX;
      var delta = clientX - startX;
      var next = Math.max(280, Math.min(640, startWidth + delta));
      consoleEl.style.setProperty('--ogc-list-width', next + 'px');
    }
    function onUp() {
      if (!dragging) return;
      dragging = false;
      splitter.classList.remove('is-dragging');
      document.removeEventListener('mousemove', onMove);
      document.removeEventListener('mouseup', onUp);
      document.removeEventListener('touchmove', onMove);
      document.removeEventListener('touchend', onUp);
      try {
        localStorage.setItem('ogcConsoleListWidth', consoleEl.style.getPropertyValue('--ogc-list-width'));
      } catch (e) { /* ignore */ }
    }
    function onDown(ev) {
      dragging = true;
      splitter.classList.add('is-dragging');
      startX = ev.touches ? ev.touches[0].clientX : ev.clientX;
      startWidth = parseInt(getComputedStyle(consoleEl).getPropertyValue('--ogc-list-width'), 10) || 420;
      document.addEventListener('mousemove', onMove);
      document.addEventListener('mouseup', onUp);
      document.addEventListener('touchmove', onMove, { passive: false });
      document.addEventListener('touchend', onUp);
      ev.preventDefault();
    }
    try {
      var saved = localStorage.getItem('ogcConsoleListWidth');
      if (saved) consoleEl.style.setProperty('--ogc-list-width', saved);
    } catch (e) { /* ignore */ }
    splitter.addEventListener('mousedown', onDown);
    splitter.addEventListener('touchstart', onDown, { passive: false });
    splitter.addEventListener('keydown', function (ev) {
      var width = parseInt(getComputedStyle(consoleEl).getPropertyValue('--ogc-list-width'), 10) || 420;
      if (ev.key === 'ArrowLeft') {
        consoleEl.style.setProperty('--ogc-list-width', Math.max(280, width - 20) + 'px');
        ev.preventDefault();
      } else if (ev.key === 'ArrowRight') {
        consoleEl.style.setProperty('--ogc-list-width', Math.min(640, width + 20) + 'px');
        ev.preventDefault();
      }
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }

  /* Minimal public surface for debugging / manual re-render. */
  window.SeekmodoChatConsole = {
    refreshList: function () { return fetchList(false); },
    refreshConversation: function () { return fetchConversation(state.selectedChatId, false); },
    selectConversation: selectConversation,
    _state: state,
    _debounce: debounce
  };
})();
