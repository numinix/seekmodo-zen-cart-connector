/**
 * Sprint 4 PR 6 — Seekmodo recommendations client.
 *
 * Auto-renders product recommendation widgets into any DOM node
 * tagged with `data-seekmodo-placement="..."`. The observer hook
 * in NuminixSeekmodoObserver.php injects these placeholder divs
 * onto the PDP / cart / category templates; the JS module fetches
 * one recommendation set per placeholder and replaces the
 * placeholder contents with a small product grid.
 *
 * Drop-in install: Zen Cart's `jscript_loader.php` auto-includes
 * any `jscript_*.js` in the active template's `jscript/` directory.
 * This file lives at
 * `template_default/jscript/jscript_seekmodo_recommendations.js`,
 * so the default template picks it up automatically. Sites on a
 * custom template should copy it into their own template's
 * `jscript/` folder (Zen Cart does NOT inherit jscript from
 * template_default).
 *
 * Placement element contract:
 *
 *   <div data-seekmodo-placement="pdp-related"
 *        data-seekmodo-doc-id="12345"
 *        data-seekmodo-limit="8"
 *        data-seekmodo-bundle-size="3">
 *     <!-- intentionally empty; JS replaces innerHTML on success -->
 *   </div>
 *
 *   - `data-seekmodo-placement` (required) — one of pdp-related,
 *     pdp-also-bought, pdp-also-viewed, pdp-bundle, cart-also-bought,
 *     cart-bundle, home-trending, category-trending.
 *   - `data-seekmodo-doc-id` (required for the *-anchored placements)
 *     — the Zen Cart products_id of the anchor product. Skipped for
 *     trending placements.
 *   - `data-seekmodo-limit` (optional, 1..50, default 8) — number of
 *     recommendation rows to render.
 *   - `data-seekmodo-bundle-size` (optional, 2..5, default 3) — only
 *     read for bundle.suggest placements.
 *
 * Each placement = exactly one fetch to numinix_seekmodo_recommend.php
 * = exactly one `searches` token billed on the gateway side. Bots are
 * excluded (bot-check classifier on the gateway). When the gateway
 * fails (or returns ok:false), the placeholder div stays empty so the
 * page layout doesn't shift.
 *
 * No external dependencies — vanilla DOM. Bails silently if
 * `fetch()` is unavailable.
 */
(function () {
    'use strict';

    if (typeof window === 'undefined' || typeof window.fetch !== 'function') {
        return;
    }

    var ENDPOINT = 'numinix_seekmodo_recommend.php';
    var ROOT_CLASS = 'numinix-seekmodo-recommendations';
    var ROW_CLASS = 'numinix-seekmodo-recommendations__row';
    var ITEM_CLASS = 'numinix-seekmodo-recommendations__item';

    // Per-placement default headings. Operators can override by
    // setting `data-seekmodo-heading` on the placement div, or hide
    // it entirely by setting `data-seekmodo-heading=""`.
    var DEFAULT_HEADINGS = {
        'pdp-related':        'Related products',
        'pdp-also-bought':    'Customers also bought',
        'pdp-also-viewed':    'Customers also viewed',
        'pdp-bundle':         'Frequently bought together',
        'cart-also-bought':   'Add to your cart',
        'cart-bundle':        'Complete your bundle',
        'home-trending':      'Trending now',
        'category-trending':  'Trending in this category'
    };

    function ready(fn) {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', fn, { once: true });
        } else {
            fn();
        }
    }

    function escapeHtml(s) {
        if (s === null || s === undefined) {
            return '';
        }
        return String(s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function selectPlaceholders() {
        return Array.prototype.slice.call(
            document.querySelectorAll('[data-seekmodo-placement]')
        );
    }

    function buildEndpoint(el) {
        var placement = el.getAttribute('data-seekmodo-placement') || '';
        if (placement === '') {
            return null;
        }
        var params = new URLSearchParams();
        params.set('placement', placement);

        var docId = el.getAttribute('data-seekmodo-doc-id') || '';
        if (docId !== '') {
            params.set('doc_id', docId);
        }

        var limit = parseInt(el.getAttribute('data-seekmodo-limit') || '8', 10);
        if (!isNaN(limit) && limit > 0) {
            params.set('limit', String(Math.min(50, limit)));
        }

        var bundleSize = parseInt(el.getAttribute('data-seekmodo-bundle-size') || '0', 10);
        if (!isNaN(bundleSize) && bundleSize >= 2 && bundleSize <= 5) {
            params.set('bundle_size', String(bundleSize));
        }

        return ENDPOINT + '?' + params.toString();
    }

    function renderItems(items) {
        if (!items || items.length === 0) {
            return '';
        }
        var rows = items.map(function (it) {
            var url = it.url || '#';
            var name = it.name || it.value || '';
            var imageHtml = it.image_html || '';
            // Fall back to a <img> wrapper if Zen Cart's helper didn't
            // emit one but the gateway gave us a raw URL.
            if (imageHtml === '' && it.image) {
                imageHtml = '<img src="' + escapeHtml(it.image)
                    + '" alt="' + escapeHtml(name) + '" loading="lazy">';
            }
            var price = it.price_formatted || '';
            if (price === '' && typeof it.price === 'number') {
                price = '$' + it.price.toFixed(2);
            }
            return ''
                + '<li class="' + ITEM_CLASS + '">'
                + '<a href="' + escapeHtml(url) + '" class="'
                + ITEM_CLASS + '__link" data-seekmodo-doc-id="'
                + escapeHtml(it.doc_id || '') + '">'
                + (imageHtml ? '<span class="' + ITEM_CLASS + '__image">'
                    + imageHtml + '</span>' : '')
                + '<span class="' + ITEM_CLASS + '__name">'
                + escapeHtml(name) + '</span>'
                + (price ? '<span class="' + ITEM_CLASS + '__price">'
                    + escapeHtml(price) + '</span>' : '')
                + '</a>'
                + '</li>';
        }).join('');
        return '<ul class="' + ROW_CLASS + '">' + rows + '</ul>';
    }

    function renderEnvelope(el, envelope) {
        if (!envelope || envelope.ok !== true) {
            // Leave the placeholder empty — no layout shift.
            return;
        }
        var items = envelope.recommendations || [];
        if (items.length === 0) {
            return;
        }
        var headingAttr = el.getAttribute('data-seekmodo-heading');
        var heading;
        if (headingAttr !== null) {
            // Explicit empty string = hide the heading.
            heading = headingAttr === '' ? '' : headingAttr;
        } else {
            heading = DEFAULT_HEADINGS[envelope.placement] || '';
        }
        var headingHtml = heading === ''
            ? ''
            : '<h2 class="' + ROOT_CLASS + '__heading">'
                + escapeHtml(heading) + '</h2>';
        el.classList.add(ROOT_CLASS);
        el.setAttribute('data-seekmodo-rendered', '1');
        el.innerHTML = headingHtml + renderItems(items);
    }

    function loadOne(el) {
        if (el.getAttribute('data-seekmodo-rendered') === '1') {
            return;
        }
        var url = buildEndpoint(el);
        if (url === null) {
            return;
        }
        window.fetch(url, {
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json' }
        }).then(function (resp) {
            if (!resp.ok) {
                return null;
            }
            return resp.json();
        }).then(function (envelope) {
            renderEnvelope(el, envelope);
        }).catch(function () {
            // Swallow — placeholder stays empty, the page layout is
            // unaffected, the storefront's other content renders
            // normally.
        });
    }

    ready(function () {
        var els = selectPlaceholders();
        for (var i = 0; i < els.length; i++) {
            loadOne(els[i]);
        }
    });
}());
