/**
 * Seekmodo recommendations client — single-algo + PDP/cart cascades.
 *
 * Placeholders: <div data-seekmodo-placement="..."> filled via
 * numinix_seekmodo_recommend.php. Cascades: pdp-cascade, cart.
 *
 * @see seekmodo.com/docs/connectors/recommendations-pdp-cart.md
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
    var SECTION_CLASS = 'numinix-seekmodo-recommendations__section';

    var DEFAULT_HEADINGS = {
        'pdp-related':        'Related products',
        'pdp-also-bought':    'Customers also bought',
        'pdp-also-viewed':    'Customers also viewed',
        'pdp-bundle':         'Frequently bought together',
        'pdp-cascade':        '',
        'cart':               'Add to your cart',
        'cart_below':         'Add to your cart',
        'cart-also-bought':   'Add to your cart',
        'cart-bundle':        'Complete your bundle',
        'home-trending':      'Trending now',
        'category-trending':  'Trending in this category'
    };

    var CASCADE_SECTION_HEADINGS = {
        bought:  'Customers also bought',
        related: 'Related products',
        popular: 'Popular in this category'
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

        var docIds = el.getAttribute('data-seekmodo-doc-ids') || '';
        if (docIds !== '') {
            params.set('doc_ids', docIds);
        }

        var exclude = el.getAttribute('data-seekmodo-exclude-doc-ids') || '';
        if (exclude !== '') {
            params.set('exclude_doc_ids', exclude);
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

    function resolveHeading(el, placementKey) {
        var headingAttr = el.getAttribute('data-seekmodo-heading');
        if (headingAttr !== null) {
            return headingAttr === '' ? '' : headingAttr;
        }
        return DEFAULT_HEADINGS[placementKey] || '';
    }

    function renderCascade(el, envelope) {
        var placements = envelope.placements || {};
        var sections = [
            { key: 'bought', items: placements.bought || [] },
            { key: 'related', items: placements.related || [] },
            { key: 'popular', items: placements.popular || [] }
        ];
        var html = '';
        for (var i = 0; i < sections.length; i++) {
            var sec = sections[i];
            if (!sec.items.length) {
                continue;
            }
            var title = CASCADE_SECTION_HEADINGS[sec.key] || '';
            html += '<div class="' + SECTION_CLASS + '" data-seekmodo-section="'
                + escapeHtml(sec.key) + '">';
            if (title !== '') {
                html += '<h2 class="' + ROOT_CLASS + '__heading">'
                    + escapeHtml(title) + '</h2>';
            }
            html += renderItems(sec.items) + '</div>';
        }
        if (html === '') {
            return;
        }
        el.classList.add(ROOT_CLASS);
        el.setAttribute('data-seekmodo-rendered', '1');
        el.innerHTML = html;
    }

    function renderEnvelope(el, envelope) {
        if (!envelope || envelope.ok !== true) {
            return;
        }
        var placement = envelope.placement || el.getAttribute('data-seekmodo-placement') || '';
        if (placement === 'pdp-cascade' && envelope.placements) {
            renderCascade(el, envelope);
            return;
        }
        var items = envelope.recommendations || [];
        if (items.length === 0) {
            return;
        }
        var heading = resolveHeading(el, placement);
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
            // Swallow — placeholder stays empty.
        });
    }

    function refreshAll() {
        var els = selectPlaceholders();
        for (var i = 0; i < els.length; i++) {
            els[i].removeAttribute('data-seekmodo-rendered');
            els[i].innerHTML = '';
            els[i].classList.remove(ROOT_CLASS);
            loadOne(els[i]);
        }
    }

    ready(function () {
        var els = selectPlaceholders();
        for (var i = 0; i < els.length; i++) {
            loadOne(els[i]);
        }
    });

    // Soft refresh after cart mutations when the host page fires a
    // custom event (or after add-to-cart forms on shopping_cart).
    document.addEventListener('seekmodo:cart-updated', refreshAll);
    if (typeof window.jQuery === 'function') {
        window.jQuery(document).on('ajaxComplete', function (_e, xhr, settings) {
            var url = (settings && settings.url) ? String(settings.url) : '';
            if (url.indexOf('shopping_cart') !== -1 || url.indexOf('cart') !== -1) {
                refreshAll();
            }
        });
    }

    window.SeekmodoRecommendations = { refresh: refreshAll };
}());
