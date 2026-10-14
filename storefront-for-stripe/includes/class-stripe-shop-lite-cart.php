<?php
/**
 * 4. Cart - cache-proof client cart + Stripe Checkout Session as the engine.
 *
 * There is NO custom cart database table and no PHP session: WordPress.com
 * caching + Cloudflare serve static HTML. The browser (localStorage) owns
 * the pre-checkout cart (product IDs + quantities only - inert data); the
 * server re-validates and re-prices everything from Stripe at checkout.
 * For logged-in users the cart is mirrored into user meta (_stripe_shop_lite_cart)
 * so it follows the account across devices; guests live in the browser only.
 * 4a. The BS5-style off-canvas drawer itself is rendered by assets/store.js.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class Stripe_Shop_Lite_Cart {

    public static function init() {
        add_action( 'wp_ajax_stripe_shop_lite_cart_items', 'stripe_shop_lite_ajax_cart_items' );
        add_action( 'wp_ajax_nopriv_stripe_shop_lite_cart_items', 'stripe_shop_lite_ajax_cart_items' );
        add_action( 'wp_ajax_stripe_shop_lite_cart_get', 'stripe_shop_lite_ajax_cart_get' );
        add_action( 'wp_ajax_nopriv_stripe_shop_lite_cart_get', 'stripe_shop_lite_ajax_cart_get' );
        add_action( 'wp_ajax_stripe_shop_lite_cart_set', 'stripe_shop_lite_ajax_cart_set' );
        add_action( 'wp_ajax_nopriv_stripe_shop_lite_cart_set', 'stripe_shop_lite_ajax_cart_set' );
        add_action( 'wp_ajax_stripe_shop_lite_waitlist_join', 'stripe_shop_lite_ajax_waitlist_join' );
        add_action( 'wp_ajax_nopriv_stripe_shop_lite_waitlist_join', 'stripe_shop_lite_ajax_waitlist_join' );
        add_action( 'rest_api_init', [ __CLASS__, 'rest_mirror' ] );
        add_shortcode( 'store_cart', [ __CLASS__, 'cart_shortcode' ] );
        add_shortcode( 'custom_sidebar_cart', [ __CLASS__, 'cart_shortcode' ] );
        add_shortcode( 'store_cart_button', [ __CLASS__, 'cart_button_shortcode' ] );
    }

    /* ---------- Cart state ---------- */

    /** Sanitize cart keys: product ID, or product ID:Stripe Price ID for a variant. */
    public static function sanitize( $cart ) {
        if ( ! is_array( $cart ) ) return [];
        $clean = [];
        foreach ( $cart as $key => $qty ) {
            $qty = min( absint( $qty ), 999 );
            if ( ! $qty ) continue;
            $key = sanitize_text_field( (string) $key );
            if ( preg_match( '/^(\d+):(price_[A-Za-z0-9_]+)$/', $key, $m ) ) {
                $clean[ (int) $m[1] . ':' . $m[2] ] = $qty;
            } else {
                $pid = absint( $key );
                if ( $pid ) $clean[ $pid ] = $qty;
            }
        }
        return $clean;
    }

    private static function parse_line_key( $key ) {
        $key = sanitize_text_field( (string) $key );
        if ( preg_match( '/^(\d+):(price_[A-Za-z0-9_]+)$/', $key, $m ) ) {
            return [ 'product_id' => (int) $m[1], 'price_id' => $m[2], 'key' => $m[1] . ':' . $m[2] ];
        }
        $pid = absint( $key );
        return [ 'product_id' => $pid, 'price_id' => '', 'key' => (string) $pid ];
    }

    /** Server cart: user meta when logged in, else empty (guests = client cart). */
    public static function read() {
        if ( is_user_logged_in() ) {
            $cart = get_user_meta( get_current_user_id(), '_stripe_shop_lite_cart', true );
            return is_array( $cart ) ? $cart : [];
        }
        return [];
    }

    public static function store( array $cart ) {
        if ( is_user_logged_in() ) {
            update_user_meta( get_current_user_id(), '_stripe_shop_lite_cart', $cart );
        }
        // Guests: the browser's localStorage cart is authoritative; nothing to store.
    }

    /* ---------- Cross-sell suggestions (only when they exist) ---------- */

    /**
     * Other published, in-stock products sharing a category with the cart
     * contents. Falls back to newest products. Returns [] when there is
     * nothing sensible to suggest - the drawer then renders no row at all.
     */
    public static function crosssell( array $cart_ids, $limit = 4 ) {
        if ( count( $cart_ids ) < 1 ) return [];
        $slugs = [];
        foreach ( wp_get_object_terms( $cart_ids, 'product_category' ) as $t ) $slugs[] = $t->slug;
        $args = [
            'post_type'      => 'product',
            'post_status'    => 'publish',
            'posts_per_page' => $limit,
            'post__not_in'   => $cart_ids,
            'orderby'        => 'date',
            'order'          => 'DESC',
            'meta_query'     => [ [ 'key' => '_from_woocommerce', 'compare' => 'NOT EXISTS' ] ],
        ];
        if ( $slugs ) {
            $args['tax_query'] = [ [ 'taxonomy' => 'product_category', 'field' => 'slug', 'terms' => $slugs ] ];
        }
        $out = [];
        foreach ( get_posts( $args ) as $p ) {
            $price_id = get_post_meta( $p->ID, '_stripe_price_id', true );
            $stock    = get_post_meta( $p->ID, '_stock_level', true );
            if ( ! $price_id || ( $stock !== '' && (int) $stock <= 0 ) ) continue;
            $price = stripe_shop_lite_price_info( $price_id );
            $out[] = [
                'id'    => $p->ID,
                'title' => get_the_title( $p ),
                'url'   => get_permalink( $p ),
                'thumb' => get_the_post_thumbnail_url( $p, 'thumbnail' ) ?: '',
                'price' => $price ? stripe_shop_lite_format_money( $price['unit'], $price['currency'] ) : '',
                'per'   => $price && $price['recurring'] ? '/mo' : '',
            ];
            if ( count( $out ) >= $limit ) break;
        }
        return $out;
    }

    /* ---------- AJAX: product info for the drawer/cart page (server-priced) ---------- */

    public static function ajax_cart_items() {
        $raw_lines = $_POST['lines'] ?? [];
        if ( is_string( $raw_lines ) ) $raw_lines = json_decode( wp_unslash( $raw_lines ), true );
        $lines = is_array( $raw_lines ) ? $raw_lines : [];
        if ( ! $lines ) {
            $ids = array_map( 'intval', (array) ( $_POST['ids'] ?? [] ) );
            foreach ( $ids as $id ) $lines[ (string) $id ] = 1;
        }

        $out = [];
        $cart_product_ids = [];
        foreach ( $lines as $line_key => $qty ) {
            $line = self::parse_line_key( $line_key );
            $id = $line['product_id'];
            if ( ! $id || get_post_type( $id ) !== 'product' || get_post_status( $id ) !== 'publish' ) continue;
            $price_id = $line['price_id'] ?: get_post_meta( $id, '_stripe_price_id', true );
            $variant = $line['price_id'] ? stripe_shop_lite_find_variant( $id, $line['price_id'] ) : null;
            if ( $line['price_id'] && ! $variant ) continue;
            $price = stripe_shop_lite_price_info( $price_id );
            $stock = get_post_meta( $id, '_stock_level', true );
            if ( $variant && isset( $variant['stock'] ) && '' !== (string) $variant['stock'] ) $stock = (string) $variant['stock'];
            $attrs = $variant && ! empty( $variant['attributes'] ) && is_array( $variant['attributes'] ) ? $variant['attributes'] : [];
            $title = get_the_title( $id );
            if ( $attrs ) $title .= ' - ' . implode( ', ', array_map( 'sanitize_text_field', array_values( $attrs ) ) );
            $out[ $line['key'] ] = [
                'title'      => $title,
                'url'        => get_permalink( $id ),
                'thumb'      => get_the_post_thumbnail_url( $id, 'thumbnail' ) ?: '',
                'stock'      => $stock,
                'buyable'    => (bool) $price_id,
                'gift'       => stripe_shop_lite_product_is_gift( $id ),
                'price'      => $price ? stripe_shop_lite_format_money( $price['unit'], $price['currency'] ) : '',
                'cents'      => $price ? (int) $price['unit'] : 0,
                'per'        => $price && $price['recurring'] ? '/mo' : '',
                'product_id' => $id,
                'price_id'   => $price_id,
                'attributes' => $attrs,
            ];
            $cart_product_ids[] = $id;
        }
        $cart_product_ids = array_values( array_unique( $cart_product_ids ) );
        $crosssell = self::crosssell( $cart_product_ids );
        wp_send_json_success( [ 'items' => $out, 'crosssell' => $crosssell ] );
    }

    public static function ajax_cart_get() {
        wp_send_json_success( self::read() );
    }

    public static function ajax_cart_set() {
        $cart = self::sanitize( json_decode( (string) wp_unslash( $_POST['cart'] ?? '{}' ), true ) );
        self::store( $cart );
        wp_send_json_success( $cart );
    }

    public static function ajax_waitlist_join() {
        $id = (int) ( $_POST['product_id'] ?? 0 );
        $email = sanitize_email( wp_unslash( $_POST['email'] ?? '' ) );
        if ( ! $id || ! is_email( $email ) ) wp_send_json_error();
        $wl = get_post_meta( $id, '_stripe_shop_lite_waitlist', true );
        $wl = is_array( $wl ) ? $wl : [];
        if ( ! in_array( $email, $wl, true ) ) $wl[] = $email;
        update_post_meta( $id, '_stripe_shop_lite_waitlist', $wl );
        wp_send_json_success();
    }

    /**
     * REST mirror for the cart ajax actions - some hosts / WAFs (Cloudflare
     * rules, mod_security, CookieYes conflicts) 403 admin-ajax.php. Params
     * are limited to a fixed list and sanitized (never an unfiltered merge into $_POST).
     */
    public static function rest_mirror() {
        // Both namespaces point at the same mirror: store.js posts to the new
        // storefront-for-stripe/v1 URL, and any client still using the
        // legacy stripe-shop-lite/v1 URL keeps working.
        $route = [
            'methods'             => [ 'POST', 'GET' ],
            'permission_callback' => '__return_true',
            'callback'            => function ( WP_REST_Request $request ) {
                $action = sanitize_key( (string) $request->get_param( 'action' ) );
                switch ( $action ) {
                    case 'stripe_shop_lite_cart_items':
                        $raw_ids = $request->get_param( 'ids' );
                        if ( is_string( $raw_ids ) ) $raw_ids = preg_split( '/\s*,\s*/', $raw_ids, -1, PREG_SPLIT_NO_EMPTY );
                        $_POST['ids'] = array_map( 'absint', (array) $raw_ids );
                        $_POST['lines'] = $request->get_param( 'lines' );
                        stripe_shop_lite_ajax_cart_items();
                        break;
                    case 'stripe_shop_lite_checkout':
                        $_POST['cart'] = $request->get_param( 'cart' );
                        stripe_shop_lite_ajax_checkout();
                        break;
                    case 'stripe_shop_lite_waitlist_join':
                        $_POST['product_id'] = absint( $request->get_param( 'product_id' ) );
                        $_POST['email']      = sanitize_email( $request->get_param( 'email' ) );
                        stripe_shop_lite_ajax_waitlist_join();
                        break;
                    case 'stripe_shop_lite_cart_get':
                        stripe_shop_lite_ajax_cart_get();
                        break;
                    case 'stripe_shop_lite_cart_set':
                        $_POST['cart'] = $request->get_param( 'cart' );
                        stripe_shop_lite_ajax_cart_set();
                        break;
                }
                return new WP_REST_Response( [ 'success' => false ], 400 );
            },
        ];
        register_rest_route( 'storefront-for-stripe/v1', '/cart', $route );
        register_rest_route( 'stripe-shop-lite/v1', '/cart', $route );
    }

    /* ---------- Shortcodes ---------- */

    // [store_cart] - placeholder; JS renders from the client cart so it works on fully cached pages.
    public static function cart_shortcode() {
        return '<div class="stripe-shop-lite-cart" data-stripe-shop-lite-cart><p class="stripe-shop-lite-cart-empty" hidden>Your cart is empty.</p><div class="stripe-shop-lite-cart-lines"></div>'
            . '<button class="stripe-shop-lite-checkout-btn" hidden>Checkout with Stripe</button>'
            . '<form class="stripe-shop-lite-waitlist-form" data-id="" hidden><input type="email" class="stripe-shop-lite-waitlist-email" placeholder="you@email.com" /><button type="button" class="stripe-shop-lite-waitlist-join">Notify me</button></form></div>';
    }

    // [store_cart_button] - header cart icon. JS fills the live count; clicking opens the drawer.
    public static function cart_button_shortcode() {
        return '<a href="' . esc_url( home_url( stripe_shop_lite_opt( 'cart_page', 'cart' ) ) ) . '" class="stripe-shop-lite-cart-button stripe-shop-lite-cart-open">Cart (<span class="stripe-shop-lite-cart-count">0</span>)</a>';
    }
}

/* Global prefixed wrappers (used by the REST mirror and other modules). */
function stripe_shop_lite_cart_read()      { return Stripe_Shop_Lite_Cart::read(); }
function stripe_shop_lite_cart_sanitize( $cart ) { return Stripe_Shop_Lite_Cart::sanitize( $cart ); }
function stripe_shop_lite_ajax_cart_items()   { Stripe_Shop_Lite_Cart::ajax_cart_items(); }
function stripe_shop_lite_ajax_cart_get()     { Stripe_Shop_Lite_Cart::ajax_cart_get(); }
function stripe_shop_lite_ajax_cart_set()     { Stripe_Shop_Lite_Cart::ajax_cart_set(); }
function stripe_shop_lite_ajax_waitlist_join(){ Stripe_Shop_Lite_Cart::ajax_waitlist_join(); }
