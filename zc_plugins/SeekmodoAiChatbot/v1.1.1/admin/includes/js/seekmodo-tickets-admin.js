/*!
 * Seekmodo Tickets — connector admin app (Zen Cart, WordPress, Lexmodo, ...).
 *
 * Classic script (no modules) so any admin can load it with a plain
 * <script> tag. Usage:
 *
 *   SeekmodoTicketsAdmin.mount(rootEl, request, { title, adminUrl })
 *
 * request(action, body) -> Promise<object> must POST to the connector's
 * local proxy, which signs the call to Seekmodo's tickets_api and adds
 * the logged-in store admin as acting_agent.
 *
 * Styles are scoped under .smk-tickets and use the Seekmodo tokens
 * (charcoal, emerald, aqua, Inter) so nothing leaks into the store admin.
 */
(function (global) {
  "use strict";

  var VIEWS = [
    ["mine", "Mine"],
    ["unassigned", "Unassigned"],
    ["open", "Open"],
    ["pending", "Pending"],
    ["overdue", "Overdue"],
    ["solved", "Solved"],
    ["all", "All"],
  ];
  var STATUS = {
    new: "New",
    open: "Open",
    pending_customer: "Waiting on customer",
    on_hold: "On hold",
    solved: "Solved",
    closed: "Closed",
  };
  var PRIORITIES = ["low", "normal", "high", "urgent"];

  function h(tag, attrs, children) {
    var node = document.createElement(tag);
    if (attrs) {
      for (var k in attrs) {
        if (!Object.prototype.hasOwnProperty.call(attrs, k)) continue;
        var v = attrs[k];
        if (v == null || v === false) continue;
        if (k === "class") node.className = v;
        else if (k === "text") node.textContent = v;
        else if (k.slice(0, 2) === "on") node.addEventListener(k.slice(2), v);
        else node.setAttribute(k, v === true ? "" : String(v));
      }
    }
    (children || []).forEach(function (c) {
      if (c == null || c === false) return;
      node.append(c.nodeType ? c : document.createTextNode(String(c)));
    });
    return node;
  }

  function ago(iso) {
    if (!iso) return "";
    var t = new Date(iso).getTime();
    if (!isFinite(t)) return "";
    var s = Math.max(0, (Date.now() - t) / 1000);
    if (s < 60) return "now";
    if (s < 3600) return Math.floor(s / 60) + "m";
    if (s < 86400) return Math.floor(s / 3600) + "h";
    if (s < 604800) return Math.floor(s / 86400) + "d";
    return new Date(t).toLocaleDateString();
  }

  function dur(ms) {
    var m = Math.round(Math.abs(ms) / 60000);
    if (m < 60) return m + "m";
    if (m < 1440) return Math.floor(m / 60) + "h";
    return Math.floor(m / 1440) + "d";
  }

  function initials(name, email) {
    var src = String(name || email || "?").trim();
    var parts = src.split(/[\s@._-]+/).filter(Boolean);
    return (parts.slice(0, 2).map(function (p) { return p[0]; }).join("") || "?").toUpperCase();
  }

  // Brand-adjacent avatar colours (emerald, aqua, sky, teal, indigo, amber).
  var AV_COLORS = ["#059669", "#0891b2", "#0284c7", "#0d9488", "#4f46e5", "#d97706"];
  function avColor(seed) {
    var n = 0;
    for (var i = 0; i < seed.length; i++) n = (n * 31 + seed.charCodeAt(i)) >>> 0;
    return AV_COLORS[n % AV_COLORS.length];
  }

  function avatars(list, max) {
    var wrap = h("span", { class: "smk-av-stack" });
    if (!list || !list.length) {
      wrap.append(h("span", { class: "smk-av smk-av-empty", title: "Unassigned", text: "+" }));
      return wrap;
    }
    list.slice(0, max || 3).forEach(function (a) {
      var node = h("span", {
        class: "smk-av" + (a.is_primary ? " is-primary" : ""),
        title: (a.name || a.email) + (a.is_primary ? " (primary)" : ""),
        text: initials(a.name, a.email),
      });
      node.style.background = avColor(String(a.agent_id || a.email || ""));
      wrap.append(node);
    });
    if (list.length > (max || 3)) {
      wrap.append(h("span", { class: "smk-av smk-av-more", text: "+" + (list.length - (max || 3)) }));
    }
    return wrap;
  }

  function statusPill(status) {
    return h("span", { class: "smk-pill smk-st-" + status, text: STATUS[status] || status });
  }

  function slaChip(t) {
    var sla = t.sla || {};
    if ((t.status !== "new" && t.status !== "open") || !sla.due_at || sla.state === "none") return null;
    var diff = new Date(sla.due_at).getTime() - Date.now();
    var label = diff < 0 ? "Overdue " + dur(diff) : "Due in " + dur(diff);
    return h("span", { class: "smk-sla smk-sla-" + (sla.state || "ok"), title: new Date(sla.due_at).toLocaleString(), text: label });
  }

  function mount(root, request, opts) {
    opts = opts || {};
    ensureStyles();
    root.innerHTML = "";
    root.classList.add("smk-tickets");

    var state = {
      view: "mine",
      q: "",
      page: 1,
      list: null,
      selected: null,
      detail: null,
      mode: "reply",
      agents: null,
      busy: false,
    };

    var rail = h("nav", { class: "smk-views", "aria-label": "Ticket views" });
    var search = h("input", { type: "search", class: "smk-input", placeholder: "Search tickets", "aria-label": "Search tickets" });
    var rows = h("div", { class: "smk-rows" });
    var pager = h("div", { class: "smk-pager" });
    var listPane = h("section", { class: "smk-list" }, [
      h("form", { class: "smk-search", onsubmit: function (e) { e.preventDefault(); state.q = search.value.trim(); state.page = 1; loadList(); } }, [search]),
      rows,
      pager,
    ]);
    var detail = h("section", { class: "smk-detail", "aria-live": "polite" });
    var toast = h("div", { class: "smk-toast", role: "status" });
    var head = h("header", { class: "smk-head" }, [
      h("span", { class: "smk-brand" }, [h("span", { class: "smk-dot" }), opts.title || "Seekmodo Tickets"]),
      opts.adminUrl ? h("a", { class: "smk-link", href: opts.adminUrl, target: "_blank", rel: "noopener", text: "Open in Seekmodo admin" }) : null,
    ]);
    root.append(head, rail, h("div", { class: "smk-grid" }, [listPane, detail]), toast);

    function say(msg) {
      toast.textContent = msg;
      toast.classList.add("is-on");
      clearTimeout(say.t);
      say.t = setTimeout(function () { toast.classList.remove("is-on"); }, 3200);
    }

    function friendly(err) {
      var m = String((err && err.message) || err || "Request failed");
      if (m.indexOf("forbidden") >= 0) return "You do not have permission to do that.";
      if (m.indexOf("not_paired") >= 0) return "Connect this store to Seekmodo first.";
      return m;
    }

    function call(action, body) {
      return Promise.resolve(request(action, body || {})).then(function (res) {
        if (!res || res.ok === false) throw new Error((res && res.error) || "Request failed");
        return res;
      });
    }

    function paintRail() {
      rail.replaceChildren();
      var counts = (state.list && state.list.counts) || {};
      VIEWS.forEach(function (v) {
        var n = counts[v[0]];
        rail.append(h("button", {
          type: "button",
          class: "smk-view" + (state.view === v[0] ? " is-active" : ""),
          "aria-pressed": state.view === v[0] ? "true" : "false",
          onclick: function () { state.view = v[0]; state.page = 1; loadList(); },
        }, [v[1], n != null && v[0] !== "all" && v[0] !== "solved" ? h("span", { class: "smk-count", text: String(n) }) : null]));
      });
    }

    var listSeq = 0;
    function loadList() {
      var seq = ++listSeq;
      paintRail();
      if (!state.list) rows.replaceChildren(h("div", { class: "smk-skel" }), h("div", { class: "smk-skel" }), h("div", { class: "smk-skel" }));
      return call("list", { view: state.view, q: state.q, page: state.page, per_page: 25 })
        .then(function (res) {
          if (seq !== listSeq) return;
          state.list = res;
          paintRail();
          paintList();
        })
        .catch(function (e) {
          if (seq !== listSeq) return;
          rows.replaceChildren(h("div", { class: "smk-empty" }, [friendly(e), " ", h("button", { type: "button", class: "smk-btn", onclick: loadList, text: "Retry" })]));
        });
    }

    function paintList() {
      var list = state.list || { rows: [] };
      rows.replaceChildren();
      if (!list.rows.length) {
        rows.append(h("div", { class: "smk-empty", text: state.view === "mine" ? "Nothing assigned to you. Check Unassigned for new tickets." : "No tickets in this view." }));
      }
      list.rows.forEach(function (t) {
        var meta = h("div", { class: "smk-row-meta" }, [statusPill(t.status), slaChip(t), h("span", { class: "smk-grow" }), avatars(t.assignees, 3)]);
        rows.append(h("button", {
          type: "button",
          "aria-current": state.selected === t.number ? "true" : null,
          class: "smk-row smk-pr-" + t.priority + (state.selected === t.number ? " is-active" : "") + (t.status === "new" ? " is-new" : ""),
          onclick: function () { open(t.number); },
        }, [
          h("div", { class: "smk-row-top" }, [
            h("span", { class: "smk-num", text: "#" + t.number }),
            h("span", { class: "smk-req", text: t.requester_name || t.requester_email }),
            h("span", { class: "smk-time", title: t.updated_at ? new Date(t.updated_at).toLocaleString() : "", text: ago(t.updated_at) }),
          ]),
          h("div", { class: "smk-subj", text: t.subject || "(no subject)" }),
          t.preview ? h("div", { class: "smk-prev", text: t.preview }) : null,
          meta,
        ]));
      });
      pager.replaceChildren();
      if ((list.pages || 1) > 1) {
        pager.append(
          h("button", { type: "button", class: "smk-btn", disabled: state.page <= 1, onclick: function () { state.page--; loadList(); }, text: "Prev" }),
          h("span", { text: "Page " + state.page + " of " + list.pages }),
          h("button", { type: "button", class: "smk-btn", disabled: state.page >= list.pages, onclick: function () { state.page++; loadList(); }, text: "Next" })
        );
      }
    }

    var detailSeq = 0;
    function open(number, quiet) {
      var seq = ++detailSeq;
      var switching = state.selected !== number;
      state.selected = number;
      root.classList.add("has-detail");
      if (switching) {
        state.detail = null;
        state.mode = "reply";
        detail.replaceChildren(h("div", { class: "smk-skel smk-skel-lg" }));
        paintList();
        call("presence", { number: number }).catch(function () {});
      }
      return call("get", { number: number })
        .then(function (res) {
          if (seq !== detailSeq || state.selected !== number) return;
          state.detail = res;
          paintDetail(!quiet);
        })
        .catch(function (e) {
          if (seq !== detailSeq) return;
          detail.replaceChildren(h("div", { class: "smk-empty", text: friendly(e) }));
        });
    }

    function act(action, body, done) {
      if (state.busy) return Promise.resolve();
      state.busy = true;
      return call(action, Object.assign({ number: state.selected }, body || {}))
        .then(function () {
          if (done) say(done);
          return Promise.all([open(state.selected, true), loadList()]);
        })
        .catch(function (e) { say(friendly(e)); })
        .then(function () { state.busy = false; });
    }

    function paintDetail(scroll) {
      var d = state.detail;
      if (!d) return;
      var t = d.ticket;
      var a = d.actions || {};
      var me = d.me || {};
      var assigned = (t.assignees || []).some(function (x) { return x.agent_id === me.id; });
      var watching = (t.watchers || []).some(function (x) { return x.agent_id === me.id; });

      var statusSel = h("select", { class: "smk-select", "aria-label": "Status", onchange: function (e) { act("update", { status: e.target.value }, "Status updated"); } });
      Object.keys(STATUS).forEach(function (s) { statusSel.append(h("option", { value: s, selected: s === t.status, text: STATUS[s] })); });
      var prioSel = h("select", { class: "smk-select", "aria-label": "Priority", onchange: function (e) { act("update", { priority: e.target.value }, "Priority updated"); } });
      PRIORITIES.forEach(function (p) { prioSel.append(h("option", { value: p, selected: p === t.priority, text: p[0].toUpperCase() + p.slice(1) + " priority" })); });

      var people = h("div", { class: "smk-people" }, [
        avatars(t.assignees, 4),
        h("span", { class: "smk-people-label", text: (t.assignees || []).length ? (t.assignees || []).map(function (x) { return x.name || x.email; }).join(", ") : "Unassigned" }),
        assigned
          ? h("button", { type: "button", class: "smk-btn", onclick: function () { act("assign", { remove: [me.id] }, "Removed you"); }, text: "Unassign me" })
          : h("button", { type: "button", class: "smk-btn smk-btn-primary", onclick: function () { act("assign", { self: true }, "Assigned to you"); }, text: "Assign to me" }),
        a.can_assign_others ? h("button", { type: "button", class: "smk-btn", onclick: openPicker, text: "Edit" }) : null,
        h("button", { type: "button", class: "smk-btn", onclick: function () { act(watching ? "unwatch" : "watch", {}, watching ? "Stopped watching" : "Watching"); }, text: watching ? "Unwatch" : "Watch" }),
      ]);

      var thread = h("div", { class: "smk-thread" });
      (d.messages || []).forEach(function (m) {
        if (m.kind === "system") {
          thread.append(h("div", { class: "smk-sys", text: m.body }));
          return;
        }
        var cls = m.kind === "internal_note" ? "note" : m.kind === "customer_message" ? "cust" : "team";
        thread.append(h("div", { class: "smk-msg smk-msg-" + cls }, [
          h("div", { class: "smk-msg-head" }, [
            h("strong", { text: (cls === "note" ? "Internal note · " : "") + (m.author_name || m.author_email || "") }),
            h("span", { title: m.created_at ? new Date(m.created_at).toLocaleString() : "", text: ago(m.created_at) }),
          ]),
          h("div", { class: "smk-msg-body", text: m.body }),
        ]));
      });

      var draftKey = "smk-ticket-draft:" + t.number;
      var box = h("textarea", { class: "smk-input smk-compose", rows: "3", placeholder: state.mode === "note" ? "Internal note (team only)" : "Reply to " + (t.requester_name || t.requester_email) });
      try { box.value = global.localStorage.getItem(draftKey) || ""; } catch (e) { /* storage blocked */ }
      box.addEventListener("input", function () { try { global.localStorage.setItem(draftKey, box.value); } catch (e) { /* ignore */ } });
      var after = h("select", { class: "smk-select", "aria-label": "Status after reply" }, [
        h("option", { value: "pending_customer", text: "Then: waiting on customer" }),
        h("option", { value: "open", text: "Then: keep open" }),
        h("option", { value: "solved", text: "Then: solved" }),
        h("option", { value: "on_hold", text: "Then: on hold" }),
      ]);
      function send() {
        var text = box.value.trim();
        if (!text) return;
        var body = state.mode === "note" ? { body: text } : { body: text, status_after: after.value };
        act(state.mode === "note" ? "note" : "reply", body, state.mode === "note" ? "Note added" : "Reply sent").then(function () {
          box.value = "";
          try { global.localStorage.removeItem(draftKey); } catch (e) { /* ignore */ }
        });
      }
      box.addEventListener("keydown", function (e) { if (e.key === "Enter" && (e.metaKey || e.ctrlKey)) { e.preventDefault(); send(); } });
      if (!a.can_reply) state.mode = "note";
      var tabs = h("div", { class: "smk-tabs", role: "tablist" }, [
        a.can_reply ? h("button", { type: "button", role: "tab", class: "smk-tab" + (state.mode === "reply" ? " is-active" : ""), "aria-selected": String(state.mode === "reply"), onclick: function () { state.mode = "reply"; paintDetail(false); }, text: "Public reply" }) : null,
        h("button", { type: "button", role: "tab", class: "smk-tab" + (state.mode === "note" ? " is-active" : ""), "aria-selected": String(state.mode === "note"), onclick: function () { state.mode = "note"; paintDetail(false); }, text: "Internal note" }),
      ]);
      var composer = h("div", { class: "smk-composer" + (state.mode === "note" ? " is-note" : "") }, [
        tabs,
        box,
        h("div", { class: "smk-compose-bar" }, [
          state.mode === "reply" ? after : h("span", { class: "smk-hint", text: "Only your team sees notes." }),
          h("span", { class: "smk-grow" }),
          h("span", { class: "smk-hint", text: "Ctrl+Enter" }),
          h("button", { type: "button", class: "smk-btn smk-btn-primary", onclick: send, text: state.mode === "note" ? "Add note" : "Send reply" }),
        ]),
      ]);

      detail.replaceChildren(
        h("div", { class: "smk-detail-head" }, [
          h("button", { type: "button", class: "smk-back", onclick: function () { root.classList.remove("has-detail"); }, text: "‹ Tickets" }),
          h("div", { class: "smk-title-row" }, [
            h("h2", { class: "smk-title", text: t.subject || "(no subject)" }),
            h("span", { class: "smk-num", text: "#" + t.number }),
          ]),
          h("div", { class: "smk-req-line", text: (t.requester_name ? t.requester_name + " · " : "") + t.requester_email }),
          h("div", { class: "smk-controls" }, [statusSel, prioSel, slaChip(t)]),
          people,
          d.viewers && d.viewers.length ? h("div", { class: "smk-viewers", text: d.viewers.map(function (v) { return v.name + (v.typing ? " (typing)" : ""); }).join(", ") + (d.viewers.length === 1 ? " is" : " are") + " viewing this ticket" }) : null,
        ]),
        thread,
        composer
      );
      if (scroll) thread.scrollTop = thread.scrollHeight;
    }

    function openPicker() {
      var d = state.detail;
      if (!d) return;
      var load = state.agents ? Promise.resolve(state.agents) : call("agents").then(function (r) { state.agents = r.agents || []; return state.agents; });
      load.then(function (agents) {
        var t = d.ticket;
        var chosen = {};
        var primary = null;
        (t.assignees || []).forEach(function (x) { chosen[x.agent_id] = true; if (x.is_primary) primary = x.agent_id; });
        var filter = h("input", { class: "smk-input", type: "search", placeholder: "Find a teammate or store admin" });
        var listEl = h("div", { class: "smk-pick-list" });
        function paint() {
          var q = filter.value.trim().toLowerCase();
          listEl.replaceChildren();
          agents
            .filter(function (g) { return g.active !== false && (!q || (g.name + " " + g.email).toLowerCase().indexOf(q) >= 0); })
            .forEach(function (g) {
              var cb = h("input", { type: "checkbox", checked: !!chosen[g.id], onchange: function (e) { chosen[g.id] = e.target.checked; if (!e.target.checked && primary === g.id) primary = null; if (e.target.checked && !primary) primary = g.id; paint(); } });
              var star = h("button", { type: "button", class: "smk-star" + (primary === g.id ? " is-on" : ""), disabled: !chosen[g.id], title: "Make primary", "aria-label": "Make primary", onclick: function () { primary = g.id; paint(); }, text: primary === g.id ? "★" : "☆" });
              listEl.append(h("label", { class: "smk-pick" }, [cb, avatars([{ agent_id: g.id, name: g.name, email: g.email }], 1), h("span", { class: "smk-grow" }, [h("strong", { text: g.name || g.email }), h("small", { text: g.email + (g.source === "store" ? " · store admin" : "") })]), star]));
            });
        }
        filter.addEventListener("input", paint);
        var dlg = h("div", { class: "smk-modal", role: "dialog", "aria-modal": "true", "aria-label": "Assignees" });
        function close() { dlg.remove(); }
        dlg.append(h("div", { class: "smk-modal-card" }, [
          h("h3", { text: "Assignees" }),
          filter,
          listEl,
          h("div", { class: "smk-compose-bar" }, [
            h("span", { class: "smk-hint", text: "The star marks the primary assignee." }),
            h("span", { class: "smk-grow" }),
            h("button", { type: "button", class: "smk-btn", onclick: close, text: "Cancel" }),
            h("button", {
              type: "button",
              class: "smk-btn smk-btn-primary",
              onclick: function () {
                var before = {};
                (t.assignees || []).forEach(function (x) { before[x.agent_id] = true; });
                var add = Object.keys(chosen).filter(function (k) { return chosen[k] && !before[k]; });
                var remove = Object.keys(before).filter(function (k) { return !chosen[k]; });
                close();
                act("assign", { add: add, remove: remove, primary: primary || undefined }, "Assignees updated");
              },
              text: "Save",
            }),
          ]),
        ]));
        dlg.addEventListener("click", function (e) { if (e.target === dlg) close(); });
        dlg.addEventListener("keydown", function (e) { if (e.key === "Escape") close(); });
        root.append(dlg);
        paint();
        filter.focus();
      }).catch(function (e) { say(friendly(e)); });
    }

    // Poll: list every 20s, open ticket every 15s (with presence).
    var timers = [
      setInterval(function () { if (!document.hidden) loadList(); }, 20000),
      setInterval(function () {
        if (document.hidden || !state.selected) return;
        call("presence", { number: state.selected }).catch(function () {});
        open(state.selected, true);
      }, 15000),
    ];

    var initial = Number(opts.openTicket || 0);
    loadList().then(function () { if (initial > 0) open(initial); });

    return {
      open: open,
      refresh: loadList,
      destroy: function () { timers.forEach(clearInterval); root.innerHTML = ""; },
    };
  }

  var CSS = [
    ".smk-tickets{--smk-ink:#111827;--smk-muted:#6b7280;--smk-line:#e5e7eb;--smk-soft:#f8fafc;--smk-em:#10b981;--smk-em-ink:#047857;--smk-aq:#06b6d4;--smk-amber:#f59e0b;--smk-red:#dc2626;font:14px/1.45 Inter,ui-sans-serif,'Segoe UI',sans-serif;color:var(--smk-ink);position:relative;background:linear-gradient(135deg,rgba(16,185,129,.06),rgba(6,182,212,.06));border:1px solid var(--smk-line);border-radius:14px;padding:12px;box-sizing:border-box}",
    ".smk-tickets *{box-sizing:border-box}",
    ".smk-head{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:2px 4px 10px}",
    ".smk-brand{display:inline-flex;align-items:center;gap:8px;font-weight:700;font-size:16px;letter-spacing:-.01em}",
    ".smk-dot{width:12px;height:12px;border-radius:4px;background:linear-gradient(135deg,var(--smk-em),var(--smk-aq))}",
    ".smk-link{color:var(--smk-em-ink);text-decoration:none;font-weight:600}.smk-link:hover{text-decoration:underline}",
    ".smk-views{display:flex;gap:6px;overflow-x:auto;padding:0 2px 10px}",
    ".smk-view{border:1px solid var(--smk-line);background:#fff;border-radius:8px;padding:5px 10px;font:inherit;font-weight:500;color:var(--smk-ink);cursor:pointer;white-space:nowrap;display:inline-flex;gap:6px;align-items:center}",
    ".smk-view.is-active{background:var(--smk-ink);color:#fff;border-color:var(--smk-ink)}",
    ".smk-count{font-size:11px;font-weight:700;padding:0 6px;border-radius:6px;background:rgba(16,185,129,.15);color:var(--smk-em-ink)}.smk-view.is-active .smk-count{background:rgba(255,255,255,.18);color:#fff}",
    ".smk-grid{display:grid;grid-template-columns:minmax(260px,360px) minmax(0,1fr);height:min(720px,calc(100vh - 220px));min-height:420px;background:#fff;border:1px solid var(--smk-line);border-radius:12px;overflow:hidden}",
    ".smk-list{display:flex;flex-direction:column;min-height:0;min-width:0;border-right:1px solid var(--smk-line)}",
    ".smk-search{padding:10px;border-bottom:1px solid var(--smk-line)}",
    ".smk-input,.smk-select{width:100%;font:inherit;border:1px solid var(--smk-line);border-radius:8px;padding:7px 10px;background:#fff;color:var(--smk-ink)}",
    ".smk-select{width:auto}",
    ".smk-input:focus,.smk-select:focus,.smk-btn:focus-visible,.smk-row:focus-visible,.smk-view:focus-visible,.smk-tab:focus-visible{outline:2px solid var(--smk-em);outline-offset:1px}",
    ".smk-rows{flex:1;overflow:auto}",
    ".smk-row{display:block;width:100%;text-align:left;border:0;border-bottom:1px solid #f1f5f9;border-left:3px solid transparent;background:#fff;padding:10px 12px;cursor:pointer;font:inherit;color:inherit}",
    ".smk-row:hover{background:var(--smk-soft)}.smk-row.is-active{background:rgba(16,185,129,.08)}",
    ".smk-pr-urgent{border-left-color:var(--smk-red)}.smk-pr-high{border-left-color:var(--smk-amber)}",
    ".smk-row-top{display:flex;gap:8px;align-items:baseline;font-size:12px;color:var(--smk-muted)}",
    ".smk-num{font-variant-numeric:tabular-nums;color:var(--smk-muted)}",
    ".smk-req{flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:var(--smk-ink);font-weight:500}",
    ".smk-subj{font-weight:600;margin-top:2px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.smk-row.is-new .smk-subj{font-weight:700}",
    ".smk-prev{font-size:12px;color:var(--smk-muted);overflow:hidden;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical}",
    ".smk-row-meta{display:flex;align-items:center;gap:6px;margin-top:6px}.smk-grow{flex:1}",
    ".smk-pill,.smk-sla{display:inline-block;font-size:11px;font-weight:600;padding:1px 7px;border-radius:6px;background:#f1f5f9;color:#475569}",
    ".smk-st-new{background:rgba(6,182,212,.14);color:#0e7490}.smk-st-open{background:rgba(16,185,129,.14);color:var(--smk-em-ink)}.smk-st-pending_customer{background:#fef3c7;color:#92400e}",
    ".smk-sla-ok{background:rgba(16,185,129,.12);color:var(--smk-em-ink)}.smk-sla-warning{background:#fef3c7;color:#92400e}.smk-sla-breached{background:#fee2e2;color:#b91c1c}",
    ".smk-av-stack{display:inline-flex}.smk-av{width:24px;height:24px;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;color:#fff;font-size:10px;font-weight:700;border:2px solid #fff;margin-left:-7px;background:#64748b}.smk-av:first-child{margin-left:0}",
    ".smk-av.is-primary{box-shadow:0 0 0 2px var(--smk-em)}.smk-av-empty{background:#fff;color:var(--smk-muted);border:1.5px dashed #cbd5e1}.smk-av-more{background:#e2e8f0;color:#334155}",
    ".smk-pager{display:flex;align-items:center;justify-content:space-between;gap:8px;padding:8px 10px;border-top:1px solid var(--smk-line);font-size:12px}",
    ".smk-detail{display:flex;flex-direction:column;min-height:0;min-width:0}",
    ".smk-detail-head{padding:14px 16px 10px;border-bottom:1px solid var(--smk-line)}",
    ".smk-back{display:none;border:0;background:none;color:var(--smk-em-ink);font:inherit;font-weight:600;padding:0 0 6px;cursor:pointer}",
    ".smk-title-row{display:flex;gap:10px;align-items:baseline;flex-wrap:wrap}.smk-title{margin:0;font-size:18px;font-weight:700;letter-spacing:-.01em;flex:1 1 240px}",
    ".smk-req-line{color:var(--smk-muted);font-size:13px;margin-top:2px}",
    ".smk-controls{display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-top:10px}",
    ".smk-people{display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-top:10px}.smk-people-label{font-size:13px;flex:1 1 160px}",
    ".smk-viewers{margin-top:10px;font-size:12px;padding:6px 10px;border-radius:8px;background:rgba(6,182,212,.1);color:#0e7490}",
    ".smk-thread{flex:1;overflow:auto;padding:14px 16px;display:flex;flex-direction:column;gap:10px;background:var(--smk-soft)}",
    ".smk-msg{max-width:85%;padding:9px 12px;border-radius:14px;background:#fff;border:1px solid var(--smk-line)}",
    ".smk-msg-cust{align-self:flex-start;border-bottom-left-radius:4px}",
    ".smk-msg-team{align-self:flex-end;background:rgba(16,185,129,.12);border-color:rgba(16,185,129,.25);border-bottom-right-radius:4px}",
    ".smk-msg-note{align-self:stretch;max-width:none;background:#fffbeb;border-color:#fde68a}",
    ".smk-msg-head{display:flex;gap:8px;font-size:12px;color:var(--smk-muted);margin-bottom:3px}.smk-msg-head strong{color:var(--smk-ink)}",
    ".smk-msg-body{white-space:pre-wrap;word-break:break-word}",
    ".smk-sys{align-self:center;font-size:12px;color:var(--smk-muted)}",
    ".smk-composer{border-top:1px solid var(--smk-line);padding:10px 12px;background:#fff}.smk-composer.is-note{background:#fffbeb}",
    ".smk-tabs{display:flex;gap:4px;margin-bottom:8px}",
    ".smk-tab{border:0;background:none;font:inherit;font-weight:600;color:var(--smk-muted);padding:4px 10px;border-radius:8px;cursor:pointer}.smk-tab.is-active{background:var(--smk-ink);color:#fff}",
    ".smk-compose{resize:vertical;min-height:72px}",
    ".smk-compose-bar{display:flex;align-items:center;gap:8px;margin-top:8px;flex-wrap:wrap}",
    ".smk-hint{font-size:12px;color:var(--smk-muted)}",
    ".smk-btn{border:1px solid var(--smk-line);background:#fff;border-radius:8px;padding:6px 12px;font:inherit;font-weight:600;color:var(--smk-ink);cursor:pointer}.smk-btn:hover{background:var(--smk-soft)}.smk-btn:disabled{opacity:.5;cursor:not-allowed}",
    ".smk-btn-primary{background:var(--smk-ink);color:#fff;border-color:var(--smk-ink)}.smk-btn-primary:hover{background:#1f2937}",
    ".smk-empty{padding:40px 20px;text-align:center;color:var(--smk-muted)}",
    ".smk-skel{height:64px;margin:10px;border-radius:10px;background:linear-gradient(90deg,#f1f5f9,#e2e8f0,#f1f5f9);background-size:200% 100%;animation:smk-shimmer 1.2s infinite}.smk-skel-lg{height:180px}",
    "@keyframes smk-shimmer{to{background-position:-200% 0}}",
    "@media (prefers-reduced-motion:reduce){.smk-skel{animation:none}}",
    ".smk-toast{position:absolute;left:50%;bottom:18px;transform:translate(-50%,12px);opacity:0;pointer-events:none;background:var(--smk-ink);color:#fff;padding:8px 14px;border-radius:10px;font-weight:500;transition:opacity .2s,transform .2s}.smk-toast.is-on{opacity:1;transform:translate(-50%,0)}",
    ".smk-modal{position:fixed;inset:0;background:rgba(17,24,39,.45);display:flex;align-items:center;justify-content:center;z-index:100000;padding:16px}",
    ".smk-modal-card{background:#fff;border-radius:14px;padding:16px;width:min(460px,100%);max-height:80vh;display:flex;flex-direction:column;gap:10px;font:14px/1.45 Inter,ui-sans-serif,'Segoe UI',sans-serif;color:var(--smk-ink)}.smk-modal-card h3{margin:0;font-size:16px}",
    ".smk-pick-list{overflow:auto;border:1px solid var(--smk-line);border-radius:10px}",
    ".smk-pick{display:flex;align-items:center;gap:10px;padding:8px 10px;border-bottom:1px solid #f1f5f9;cursor:pointer}.smk-pick small{display:block;color:var(--smk-muted)}",
    ".smk-star{border:0;background:none;font-size:18px;color:#cbd5e1;cursor:pointer}.smk-star.is-on{color:var(--smk-amber)}.smk-star:disabled{opacity:.3;cursor:default}",
    "@media (max-width:820px){.smk-grid{grid-template-columns:minmax(0,1fr);height:auto;min-height:0}.smk-detail{display:none;min-height:70vh}.smk-tickets.has-detail .smk-detail{display:flex}.smk-tickets.has-detail .smk-list{display:none}.smk-back{display:inline-block}.smk-list{border-right:0;max-height:75vh}}",
  ].join("\n");

  function ensureStyles() {
    if (document.querySelector("style[data-smk-tickets]")) return;
    var style = document.createElement("style");
    style.setAttribute("data-smk-tickets", "1");
    style.textContent = CSS;
    document.head.appendChild(style);
  }

  global.SeekmodoTicketsAdmin = { mount: mount, version: "1.0.0" };
})(typeof window !== "undefined" ? window : globalThis);
