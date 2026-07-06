/**
 * Sprint 3 PR 6 — Seekmodo typeahead client (Zen Cart).
 *
 * Debounced (150ms) keystroke handler on the storefront's search box
 * that GETs /numinix_seekmodo_suggest.php and renders a three-section
 * dropdown (keywords / products / categories) inline below the input.
 *
 * v1.0.20 — typeahead-perf parity with WordPress connector v0.5.0
 * (SM-602 phase B of seekmodo/docs/CONNECTOR_TYPEAHEAD_SPEC.md):
 *
 *   1. Adds a 32-entry LRU cache keyed on (max, normalized q). A
 *      cache hit renders synchronously off the previous payload, so
 *      a shopper backspacing from `boats` to `boat` and back to
 *      `boats` only pays one network round trip total.
 *   2. Tightens the in-flight-abort dance with a `lastQ` guard so
 *      a slow `boa` response can't overwrite the freshly-rendered
 *      `boats` rows when a fast typist outruns the network.
 *   3. Normalizes q (lowercase + collapsed whitespace + trim) on
 *      the cache key so "Boa  Engine" / "boa engine" / "BOA ENGINE"
 *      share the same cache slot — matches the gateway's APCu key
 *      derivation in `TypeaheadTool::cacheKey` so client + server
 *      cache scopes align.
 *
 * Phase C (browser-token gateway-direct fetch) is not in this
 * release because the Zen Cart connector doesn't mint browser tokens
 * at page render today. Adding that mint + the localized config is
 * queued for v1.0.21 along with the migration to the new flat-rows
 * /v1/typeahead path.
 *
 * Drop-in install:
 *   Zen Cart's `jscript_loader.php` already includes any file in the
 *   active template's `jscript/` directory whose name starts with
 *   `jscript_`. This file lives in
 *   `template_default/jscript/jscript_seekmodo_typeahead.js`, so any
 *   storefront on the default template picks it up automatically.
 *   Sites on a custom template should copy this file into their own
 *   template's `jscript/` folder (Zen Cart does NOT inherit jscript
 *   from `template_default` automatically).
 *
 * Auto-wires to any of:
 *   - input[name="keyword"]   (Zen Cart's stock header search box)
 *   - input#keyword
 *   - input[data-seekmodo-typeahead]   (explicit opt-in marker)
 *
 * Form-submit behaviour is unchanged: the search button still POSTs
 * to advanced_search_result.php with the full query. Typeahead only
 * augments the in-progress experience.
 *
 * No external dependencies — vanilla DOM. Bails silently if
 * `fetch()` is unavailable (IE11 / very old Safari) so the
 * storefront's existing typeahead UX (if any) still works.
 */
(function () {
    'use strict';

    if (typeof window === 'undefined' || typeof window.fetch !== 'function') {
        return;
    }

    var DEBOUNCE_MS = 280;
    var MIN_QUERY_LENGTH = 2;
    var MAX_PRODUCTS = 8;
    // Per-keystroke client-side LRU cache size (v1.0.20 / SM-602 phase B).
    // Sized to cover a typical shopper typing 3-5 unique prefixes per
    // session without growing the resident DOM payload. The Map's
    // insertion-order iteration gives us LRU semantics for free —
    // re-inserting on a get bumps the entry to most-recent, and
    // `keys().next().value` is always the oldest.
    var CACHE_SIZE = 32;
    var ENDPOINT = 'numinix_seekmodo_suggest.php';
    var DROPDOWN_CLASS = 'numinix-seekmodo-typeahead';
    var ACTIVE_ROW_CLASS = 'numinix-seekmodo-typeahead__row--active';

    function ready(fn) {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', fn, { once: true });
        } else {
            fn();
        }
    }

    function selectSearchInputs() {
        var inputs = [];
        var seen = new Set();

        function add(el) {
            if (!el || seen.has(el)) {
                return;
            }
            if (el.tagName !== 'INPUT' || (el.type && el.type === 'hidden')) {
                return;
            }
            seen.add(el);
            inputs.push(el);
        }

        Array.prototype.forEach.call(
            document.querySelectorAll('input[data-seekmodo-typeahead]'),
            add
        );
        Array.prototype.forEach.call(
            document.querySelectorAll('input[name="keyword"]'),
            add
        );
        var byId = document.getElementById('keyword');
        if (byId) {
            add(byId);
        }
        return inputs;
    }

    function endpointBase() {
        // Resolve relative to the script's loaded directory so a
        // sub-directory Zen Cart install (e.g. redlinestands.com's
        // /catalog/) works without a hand-edited path.
        var scripts = document.getElementsByTagName('script');
        for (var i = 0; i < scripts.length; i++) {
            var src = scripts[i].src || '';
            if (src.indexOf('jscript_seekmodo_typeahead') === -1) {
                continue;
            }
            // .../catalog/includes/templates/<tpl>/jscript/<file>
            // The endpoint lives at .../catalog/<file>, so walk up
            // four directories.
            var match = src.match(/^(.*)\/includes\/templates\/[^/]+\/jscript\/[^/]+$/);
            if (match) {
                return match[1].replace(/\/$/, '') + '/' + ENDPOINT;
            }
        }
        // Fallback: assume the connector endpoint is at site root /
        // current dir. Either way the storefront's own typeahead
        // picks up when the fetch 404s.
        return ENDPOINT;
    }

    function buildDropdown(input) {
        var dropdown = document.createElement('div');
        dropdown.className = DROPDOWN_CLASS;
        dropdown.setAttribute('role', 'listbox');
        dropdown.style.display = 'none';
        dropdown.style.position = 'absolute';
        dropdown.style.zIndex = '9999';
        dropdown.style.background = '#fff';
        dropdown.style.border = '1px solid rgba(0,0,0,0.15)';
        dropdown.style.boxShadow = '0 4px 12px rgba(0,0,0,0.08)';
        dropdown.style.maxHeight = '420px';
        dropdown.style.overflowY = 'auto';
        dropdown.style.fontSize = '14px';
        // The dropdown sits in the document flow at body level so
        // overflow:hidden parents don't clip it.
        document.body.appendChild(dropdown);

        function position() {
            var rect = input.getBoundingClientRect();
            dropdown.style.top = (rect.bottom + window.pageYOffset) + 'px';
            dropdown.style.left = (rect.left + window.pageXOffset) + 'px';
            dropdown.style.width = rect.width + 'px';
        }
        return { el: dropdown, position: position };
    }

    function escapeHtml(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function renderDropdown(dropdown, q, payload) {
        var html = '';
        var rows = [];

        function section(label, items, rowFn) {
            if (!items || !items.length) {
                return;
            }
            html += '<div class="' + DROPDOWN_CLASS + '__section">';
            html += '<div class="' + DROPDOWN_CLASS + '__heading" style="padding:6px 10px;font-weight:600;color:#666;text-transform:uppercase;font-size:11px;letter-spacing:0.04em;background:#f7f7f7;">';
            html += escapeHtml(label);
            html += '</div>';
            for (var i = 0; i < items.length; i++) {
                var rowHtml = rowFn(items[i]);
                if (rowHtml) {
                    html += rowHtml;
                    rows.push(items[i]);
                }
            }
            html += '</div>';
        }

        section('Suggestions', payload.keywords || [], function (kw) {
            var word = kw.keyword || '';
            if (!word) return '';
            return '<div class="' + DROPDOWN_CLASS + '__row" data-kind="keyword" data-value="'
                + escapeHtml(word) + '" style="padding:8px 10px;cursor:pointer;">'
                + escapeHtml(word) + '</div>';
        });

        section('Products', (payload.products || []).slice(0, MAX_PRODUCTS), function (p) {
            var label = p.value || p.name || '';
            if (!label) return '';
            var href = p.url || ('index.php?main_page=product_info&products_id=' + (p.products_id || ''));
            var price = p.price ? ('<span style="margin-left:8px;color:#888;">' + escapeHtml(p.price) + '</span>') : '';
            var image = p.image
                ? ('<span style="margin-right:8px;display:inline-block;vertical-align:middle;">' + p.image + '</span>')
                : '';
            return '<a class="' + DROPDOWN_CLASS + '__row" href="' + escapeHtml(href)
                + '" data-kind="product" style="display:block;padding:8px 10px;color:inherit;text-decoration:none;">'
                + image + escapeHtml(label) + price + '</a>';
        });

        section('Categories', payload.categories || [], function (cat) {
            var name = cat.name || '';
            if (!name) return '';
            // The storefront's category routing varies (cPath= vs
            // permalink), so we surface the name as a click-to-fill
            // suggestion rather than a deep link.
            return '<div class="' + DROPDOWN_CLASS + '__row" data-kind="category" data-value="'
                + escapeHtml(name) + '" style="padding:8px 10px;cursor:pointer;color:#444;">'
                + escapeHtml(name)
                + ' <span style="color:#888;font-size:12px;">(' + (cat.count || 0) + ')</span>'
                + '</div>';
        });

        if (!html) {
            dropdown.style.display = 'none';
            dropdown.innerHTML = '';
            return;
        }
        dropdown.innerHTML = html;
        dropdown.style.display = 'block';
    }

    function debounce(fn, wait) {
        var t = null;
        return function () {
            var args = arguments;
            var self = this;
            if (t) {
                clearTimeout(t);
            }
            t = setTimeout(function () {
                t = null;
                fn.apply(self, args);
            }, wait);
        };
    }

    /**
     * Tiny LRU keyed on the normalized (max, q) tuple. Backspace ->
     * retype on an already-fetched prefix renders instantly off the
     * cache without a network round trip. Sized by CACHE_SIZE
     * (default 32 entries) — covers a typical session without
     * bloating the long-lived JS heap.
     *
     * Cache values are the parsed `{ok, q, keywords, products,
     * categories, total}` payloads the suggest endpoint returned, so
     * a hit skips both the network AND re-rendering from raw rows.
     *
     * Map is used because its insertion-order iteration gives LRU
     * semantics for free: get() bumps the key by re-inserting it
     * (most-recent wins), and `keys().next().value` always returns
     * the oldest entry.
     */
    function lruCreate() {
        var map = new Map();
        return {
            get: function (k) {
                if (!map.has(k)) return null;
                var v = map.get(k);
                map.delete(k);
                map.set(k, v);
                return v;
            },
            set: function (k, v) {
                if (map.has(k)) map.delete(k);
                map.set(k, v);
                if (map.size > CACHE_SIZE) {
                    var oldest = map.keys().next().value;
                    map.delete(oldest);
                }
            }
        };
    }

    var CACHE = (typeof Map === 'function') ? lruCreate() : null;

    function cacheKey(q) {
        // Normalize the prefix to lowercase + collapsed whitespace
        // so "Boa  Engine" / "boa engine" / "BOA ENGINE" share the
        // same cache slot. Server-side TypeaheadTool does the same
        // normalization before its APCu/Cloudflare lookup, so the
        // client + server cache scopes align.
        var n = String(q || '').toLowerCase().replace(/\s+/g, ' ').trim();
        return MAX_PRODUCTS + '|' + n;
    }

    function attach(input) {
        if (!input || input.dataset.seekmodoTypeaheadBound === '1') {
            return;
        }
        input.dataset.seekmodoTypeaheadBound = '1';
        // Disable browser-native autofill on the search box so the
        // gateway suggestions are visible underneath.
        input.setAttribute('autocomplete', 'off');

        var dd = buildDropdown(input);
        var endpoint = endpointBase();
        var inflight = null;
        // Tracks the most recent query the user has typed. A response
        // arriving after the user has moved on (`q !== lastQ`) is
        // discarded so a slow `boa` callback can't overwrite a
        // freshly-rendered `boats` dropdown.
        var lastQ = '';

        function hide() {
            dd.el.style.display = 'none';
        }

        function run(q) {
            // 1. Cache hit -> render synchronously, no network.
            //    Re-position first because the dropdown might have
            //    been hidden + the input scrolled since the previous
            //    render.
            if (CACHE) {
                var cached = CACHE.get(cacheKey(q));
                if (cached) {
                    dd.position();
                    renderDropdown(dd.el, q, cached);
                    return;
                }
            }

            // 2. Otherwise issue a fresh request. Abort any
            //    in-flight one so a fast typist's "boat" -> "boats"
            //    doesn't render stale "boat" rows after "boats"
            //    arrives.
            if (inflight && typeof inflight.abort === 'function') {
                inflight.abort();
            }
            var ctrl = (typeof AbortController === 'function') ? new AbortController() : null;
            inflight = ctrl;
            var url = endpoint + (endpoint.indexOf('?') >= 0 ? '&' : '?')
                + 'q=' + encodeURIComponent(q)
                + '&max=' + MAX_PRODUCTS;
            window.fetch(url, {
                method: 'GET',
                credentials: 'same-origin',
                signal: ctrl ? ctrl.signal : undefined,
                headers: { 'Accept': 'application/json' }
            }).then(function (resp) {
                return resp.ok ? resp.json() : null;
            }).then(function (payload) {
                // Race-prevention: drop any response for a query the
                // user has already moved past.
                if (q !== lastQ) {
                    return;
                }
                if (!payload || payload.ok !== true) {
                    hide();
                    return;
                }
                if (CACHE) {
                    CACHE.set(cacheKey(q), payload);
                }
                dd.position();
                renderDropdown(dd.el, q, payload);
            }).catch(function () {
                // AbortError lands here too — that's expected, no UI
                // change. Real network errors hide the dropdown so a
                // dead gateway doesn't leak a stale render.
                if (q === lastQ) {
                    hide();
                }
            });
        }

        var debounced = debounce(function () {
            var q = (input.value || '').trim();
            lastQ = q;
            if (q.length < MIN_QUERY_LENGTH) {
                hide();
                return;
            }
            run(q);
        }, DEBOUNCE_MS);

        input.addEventListener('input', debounced);
        input.addEventListener('focus', function () {
            var q = (input.value || '').trim();
            if (q.length >= MIN_QUERY_LENGTH) {
                debounced();
            }
        });
        input.addEventListener('blur', function () {
            // Delay hide so a click inside the dropdown registers
            // before the dropdown disappears.
            setTimeout(hide, 150);
        });
        window.addEventListener('resize', dd.position);
        window.addEventListener('scroll', dd.position, true);

        // Click a non-link row (keyword / category) → fill the box
        // and submit the form.
        dd.el.addEventListener('mousedown', function (e) {
            var row = e.target.closest('.' + DROPDOWN_CLASS + '__row');
            if (!row) return;
            var kind = row.getAttribute('data-kind');
            if (kind === 'keyword' || kind === 'category') {
                var value = row.getAttribute('data-value') || '';
                input.value = value;
                hide();
                var form = input.form;
                if (form) {
                    e.preventDefault();
                    form.submit();
                }
            }
            // Product rows are <a> tags — let the browser navigate.
        });
    }

    ready(function () {
        var inputs = selectSearchInputs();
        for (var i = 0; i < inputs.length; i++) {
            attach(inputs[i]);
        }
    });
})();
