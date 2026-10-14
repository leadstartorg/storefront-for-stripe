=== Storefront for Stripe ===
Contributors: jessicakafor
Tags: stripe, store, ecommerce, checkout, products
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.1
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Stripe acts as the immutable source of truth for products, pricing, and checkout, while WordPress functions as a catalog.

== Description ==

Storefront for Stripe uses a native core architecture: Stripe acts as the immutable source of truth for products, pricing, and checkout, while WordPress functions as a catalog.

When pricing errors, tax calculation mismatches, or plugin conflicts occur inside WordPress, clients struggle to audit them. Storefront for Stripe allows merchants to manage and audit their product catalog and pricing directly in Stripe, which eliminates ambiguity, reduces liability, and solves deep-seated client-developer trust issues.

The products, prices (checkout and billing), metadata, and subscriptions are managed directly within the Stripe Dashboard (or via Stripe APIs), while the WordPress plugin is used purely for content and layouts and serves as a front-end catalog.

The plugin leverages Stripe `metadata` fields, which can be standardized to handle custom merchandising attributes (e.g., variant attributes and swatches, product type, gift flags).

The plugin offloads core billing and state validation to Stripe, which reduces custom cart/checkout plugin bloat, mitigates database sync drift, and drastically lowers maintenance overhead for developers.

The plugin imports your Stripe catalog as product posts, renders a cache-proof storefront (client cart plus a REST/AJAX mirror for logged-in users) and derives member, customer and donor roles from live Stripe state.

Features:

* Stripe Checkout with Stripe Tax, shipping rates and promotion codes
* Stripe to WordPress product importer: variants with wildcard matching and priority tie-breaks, attribute-value color images, configuration-mistake flagging
* Cache-proof front end built for Cloudflare and aggressive host caching
* Roles derived from live Stripe state: plan_member, plan_subscriber, customer, donor, registered_user
* Product ratings and verified-buyer reviews (buyers-only option)
* Waitlist with restock notifications
* Signature-verified webhook endpoint; stock is deducted when payment completes (never held by an unpaid cart), with an automatic full refund if the last unit sells out during payment

Stripe's terms of service apply to the payment services: https://stripe.com/legal

Product images may be served from your own external URLs (entered by you in Stripe product metadata).

== Installation ==

1. Upload the `storefront-for-stripe` folder to `/wp-content/plugins/` and activate.
2. Enter your Stripe secret key and webhook signing secret in the store settings.
3. Point a Stripe webhook endpoint at `https://example.com/wp-json/storefront-for-stripe/v1/webhook` and subscribe it to:
   checkout.session.completed, checkout.session.async_payment_succeeded, invoice.paid, invoice.payment_failed, charge.refunded, credit_note.created, customer.subscription.* (all subscription events)
   (checkout.session.expired and checkout.session.async_payment_failed may stay subscribed; they are acknowledged and ignored. checkout.session.completed when paid and async_payment_succeeded are what deduct stock.)
4. Import your Stripe catalog from the store admin page.

Stripe metadata convention (the importer never guesses):

`Product metadata:`
`shoplite_attributes = "{Color, Size}"        attribute names`
`color               = "{red, blue}"           values per attribute`
`size                = "{S, M, L}"`
`shoplite_classification = physical | digital | donation`
`shoplite_gift       = 1`

`Price metadata - list only the attributes that constrain the price; unlisted attributes are wildcards:`
`color = "red", size = "M"    one exact combination`
`color = "green"              green in every size`
`shoplite_priority = 10       optional: wins specificity ties`

Metadata keys are case-insensitive (normalized on read); values are case-sensitive and must match on both the product and the price.

== Frequently Asked Questions ==

= Does it require WooCommerce? =

No. WordPress holds the product content; Stripe holds the money and prices. Leftover products from an earlier WooCommerce installation are hidden from the store, and an admin notice offers to move them to drafts. Do not run WooCommerce at the same time: both plugins register the `product` post type.

= Where does the cart live? =

In the visitor's browser (localStorage), mirrored to user meta for logged-in customers. The server rebuilds every line and every price from Stripe at checkout - the browser can never set an amount.

= When is stock deducted? =

When payment completes (checkout.session.completed when paid, checkout.session.async_payment_succeeded for delayed payment methods, or the success page as a fallback) - never when a checkout session is opened, so an abandoned cart holds nothing. If the last unit sells out while a buyer is paying, that payment is refunded in full automatically and both buyer and admin are emailed.

= How do I customize the templates? =

The store templates ship inside the plugin (templates/); theme overrides are optional. To override one, copy it to yourtheme/storefront-for-stripe/; the theme copy wins over the built-in one. dashboard.php and my-account.php receive the $user (WP_User) and $is_staff (bool) variables.

= What happens to my data when I delete the plugin? =

Deleting the plugin removes its options, transients, scheduled events, user meta (Stripe customer links, cart copies, purchase flags), its roles and the product capabilities it gave administrators. Product posts and their meta are your content and are never touched.

== Local Assets ==

The plugin makes no third-party CDN requests. The distribution files for Bootstrap, Font Awesome and Spotlight are not part of the zip: copy the official files into the plugin's assets/vendor/ folder.

    assets/vendor/bootstrap.min.css           (Bootstrap 5.3.3)
    assets/vendor/bootstrap.bundle.min.js    (Bootstrap 5.3.3)
    assets/vendor/font-awesome.min.css       (Font Awesome 4.7.0)
    assets/vendor/fonts/                    (the webfont files the CSS references)
    assets/vendor/spotlight.bundle.js        (Spotlight.js)

An admin notice lists any file that is still missing.

== Bundled libraries ==

* stripe-php 21.3.2 (MIT) - https://github.com/stripe/stripe-php (bundled under vendor/Stripe/)
* Bootstrap 5.3.3 (MIT) - https://getbootstrap.com (local file; copy the distribution into assets/vendor/)
* Font Awesome 4.7.0 (SIL OFL 1.1 + MIT) - https://fontawesome.com/v4/ (local file; copy into assets/vendor/)
* Spotlight.js (Apache-2.0) - https://github.com/nextapps-de/spotlight (local file; copy into assets/vendor/)

== Changelog ==

= 1.0.1 =
* Stock is deducted when payment completes instead of when a Checkout Session is created: checkout only checks availability, an unpaid or abandoned session holds nothing, and a buyer who loses the race for the last unit is refunded in full automatically (buyer and admin are emailed).
* Webhook events are marked processed only after processing, so a failed run is retried by Stripe; stock deduction carries an atomic once-only claim per Checkout Session.
* The expired-session stock restore and the daily reconciliation cron are removed (retired with nothing ever held); the leftover scheduled event is cleared on upgrade.
* Stock is now deducted on checkout.session.completed and checkout.session.async_payment_succeeded; add async_payment_succeeded to the endpoint subscription.
* The store templates now ship inside the plugin (templates/); theme overrides are optional.
* assets/store.css: the owner's real stylesheet ships in the package, updated with the consolidated theme-matching fix list (section 20 of the file: like/save buttons, card hover, theme typography, button radii, gallery thumbnail focus, dark-mode comments and cross-sell, ratings, filter widths).

= 1.0.0 =
* First public release.
