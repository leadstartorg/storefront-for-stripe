<?php
/**
 * Plugin Name: Storefront for Stripe
 * Description: Stripe acts as the immutable source of truth for products, pricing, and checkout, while WordPress functions as a catalog.
 * Version: 1.0.1
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Author: Jessica Kafor
 * Author URI: https://leadstart.org/
 * License: GPLv3 or later
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain: storefront-for-stripe
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// Plugin version. Any change to this string re-versions store.css/store.js
// (?ver= below), so browsers fetch the new files instead of a cached copy.
// Cached HTML pages still need a page-cache purge after a deploy.
define( 'STRIPE_SHOP_LITE_VERSION', '1.0.1' );
define( 'STRIPE_SHOP_LITE_FILE', __FILE__ );
define( 'STRIPE_SHOP_LITE_DIR', plugin_dir_path( __FILE__ ) );

// The Stripe PHP library is required for anything that talks to Stripe
// (checkout, webhooks, role derivation, import). A silent require means a
// white screen later; a notice tells you what to install.
if ( file_exists( STRIPE_SHOP_LITE_DIR . 'lib/Stripe/init.php' ) ) {
    require_once STRIPE_SHOP_LITE_DIR . 'lib/Stripe/init.php';
}
if ( ! class_exists( '\Stripe\StripeClient' ) ) {
    add_action( 'admin_notices', function () {
        if ( ! current_user_can( 'manage_options' ) ) return;
        echo '<div class="notice notice-error is-dismissible"><p><strong>Storefront for Stripe:</strong> the bundled Stripe library is missing or too old. Install Stripe 21.3.2 (the bundled version) in the plugin folder under <code>lib/Stripe/</code>. Checkout, webhooks and role sync do not work without it.</p></div>';
    } );
}

require_once STRIPE_SHOP_LITE_DIR . 'includes/class-stripe-shop-lite-roles.php';
require_once STRIPE_SHOP_LITE_DIR . 'includes/class-stripe-shop-lite-cpt.php';
require_once STRIPE_SHOP_LITE_DIR . 'includes/class-stripe-shop-lite-stripe.php';
require_once STRIPE_SHOP_LITE_DIR . 'includes/class-stripe-shop-lite-cart.php';
require_once STRIPE_SHOP_LITE_DIR . 'includes/class-stripe-shop-lite-shortcodes.php';
require_once STRIPE_SHOP_LITE_DIR . 'includes/class-stripe-shop-lite-auth0.php';
require_once STRIPE_SHOP_LITE_DIR . 'includes/class-stripe-shop-lite-admin.php';
require_once STRIPE_SHOP_LITE_DIR . 'includes/class-stripe-shop-lite-importer.php';

class Stripe_Shop_Lite {

    public static function init() {
        // Translations: on WordPress.org the platform loads the plugin's
        // translations automatically (since WP 4.6) - no load_plugin_textdomain()
        // call is needed or recommended for .org-hosted plugins.
        register_activation_hook( STRIPE_SHOP_LITE_FILE, [ __CLASS__, 'activate' ] );
        register_deactivation_hook( STRIPE_SHOP_LITE_FILE, [ __CLASS__, 'deactivate' ] );
        add_action( 'plugins_loaded', [ __CLASS__, 'upgrade' ] );

        // Module initialization.
        Stripe_Shop_Lite_Roles::init();
        Stripe_Shop_Lite_CPT::init();
        Stripe_Shop_Lite_Stripe::init();
        Stripe_Shop_Lite_Cart::init();
        Stripe_Shop_Lite_Shortcodes::init();
        Stripe_Shop_Lite_Auth0::init();
        Stripe_Shop_Lite_Admin::init();
        Stripe_Shop_Lite_Importer::init();

        add_action( 'wp_enqueue_scripts', [ __CLASS__, 'assets' ] );
        add_action( 'admin_notices', [ __CLASS__, 'vendor_notice' ] );
        add_filter( 'body_class', [ __CLASS__, 'body_class' ] );
    }

    public static function activate() {
        stripe_shop_lite_sync_roles();

        // Bootstrap 5 is the intended front-end layout system. Default it ON
        // for new installs, but never overwrite an existing admin choice.
        if ( false === get_option( 'stripe_shop_lite_bootstrap_on', false ) ) {
            add_option( 'stripe_shop_lite_bootstrap_on', '1', '', false );
        }

        // Schedule each event only when it is not scheduled yet: every
        // deactivate/reactivate cycle used to add another copy of the daily job.
        if ( ! wp_next_scheduled( 'stripe_shop_lite_daily_role_check' ) ) {
            wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'stripe_shop_lite_daily_role_check' );
        }
        if ( ! wp_next_scheduled( 'stripe_shop_lite_quarantine_scan' ) ) {
            wp_schedule_single_event( time() + 60, 'stripe_shop_lite_quarantine_scan' );
        }
        // The rewrite rules for /products/ and the legacy /product/ must be built AFTER the post type is
        // registered, or every product URL 404s until the next flush.
        Stripe_Shop_Lite_CPT::register();
        flush_rewrite_rules();
    }

    public static function deactivate() {
        wp_clear_scheduled_hook( 'stripe_shop_lite_daily_role_check' );
        wp_clear_scheduled_hook( 'stripe_shop_lite_quarantine_scan' );
        wp_clear_scheduled_hook( 'stripe_shop_lite_reconcile_expired' ); // retired in 1.0.1
        flush_rewrite_rules();
    }

    public static function upgrade() {
        // Also (re)build the rewrite rules once per plugin version change, so an
        // update never needs a manual "flush permalinks" trip to Settings > Permalinks.
        $was = get_option( 'stripe_shop_lite_version' );
        if ( $was !== STRIPE_SHOP_LITE_VERSION ) {
            update_option( 'stripe_shop_lite_version', STRIPE_SHOP_LITE_VERSION, false );
            add_action( 'init', function () { flush_rewrite_rules(); }, 99 );
            // 1.0.1 retired the expired-session reconciliation job: stock is
            // deducted at payment, so nothing is ever held to give back.
            wp_clear_scheduled_hook( 'stripe_shop_lite_reconcile_expired' );
            delete_option( 'stripe_shop_lite_reconcile_last' );
        }
    }

    /**
     * Load the plugin's own template with theme override support:
     * yourtheme/storefront-for-stripe/<name>.php wins over the
     * templates/<name>.php copy inside the plugin.
     */
    public static function load_template( $name, $args = [] ) {
        $override = locate_template( [ 'storefront-for-stripe/' . $name, 'stripe-shop-lite/' . $name ] );
        $path = $override ? $override : STRIPE_SHOP_LITE_DIR . 'templates/' . $name;
        if ( ! is_readable( $path ) ) return '';
        ob_start();
        extract( (array) $args, EXTR_SKIP );
        include $path;
        return ob_get_clean();
    }

    public static function assets() {
        // Bootstrap 5 and Font Awesome are LOCAL files under assets/vendor/ -
        // no external CDN, no third-party request, no consent-banner blocking.
        // Drop the official distribution files in (see README, Local Assets):
        //   assets/vendor/bootstrap.min.css           (Bootstrap 5.3.3)
        //   assets/vendor/bootstrap.bundle.min.js    (Bootstrap 5.3.3)
        //   assets/vendor/font-awesome.min.css       (Font Awesome 4.7.0)
        //   assets/vendor/fonts/                    (the webfont files the CSS references)
        if ( stripe_shop_lite_opt( 'bootstrap_on' ) ) {
            wp_enqueue_style( 'bootstrap5', plugins_url( 'assets/vendor/bootstrap.min.css', STRIPE_SHOP_LITE_FILE ), [], '5.3.3' );
            wp_enqueue_script( 'bootstrap5', plugins_url( 'assets/vendor/bootstrap.bundle.min.js', STRIPE_SHOP_LITE_FILE ), [], '5.3.3', true );
        }
        // Font Awesome loads regardless of the Bootstrap toggle - the card
        // demos use its icons, and it is local now too.
        wp_enqueue_style( 'fontawesome47', plugins_url( 'assets/vendor/font-awesome.min.css', STRIPE_SHOP_LITE_FILE ), [], '4.7.0' );

        // Real, cacheable files. An earlier build pasted the full CSS and JS inline
        // into every page (~80 KB each view, no browser caching).
        $deps = stripe_shop_lite_opt( 'bootstrap_on' ) ? [ 'bootstrap5' ] : [];
        $css_url = plugins_url( 'assets/store.css', STRIPE_SHOP_LITE_FILE );
        $js_url  = plugins_url( 'assets/store.js', STRIPE_SHOP_LITE_FILE );

        wp_enqueue_style( 'stripe-shop-lite-store', $css_url, $deps, STRIPE_SHOP_LITE_VERSION );
        wp_enqueue_script( 'stripe-shop-lite-store', $js_url, $deps, STRIPE_SHOP_LITE_VERSION, true );
        wp_localize_script( 'stripe-shop-lite-store', 'stripeShopLiteAjax', [
            'url'       => admin_url( 'admin-ajax.php' ),
            'restUrl'   => esc_url_raw( rest_url( 'storefront-for-stripe/v1/cart' ) ),
            'cartPage'  => home_url( stripe_shop_lite_opt( 'cart_page', 'cart' ) ),
            'loginUrl'  => wp_login_url( home_url( stripe_shop_lite_opt( 'cart_page', 'cart' ) ) ),
            'loggedIn'  => is_user_logged_in() ? 1 : 0,
            // Placeholder image for products without a picture (assets/placeholder.svg).
            'placeholder' => plugins_url( 'assets/placeholder.svg', STRIPE_SHOP_LITE_FILE ),
            // WordPress REST cookie auth ignores the login cookie unless the
            // request also carries this nonce - without it the REST path never
            // knows who is logged in (purchases were never linked to accounts).
            'nonce'     => wp_create_nonce( 'wp_rest' ),
            // Nonce for the checkout endpoint (action stripe_shop_lite_store).
            // Verified on every checkout request - the REST nonce above only
            // covers REST cookie authentication.
            'checkoutNonce' => wp_create_nonce( 'stripe_shop_lite_store' ),
        ] );
    }

    // The notice names the vendor files that are missing.
    public static function vendor_notice() {
        $need = [ 'assets/vendor/bootstrap.min.css', 'assets/vendor/bootstrap.bundle.min.js', 'assets/vendor/font-awesome.min.css' ];
        $missing = array_filter( $need, function ( $f ) { return ! is_readable( STRIPE_SHOP_LITE_DIR . $f ); } );
        if ( ! $missing ) return;
        echo '<div class="notice notice-warning is-dismissible"><p><strong>Storefront for Stripe:</strong> local asset' . ( count( $missing ) > 1 ? 's are' : ' is' ) . ' missing: <code>' . implode( '</code>, <code>', array_map( 'esc_html', $missing ) ) . '</code>. Copy the official distribution file' . ( count( $missing ) > 1 ? 's' : '' ) . ' into the plugin folder (see the Local Assets section of the README), or layout and icons will not load.</p></div>';
    }

    // Body class so store.css can provide grid/sidebar fallbacks when Bootstrap is NOT loaded.
    public static function body_class( $classes ) {
        $classes[] = stripe_shop_lite_opt( 'bootstrap_on' ) ? 'stripe-shop-lite-has-bs' : 'stripe-shop-lite-no-bs';
        return $classes;
    }
}

Stripe_Shop_Lite::init();
