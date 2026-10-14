/*
 * Storefront for Stripe - front-end. No libraries, no PHP sessions.
 * Cart state lives in the browser (localStorage) because WordPress.com
 * caching + Cloudflare serve static HTML. Prices come from the server
 * (Stripe, cached) - never trusted from the browser.
 * Drawer UX mimics side-cart-woocommerce: slide-out panel, overlay,
 * live badge, opens on add-to-cart. No Bootstrap dependency.
 */
(function () {
    'use strict';
    var KEY = 'stripe_shop_lite_cart';
    var CART_PAGE = (window.stripeShopLiteAjax && stripeShopLiteAjax.cartPage) || '/cart/';
    // Server-provided placeholder image for products without a picture.
    var PH = (window.stripeShopLiteAjax && stripeShopLiteAjax.placeholder) || '';
    // Shopping-bag icon for the drawer header (currentColor = dark-mode safe).
    var BAG_SVG = '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" version="1.1" fill="currentColor" viewBox="0 0 1200 1200" aria-hidden="true"><path d="m450 400v150.05c0 27.59-22.191 49.953-50 49.953-27.613 0-50-22.168-50-49.953v-25.023 100.09-225.12h-150c-4.2148 0-11.188 6.2344-11.648 10.363l-76.625 689.64h976.55l-76.625-689.64c-0.45703-4.1094-7.4414-10.363-11.648-10.363h-150v225.12-100.09 25.023c0 27.59-22.191 49.953-50 49.953-27.613 0-50-22.168-50-49.953v-150.05zm0-100h300v-50.238c0-82.703-67.129-149.76-150-149.76-82.699 0-150 67.188-150 149.76zm-100 0v-50.238c0-137.94 112.21-249.76 250-249.76 138.07 0 250 111.8 250 249.76v50.238h150c55.227 0 104.96 44.633 111.04 99.32l77.93 701.36c6.0938 54.852-33.816 99.32-89.211 99.32h-999.51c-55.363 0-95.285-44.633-89.211-99.32l77.93-701.36c6.0938-54.852 55.945-99.32 111.04-99.32z"></path></svg>';

    function read() {
        try { return JSON.parse(localStorage.getItem(KEY)) || {}; }
        catch (e) { return {}; }
    }
    function write(cart) {
        localStorage.setItem(KEY, JSON.stringify(cart)); updateBadges();
        // Logged-in: mirror the cart into user meta (cross-device).
        if (window.stripeShopLiteAjax && stripeShopLiteAjax.loggedIn) {
            clearTimeout(write._t);
            write._t = setTimeout(function () { post('stripe_shop_lite_cart_set', { cart: JSON.stringify(read()) }); }, 400);
        }
    }
    function count(cart) { return Object.keys(cart).reduce(function (n, k) { return n + cart[k]; }, 0); }

    function updateBadges() {
        var c = count(read());
        document.querySelectorAll('.stripe-shop-lite-cart-count').forEach(function (el) { el.textContent = c; });
        var badge = document.querySelector('.stripe-shop-lite-drawer-badge');
        if (badge) { badge.textContent = c; badge.style.display = c ? '' : 'none'; }
    }

    function post(action, data) {
        var body = new FormData();
        body.append('action', action);
        Object.keys(data).forEach(function (k) {
            if (Array.isArray(data[k])) {
                data[k].forEach(function (v) { body.append(k + '[]', v); });
            } else {
                body.append(k, data[k]);
            }
        });
        // REST first (rarely blocked); admin-ajax fallback (some WAFs / CookieYes
        // conflicts 403 one or the other).
        function via(url, isRest) {
            // Cookie auth on the REST API requires X-WP-Nonce; without it WordPress
            // sets the current user to 0 even with a valid login cookie, so checkout
            // would never be linked to the customer (and "require sign-in" would
            // bounce people who are signed in).
            var headers = {};
            if (isRest && window.stripeShopLiteAjax && stripeShopLiteAjax.nonce) headers['X-WP-Nonce'] = stripeShopLiteAjax.nonce;
            return fetch(url, { method: 'POST', credentials: 'same-origin', headers: headers, body: body }).then(function (r) {
                if (!r.ok) throw new Error(r.status);
                return r.json();
            });
        }
        var rest = (window.stripeShopLiteAjax && stripeShopLiteAjax.restUrl) || null;
        var ajax = (window.stripeShopLiteAjax && stripeShopLiteAjax.url) || null;
        var p = rest ? via(rest, true).catch(function () { return ajax ? via(ajax, false) : Promise.reject(); }) : via(ajax, false);
        return p.catch(function () { return { success: false, data: { message: 'Connection blocked. Please refresh and try again.' } }; });
    }

    function lineProductId(key) {
        var m = String(key).match(/^(\d+)(?::price_[A-Za-z0-9_]+)?$/);
        return m ? m[1] : String(key);
    }

    // Response: { items: { lineKey: productInfo }, crosssell: [ suggestions ] }
    function fetchInfo(lines, cb) {
        var ids = lines.map(lineProductId);
        post('stripe_shop_lite_cart_items', { ids: ids, lines: JSON.stringify(lines.reduce(function (o, key) { o[key] = 1; return o; }, {})) }).then(function (res) {
            var d = res && res.success ? res.data : {};
            cb(d.items || {}, d.crosssell || []);
        });
    }

    // Cross-sell renders as a Bootstrap grid: the .col wrappers carry the
    // Bootstrap classes and the <a> inside stays plain, so gutters and
    // columns never tangle with link styling. 4 across from md up, 1 per
    // row below.
    function crosssellHtml(list) {
        if (!list || !list.length) return ''; // only when suggestions exist
        var html = '<p class="stripe-shop-lite-crosssell-title">You might also like</p>' +
            '<div class="stripe-shop-lite-crosssell row row-cols-1 row-cols-md-4 g-4">';
        list.forEach(function (p) {
            var img = '<img src="' + (p.thumb || PH) + '" alt="">';
            html += '<div class="col"><a class="stripe-shop-lite-add stripe-shop-lite-crosssell-item" href="' + (p.url || '#') + '" data-id="' + p.id + '">' + img +
                '<span class="stripe-shop-lite-crosssell-name">' + (p.title || '') + '</span>' +
                (p.price ? '<em>' + p.price + (p.per || '') + '</em>' : '') +
                '<span class="stripe-shop-lite-crosssell-add">Add</span></a></div>';
        });
        return html + '</div>';
    }

    function formatMoney(cents) {
        return '$' + (cents / 100).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    }

    /* --- Loaders --- */

    // Plain CSS spinner (see store.css). Used while cart info is fetched.
    function loaderHtml() {
        return '<div class="stripe-shop-lite-loader" role="status" aria-label="Loading">Loading...</div>';
    }

    /* --- Cart drawer --- */

    // With Bootstrap ON the drawer is a real BS5 offcanvas (bootstrap.Offcanvas
    // handles slide, overlay, backdrop click, ESC and body scroll lock); the
    // hand-rolled panel below is only the fallback for Bootstrap OFF.
    var HAS_BS = document.body.classList.contains('stripe-shop-lite-has-bs');
    var drawer, overlay;

    function ensureDrawer() {
        if (drawer) return;
        overlay = document.createElement('div');
        overlay.className = 'stripe-shop-lite-overlay';
        overlay.addEventListener('click', closeDrawer);

        drawer = document.createElement('aside');
        drawer.className = HAS_BS ? 'offcanvas offcanvas-end stripe-shop-lite-drawer' : 'stripe-shop-lite-drawer';
        drawer.tabIndex = -1;
        drawer.setAttribute('aria-labelledby', 'stripe-shop-lite-drawer-title');
        var closeBtn = HAS_BS
            ? '<button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button>'
            : '<button class="stripe-shop-lite-drawer-close" aria-label="Close">&times;</button>';
        drawer.innerHTML =
            '<header class="' + (HAS_BS ? 'offcanvas-header' : 'stripe-shop-lite-drawer-head') + '"><span class="stripe-shop-lite-drawer-title' + (HAS_BS ? ' offcanvas-title' : '') + '" id="stripe-shop-lite-drawer-title">' + BAG_SVG + ' Cart</span>' +
            '<span class="stripe-shop-lite-drawer-badge" style="display:none"></span>' + closeBtn + '</header>' +
            '<div class="stripe-shop-lite-drawer-total" hidden></div>' +
            '<div class="stripe-shop-lite-drawer-body' + (HAS_BS ? ' offcanvas-body' : '') + '"></div>' +
            '<footer class="stripe-shop-lite-drawer-foot"><button class="stripe-shop-lite-checkout-btn stripe-shop-lite-drawer-checkout">Checkout with Stripe</button>' +
            '<button type="button" class="stripe-shop-lite-drawer-clear stripe-shop-lite-clear-cart">Clear cart</button>' +
            '<a class="stripe-shop-lite-drawer-cartpage" href="' + CART_PAGE + '">View cart page</a></footer>';
        if (!HAS_BS) {
            drawer.querySelector('.stripe-shop-lite-drawer-close').addEventListener('click', closeDrawer);
            document.body.appendChild(overlay);
        }
        document.body.appendChild(drawer);

        var css = document.createElement('style');
        var panelCss = HAS_BS ? '' :
            '.stripe-shop-lite-overlay{position:fixed;inset:0;background:rgba(0,0,0,.45);opacity:0;pointer-events:none;transition:opacity .25s;z-index:99998}' +
            '.stripe-shop-lite-overlay.stripe-shop-lite-open{opacity:1;pointer-events:auto}' +
            '.stripe-shop-lite-drawer{position:fixed;top:0;right:-380px;width:340px;max-width:92vw;height:100%;background:#fff;z-index:99999;display:flex;flex-direction:column;transition:right .25s ease;box-shadow:-4px 0 20px rgba(0,0,0,.15)}' +
            '.stripe-shop-lite-drawer.stripe-shop-lite-open{right:0}' +
            '.stripe-shop-lite-drawer header{display:flex;align-items:center;gap:8px;padding:14px 16px;border-bottom:1px solid #eee}' +
            '.stripe-shop-lite-drawer-title{font-weight:600;font-size:16px}' +
            '.stripe-shop-lite-drawer-body{flex:1;overflow:auto;padding:16px}';
        css.textContent = panelCss +
            '.stripe-shop-lite-drawer *{box-sizing:border-box}' +
            '.stripe-shop-lite-drawer-badge{background:#111;color:#fff;border-radius:999px;min-width:22px;height:22px;display:inline-flex;align-items:center;justify-content:center;font-size:12px;padding:0 6px}' +
            '.stripe-shop-lite-drawer-close{margin-left:auto;background:none;border:none;font-size:24px;cursor:pointer;line-height:1}' +
            '.stripe-shop-lite-drawer-body .stripe-shop-lite-empty{color:#777}' +
            '.stripe-shop-lite-line{display:flex;align-items:center;gap:10px;padding:10px 0;border-bottom:1px solid #f0f0f0}' +
            '.stripe-shop-lite-line-thumb{width:46px;height:46px;object-fit:cover;background:#f5f5f5;flex:none}' +
            '.stripe-shop-lite-line-title{font-size:14px;font-weight:500;text-decoration:none;color:inherit;flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}' +
            '.stripe-shop-lite-line-qty{font-size:13px;color:#555}' +
            '.stripe-shop-lite-line-price{font-size:13px;font-weight:600;white-space:nowrap}' +
            '.stripe-shop-lite-remove{background:none;border:none;color:#c00;cursor:pointer;font-size:12px;text-decoration:underline;flex:none}' +
            '.stripe-shop-lite-checkout-btn{background:#635bff;color:#fff;border:none;border-radius:6px;padding:12px;font-size:15px;font-weight:600;cursor:pointer}' +
            '.stripe-shop-lite-checkout-btn:disabled{opacity:.6;cursor:wait}' +
            '.stripe-shop-lite-drawer-cartpage{font-size:13px;text-align:center;color:#555}' +
            '.stripe-shop-lite-drawer-clear{background:none;border:none;color:#c00;cursor:pointer;font-size:12px;text-decoration:underline;margin-top:6px}' +
            '.stripe-shop-lite-crosssell-title{font-size:13px;font-weight:600;margin:14px 0 8px}' +
            '.stripe-shop-lite-crosssell-item{display:flex;align-items:center;gap:10px;text-decoration:none;color:inherit;background:#fff;border:1px solid #ececec;border-radius:8px;padding:8px}' +
            '.stripe-shop-lite-crosssell-item:hover{border-color:#635bff;text-decoration:none}' +
            '.stripe-shop-lite-crosssell-item img{width:44px;height:44px;object-fit:cover;border-radius:6px;background:#f5f5f5;flex:none}' +
            '.stripe-shop-lite-crosssell-name{flex:1;min-width:0;font-size:13px;font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}' +
            '.stripe-shop-lite-crosssell-item em{font-style:normal;color:#555;font-size:12px;flex:none}' +
            '.stripe-shop-lite-crosssell-add{flex:none;background:#635bff;color:#fff;font-size:12px;font-weight:600;padding:4px 10px;border-radius:999px}' +
            /* dark mode: drawer, cross-sell, totals */
            'html[data-bs-theme="dark"] .stripe-shop-lite-drawer{background:#1e1e1e;color:#fff}' +
            'html[data-bs-theme="dark"] .stripe-shop-lite-drawer-title{color:#fff}' +
            'html[data-bs-theme="dark"] .stripe-shop-lite-line{border-bottom-color:#333}' +
            'html[data-bs-theme="dark"] .stripe-shop-lite-line-title{color:#fff}' +
            'html[data-bs-theme="dark"] .stripe-shop-lite-line-qty,html[data-bs-theme="dark"] .stripe-shop-lite-line-price{color:#bbb}' +
            'html[data-bs-theme="dark"] .stripe-shop-lite-drawer-cartpage{color:#bbb}' +
            'html[data-bs-theme="dark"] .stripe-shop-lite-crosssell-item{background:#1e1e1e;border-color:#333}' +
            'html[data-bs-theme="dark"] .stripe-shop-lite-crosssell-name{color:#fff}' +
            'html[data-bs-theme="dark"] .stripe-shop-lite-crosssell-item em{color:#aaa}' +
            'html[data-bs-theme="dark"] .stripe-shop-lite-drawer-total{background:#161616;color:#fff;border-bottom-color:#333}' +
            /* drawer: bag icon alignment + visible subtotal line */
            '.stripe-shop-lite-drawer-title svg{vertical-align:-3px;margin-right:6px}' +
            '.stripe-shop-lite-drawer-total{padding:12px 16px;font-weight:600;border-bottom:1px solid #eee;font-size:14px}' +
            /* quick view: simple iframe popup of the product page */
            '.stripe-shop-lite-quickview{position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:100000;display:flex;align-items:center;justify-content:center;opacity:0;transition:opacity .2s;padding:20px}' +
            '.stripe-shop-lite-quickview.stripe-shop-lite-open{opacity:1}' +
            '.stripe-shop-lite-quickview-panel{position:relative;width:min(1000px,94vw);height:min(720px,88vh);background:#fff;border-radius:8px;overflow:hidden}' +
            '.stripe-shop-lite-quickview-panel iframe{width:100%;height:100%;border:0;background:#fff}' +
            '.stripe-shop-lite-quickview-close{position:absolute;top:8px;right:10px;background:rgba(0,0,0,.55);color:#fff;border:none;font-size:22px;line-height:1;border-radius:50%;width:34px;height:34px;cursor:pointer;z-index:1}' +
            'html[data-bs-theme="dark"] .stripe-shop-lite-quickview-panel{background:#1e1e1e}';
        document.head.appendChild(css);
    }

    function openDrawer() {
        ensureDrawer(); renderDrawer();
        if (HAS_BS && window.bootstrap && bootstrap.Offcanvas) { bootstrap.Offcanvas.getOrCreateInstance(drawer).show(); return; }
        overlay.classList.add('stripe-shop-lite-open'); drawer.classList.add('stripe-shop-lite-open'); document.body.style.overflow = 'hidden';
    }
    function closeDrawer() {
        if (!drawer) return;
        if (HAS_BS && window.bootstrap && bootstrap.Offcanvas) { bootstrap.Offcanvas.getOrCreateInstance(drawer).hide(); return; }
        overlay.classList.remove('stripe-shop-lite-open'); drawer.classList.remove('stripe-shop-lite-open'); document.body.style.overflow = '';
    }

    /* --- Quick View (simple iframe popup) ---
       Opens the product page in a modal overlay iframe - dark-mode aware,
       closes on the X, on a backdrop click and on Esc. */
    function closeQuickview() {
        var m = document.querySelector('.stripe-shop-lite-quickview');
        if (!m) return;
        m.classList.remove('stripe-shop-lite-open');
        setTimeout(function () { m.remove(); }, 200);
    }
    function openQuickview(url) {
        if (!url) return;
        closeQuickview();
        var wrap = document.createElement('div');
        wrap.className = 'stripe-shop-lite-quickview';
        wrap.innerHTML =
            '<div class="stripe-shop-lite-quickview-panel">' +
            '<button type="button" class="stripe-shop-lite-quickview-close" aria-label="Close quick view">&times;</button>' +
            '<iframe src="' + url + '" title="Quick view" loading="lazy"></iframe>' +
            '</div>';
        document.body.appendChild(wrap);
        wrap.querySelector('.stripe-shop-lite-quickview-close').addEventListener('click', closeQuickview);
        wrap.addEventListener('click', function (e) { if (e.target === wrap) closeQuickview(); });
        requestAnimationFrame(function () { wrap.classList.add('stripe-shop-lite-open'); });
    }

    function lineHtml(p, qty, id) {
        p = p || {};
        var img = '<img class="stripe-shop-lite-line-thumb" src="' + (p.thumb || PH) + '" alt="">';
        return '<div class="stripe-shop-lite-line">' + img +
            '<a class="stripe-shop-lite-line-title" href="' + (p.url || '#') + '">' + (p.title || 'Product') + '</a>' +
            '<span class="stripe-shop-lite-line-qty">&times;' + qty + '</span>' +
            (p.price ? '<span class="stripe-shop-lite-line-price">' + p.price + (p.per || '') + '</span>' : '') +
            '<button class="stripe-shop-lite-remove" data-id="' + id + '">Remove</button></div>';
    }

    function sumTotal(cart, info) {
        var total = 0, any = false;
        Object.keys(cart).forEach(function (id) {
            if (info[id] && info[id].cents) { total += info[id].cents * cart[id]; any = true; }
        });
        return any ? total : null;
    }

    function renderDrawer() {
        ensureDrawer();
        var cart = read();
        var ids = Object.keys(cart);
        var body = drawer.querySelector('.stripe-shop-lite-drawer-body');
        var btn = drawer.querySelector('.stripe-shop-lite-drawer-checkout');
        var totalEl = drawer.querySelector('.stripe-shop-lite-drawer-total');
        updateBadges();
        if (!ids.length) {
            body.innerHTML = '<p class="stripe-shop-lite-empty">Your cart is empty.</p>';
            btn.disabled = true;
            totalEl.hidden = true;
            return;
        }
        btn.disabled = false;
        body.innerHTML = loaderHtml();
        fetchInfo(ids, function (info, crosssell) {
            body.innerHTML = '';
            ids.forEach(function (id) {
                body.insertAdjacentHTML('beforeend', lineHtml(info[id], cart[id], id));
            });
            var cross = crosssellHtml(crosssell);
            if (cross) body.insertAdjacentHTML('beforeend', cross);
            var total = sumTotal(cart, info);
            totalEl.hidden = total === null;
            if (total !== null) totalEl.textContent = 'Subtotal: ' + formatMoney(total);
        });
    }

    /* --- Cart page --- */

    function renderCartPage(root) {
        var cart = read();
        root.innerHTML = '';
        if (!Object.keys(cart).length) {
            root.innerHTML = '<p class="stripe-shop-lite-cart-page-empty">Your cart is empty. <a href="' + CART_PAGE + '">Continue shopping</a></p>';
            return;
        }
        root.innerHTML = loaderHtml();
        fetchInfo(Object.keys(cart), function (info, crosssell) {
            // Bootstrap 5 table (converted from the classic BS3 cart demo:
            // media -> d-flex, pull-left -> me-3, col-md-offset -> offset-md-*).
            var rows = '';
            Object.keys(cart).forEach(function (id) {
                var p = info[id] || {};
                var img = '<img src="' + (p.thumb || PH) + '" alt="" width="72" height="72" class="stripe-shop-lite-cart-thumb">';
                var unit = p.cents ? formatMoney(p.cents) : '';
                var line = p.cents ? formatMoney(p.cents * cart[id]) : '';
                rows += '<tr data-line="' + id + '">' +
                    '<td class="stripe-shop-lite-cart-product">' +
                        '<div class="d-flex align-items-center gap-3">' + img +
                            '<div><a href="' + (p.url || '#') + '" class="stripe-shop-lite-cart-title">' + (p.title || 'Product') + '</a>' +
                            (p.per ? '<div class="stripe-shop-lite-cart-per">' + p.per + ' billing</div>' : '') + '</div>' +
                        '</div></td>' +
                    '<td class="stripe-shop-lite-cart-qty"><div class="stripe-shop-lite-qty-group input-group input-group-sm">' +
                        '<button type="button" class="btn btn-outline-secondary stripe-shop-lite-qty-minus" data-id="' + id + '">&minus;</button>' +
                        '<input type="text" class="form-control stripe-shop-lite-qty-value" value="' + cart[id] + '" readonly>' +
                        '<button type="button" class="btn btn-outline-secondary stripe-shop-lite-qty-plus" data-id="' + id + '">+</button>' +
                    '</div></td>' +
                    '<td class="stripe-shop-lite-cart-unit text-center"><strong>' + unit + '</strong></td>' +
                    '<td class="stripe-shop-lite-cart-line text-center"><strong>' + line + '</strong></td>' +
                    '<td class="stripe-shop-lite-cart-remove"><button type="button" class="btn btn-danger btn-sm stripe-shop-lite-remove" data-id="' + id + '">Remove</button></td>' +
                '</tr>';
            });
            var total = sumTotal(cart, info);
            root.innerHTML =
                '<div class="table-responsive"><table class="table stripe-shop-lite-cart-table table-hover align-middle">' +
                '<thead><tr><th>Product</th><th>Quantity</th><th class="text-center">Price</th><th class="text-center">Total</th><th></th></tr></thead>' +
                '<tbody>' + rows +
                    (total !== null
                        ? '<tr class="stripe-shop-lite-cart-subtotal-row"><td colspan="3"></td><td class="text-end"><strong>Subtotal</strong></td><td class="text-end"><strong>' + formatMoney(total) + '</strong></td></tr>' +
                          '<tr><td colspan="3"></td><td class="text-end" colspan="2"><div class="d-flex justify-content-end gap-2 flex-wrap"><button type="button" class="btn btn-outline-danger stripe-shop-lite-clear-cart">Clear cart</button><button type="button" class="btn stripe-shop-lite-checkout-btn stripe-shop-lite-cart-page-checkout">Checkout with Stripe</button></div></td></tr>'
                        : '') +
                '</tbody></table></div>' + crosssellHtml(crosssell);
        });
    }

    /* --- Event handling --- */

    document.addEventListener('DOMContentLoaded', function () {
        updateBadges();
        document.querySelectorAll('[data-stripe-shop-lite-cart]').forEach(renderCartPage);

        // Infinite-scroll grids (pagination mode from Store -> Settings -> Extra).
        var inf = document.querySelector('.stripe-shop-lite-products[data-pagination="infinite"]');
        if (inf && 'IntersectionObserver' in window) {
            var page = 2, busy = false, done = false;
            var sentinel = document.createElement('div');
            sentinel.className = 'stripe-shop-lite-infinite-sentinel';
            inf.parentNode.insertBefore(sentinel, inf.nextSibling);
            var io = new IntersectionObserver(function (entries) {
                if (!entries[0].isIntersecting || busy || done) return;
                busy = true;
                sentinel.innerHTML = '<div class="stripe-shop-lite-loader" role="status" aria-label="Loading">Loading...</div>';
                fetch(location.pathname.replace(/\/?$/, '/') + 'page/' + page + '/', { credentials: 'same-origin' })
                    .then(function (r) { return r.text(); })
                    .then(function (html) {
                        var doc = new DOMParser().parseFromString(html, 'text/html');
                        var items = doc.querySelectorAll('.stripe-shop-lite-products > .col');
                        if (!items.length) { done = true; return; }
                        items.forEach(function (el) { inf.appendChild(el); });
                        sentinel.innerHTML = '';
                        page++; busy = false;
                    })
                    .catch(function () { sentinel.innerHTML = ''; done = true; });
            }, { rootMargin: '400px' });
            io.observe(sentinel);
        }

        // Success page: empty the cart.
        if (/session_id=/.test(location.search)) {
            localStorage.removeItem(KEY);
            updateBadges();
            // Also zero the server-side mirror for logged-in users, so the
            // next checkout can never bill stale lines.
            if (window.stripeShopLiteAjax && stripeShopLiteAjax.loggedIn) {
                post('stripe_shop_lite_cart_set', { cart: '{}' });
            }
        }
    });

    document.addEventListener('click', function (e) {
        var t = e.target.closest ? e.target.closest('button, a, img.stripe-shop-lite-single-thumb') : null;
        if (!t) return;

        // "Complete my profile" (my-account.php): a data-bs-toggle outside the
        // tab list does nothing in Bootstrap 5.3, so the tab is switched here.
        if (t.hasAttribute('data-store-show-tab') && window.bootstrap) {
            e.preventDefault();
            bootstrap.Tab.getOrCreateInstance(document.getElementById(t.getAttribute('data-store-show-tab'))).show();
        }

        if (t.classList.contains('stripe-shop-lite-add')) {
            e.preventDefault();
            var cart = read();
            var id = t.getAttribute('data-id');
            var priceId = t.getAttribute('data-price-id') || '';
            var lineKey = priceId ? id + ':' + priceId : id;
            var qty = 1;
            var actionWrap = t.closest('.stripe-shop-lite-product-actions');
            var qtyInput = actionWrap && actionWrap.querySelector('.stripe-shop-lite-product-qty');
            if (qtyInput) qty = Math.max(1, parseInt(qtyInput.value, 10) || 1);
            cart[lineKey] = (cart[lineKey] || 0) + qty;
            write(cart);
            openDrawer();
            // Cross-sell rows keep their content; flash the Add pill instead
            // of replacing the button text like plain add buttons do.
            if (t.classList.contains('stripe-shop-lite-crosssell-item')) {
                var pill = t.querySelector('.stripe-shop-lite-crosssell-add');
                if (pill) {
                    pill.dataset.text = pill.textContent;
                    pill.textContent = 'Added';
                    clearTimeout(pill._t);
                    pill._t = setTimeout(function () { pill.textContent = pill.dataset.text; }, 1600);
                }
            } else {
                t.textContent = 'Added';
            }
        }


        if (t.classList.contains('lsm-variant-option')) {
            e.preventDefault();
            // Resolve the picker for any product-detail markup generation:
            // data-variants/data-product-id may sit on the [data-variant-picker]
            // column wrapper, the inner .lsm-variant-picker div, or both. Match
            // whichever ancestor is nearest and, if it does not carry the JSON,
            // climb to the nearest one that does.
            var picker = t.closest('[data-variant-picker], .lsm-variant-picker');
            if (picker && !picker.getAttribute('data-variants')) {
                var withVariants = picker.closest('[data-variants]');
                if (withVariants) picker = withVariants;
            }
            if (!picker) return;
            picker.querySelectorAll('.lsm-variant-option[data-attribute="' + t.getAttribute('data-attribute') + '"]').forEach(function (el) {
                el.classList.remove('sel');
                el.setAttribute('aria-pressed', 'false');
            });
            t.classList.add('sel');
            t.setAttribute('aria-pressed', 'true');

            var selected = {};
            picker.querySelectorAll('.lsm-variant-option.sel').forEach(function (el) {
                selected[el.getAttribute('data-attribute')] = el.getAttribute('data-value');
            });
            picker.querySelectorAll('.lsm-selected-value[data-attribute-label]').forEach(function (el) {
                var key = el.getAttribute('data-attribute-label');
                if (selected[key]) el.textContent = selected[key];
            });

            // Color gallery swap (attribute-value level). When a color is
            // selected and the template carries data-color-images, the main
            // gallery image (carousel first slide and its thumbnail, or the
            // single image) swaps to that color's mapped image; a color with
            // no mapping restores the original gallery picture. The
            // spotlight zoom source is kept in sync.
            if (selected.color) {
                var colorImages = {};
                try { colorImages = JSON.parse(picker.getAttribute('data-color-images') || '{}'); } catch (err) { colorImages = {}; }
                var gallery = document.querySelector('.stripe-shop-lite-single-gallery');
                if (gallery) {
                    var swapUrl = colorImages[selected.color] || '';
                    [gallery.querySelector('.carousel-item .zoom-img'),
                     gallery.querySelector('.carousel-thumbnails button img'),
                     gallery.querySelector('.stripe-shop-lite-single-main-img')].forEach(function (img) {
                        if (!img) return;
                        if (!img.dataset.origSrc) img.dataset.origSrc = img.src;
                        img.src = swapUrl || img.dataset.origSrc;
                    });
                    var spot = gallery.querySelector('.carousel-item .spotlight') || gallery.querySelector('.spotlight');
                    if (spot) {
                        if (!spot.dataset.origSrc) spot.dataset.origSrc = spot.getAttribute('data-src') || '';
                        spot.setAttribute('data-src', swapUrl || spot.dataset.origSrc);
                    }
                }
            }

            var variants = [];
            try { variants = JSON.parse(picker.getAttribute('data-variants') || '[]'); } catch (err) { variants = []; }
            var match = variants.find(function (v) {
                var attrs = v.attributes || {};
                return Object.keys(selected).every(function (key) { return String(attrs[key] || '') === String(selected[key]); });
            });
            // productId: the resolved picker may be the inner div, which in
            // some template versions lacks data-product-id - climb if needed.
            var idHost = picker.hasAttribute('data-product-id') ? picker : picker.closest('[data-product-id]');
            var productId = idHost ? idHost.getAttribute('data-product-id') : '';
            var priceEl = document.getElementById('stripe-shop-lite-product-price-' + productId);
            var stockEl = document.getElementById('stripe-shop-lite-product-stock-' + productId);
            var addBtn = picker.querySelector('.stripe-shop-lite-add') || document.querySelector('.stripe-shop-lite-product-page [data-id="' + productId + '"].stripe-shop-lite-add');
            if (!match) {
                if (priceEl) priceEl.innerHTML = 'Select an available option';
                if (addBtn) { addBtn.disabled = true; addBtn.removeAttribute('data-price-id'); }
                if (stockEl) stockEl.textContent = '';
                return;
            }
            var cents = parseInt(match.unit, 10) || 0;
            var currency = String(match.currency || 'USD').toUpperCase();
            var money = cents ? new Intl.NumberFormat(undefined, { style: 'currency', currency: currency }).format(cents / 100) : 'Price shown at checkout';
            if (priceEl) {
                priceEl.setAttribute('data-price-id', match.price_id || '');
                priceEl.innerHTML = money + (match.recurring ? '<span class="stripe-shop-lite-recurring">/mo</span>' : '');
            }
            var stock = picker.getAttribute('data-product-stock') || '';
            if (stockEl) stockEl.textContent = stock === '' ? '' : (parseInt(stock, 10) > 0 ? parseInt(stock, 10) + ' in stock' : 'Sold out');
            if (addBtn) {
                addBtn.setAttribute('data-price-id', match.price_id || '');
                addBtn.disabled = stock !== '' && parseInt(stock, 10) <= 0;
                addBtn.textContent = addBtn.disabled ? 'Sold out' : 'Add to cart';
            }
        }

        if (t.classList.contains('stripe-shop-lite-detail-qty-plus') || t.classList.contains('stripe-shop-lite-detail-qty-minus')) {
            e.preventDefault();
            var detailWrap = t.closest('.stripe-shop-lite-detail-qty');
            var detailInput = detailWrap && detailWrap.querySelector('.stripe-shop-lite-product-qty');
            if (detailInput) {
                var currentQty = Math.max(1, parseInt(detailInput.value, 10) || 1);
                currentQty += t.classList.contains('stripe-shop-lite-detail-qty-plus') ? 1 : -1;
                detailInput.value = Math.max(1, currentQty);
            }
        }

        if (t.classList.contains('stripe-shop-lite-qty-plus') || t.classList.contains('stripe-shop-lite-qty-minus')) {
            e.preventDefault();
            var qc = read();
            var qid = t.getAttribute('data-id');
            var delta = t.classList.contains('stripe-shop-lite-qty-plus') ? 1 : -1;
            qc[qid] = Math.max(0, (qc[qid] || 0) + delta);
            if (!qc[qid]) delete qc[qid];
            write(qc);
            if (drawer && (drawer.classList.contains('stripe-shop-lite-open') || drawer.classList.contains('show'))) renderDrawer();
            document.querySelectorAll('[data-stripe-shop-lite-cart]').forEach(renderCartPage);
        }

        if (t.classList.contains('stripe-shop-lite-remove')) {
            e.preventDefault();
            var c = read();
            delete c[t.getAttribute('data-id')];
            write(c);
            if (drawer && (drawer.classList.contains('stripe-shop-lite-open') || drawer.classList.contains('show'))) renderDrawer();
            document.querySelectorAll('[data-stripe-shop-lite-cart]').forEach(renderCartPage);
        }

        // "Clear cart" reset button on the cart page (and drawer). Empties
        // localStorage and - for logged-in users - the server-side
        // user-meta mirror, so the next checkout can never bill stale lines.
        if (t.classList.contains('stripe-shop-lite-clear-cart')) {
            e.preventDefault();
            if (!window.confirm('Remove all items from your cart?')) return;
            write({});
            if (drawer && (drawer.classList.contains('stripe-shop-lite-open') || drawer.classList.contains('show'))) renderDrawer();
            document.querySelectorAll('[data-stripe-shop-lite-cart]').forEach(renderCartPage);
        }

        if (t.classList.contains('stripe-shop-lite-checkout-btn')) {
            e.preventDefault();
            t.disabled = true; t.textContent = 'Redirecting...';
            post('stripe_shop_lite_checkout', {
                cart: JSON.stringify(read()),
                // Checkout endpoint nonce; required by the server.
                nonce: (window.stripeShopLiteAjax && stripeShopLiteAjax.checkoutNonce) || ''
            }).then(function (res) {
                if (res.success) { location.href = res.data.checkout_url; return; } // cart is cleared on the success page
                t.disabled = false; t.textContent = 'Checkout with Stripe';
                if (res.data && res.data.signin_required) { location.href = res.data.redirect; return; } // cart survives - it's client-side
                alert((res.data && res.data.message) || 'Checkout failed.');
            });
        }

        if (t.classList.contains('stripe-shop-lite-cart-open')) {   // opens the drawer (modifier clicks open the /cart page in a new tab)
            if (!e.ctrlKey && !e.metaKey && !e.shiftKey) { e.preventDefault(); openDrawer(); }
        }

        if (t.classList.contains('stripe-shop-lite-quickview')) {
            e.preventDefault();
            openQuickview(t.getAttribute('href'));
        }

        if (t.classList.contains('stripe-shop-lite-single-thumb')) {
            e.preventDefault();
            var g = t.closest('.stripe-shop-lite-single-gallery');
            if (g && t.dataset.src) {
                var m = g.querySelector('.stripe-shop-lite-single-main-img');
                if (m) m.src = t.dataset.src;
                g.querySelectorAll('.stripe-shop-lite-single-thumb').forEach(function (el) { el.classList.remove('is-active'); });
                t.classList.add('is-active');
            }
        }

        if (t.classList.contains('stripe-shop-lite-waitlist-open')) {
            var form = t.parentElement.querySelector('.stripe-shop-lite-waitlist-form');
            if (form) { e.preventDefault(); form.dataset.id = t.getAttribute('data-id'); form.hidden = false; }
            // No form next to it (product card): let the link to the product page go through.
        }

        if (t.classList.contains('stripe-shop-lite-waitlist-join')) {
            e.preventDefault();
            var f = t.closest('.stripe-shop-lite-waitlist-form');
            var email = f.querySelector('.stripe-shop-lite-waitlist-email').value;
            post('stripe_shop_lite_waitlist_join', { product_id: f.dataset.id, email: email }).then(function (res) {
                t.textContent = res.success ? 'You are on the list ' : 'Enter a valid email';
            });
        }
    });

    /* ---- Bootstrap absent: tabs + account offcanvas still work (no-op when BS5 is loaded) ---- */
    if (!window.bootstrap) {
        document.querySelectorAll('.stripe-shop-lite-single-tabs .nav-link[data-bs-toggle="tab"]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var wrap = btn.closest('.stripe-shop-lite-single-tabs');
                var target = wrap && document.querySelector(btn.getAttribute('data-bs-target'));
                if (!target) return;
                wrap.querySelectorAll('.nav-link').forEach(function (b) { b.classList.remove('active'); b.setAttribute('aria-selected', 'false'); });
                target.parentElement.querySelectorAll(':scope > .tab-pane').forEach(function (p) { p.classList.remove('active', 'show'); });
                btn.classList.add('active'); btn.setAttribute('aria-selected', 'true');
                target.classList.add('active', 'show');
            });
        });
        document.querySelectorAll('[data-bs-toggle="offcanvas"]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var oc = document.querySelector(btn.getAttribute('data-bs-target'));
                if (!oc) return;
                oc.style.visibility = 'visible';
                oc.classList.add('showing');
                requestAnimationFrame(function () { oc.classList.add('show'); oc.classList.remove('showing'); });
            });
        });
        document.querySelectorAll('.offcanvas [data-bs-dismiss="offcanvas"]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var oc = btn.closest('.offcanvas');
                if (oc) oc.classList.remove('show');
            });
        });
    }

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') { closeDrawer(); closeQuickview(); }
    });
})();
