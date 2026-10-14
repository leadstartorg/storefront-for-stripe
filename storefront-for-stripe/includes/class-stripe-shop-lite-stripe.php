<?php
/**
 * Stripe client wrapper (requires stripe-php 7.33 or later; 21.3.2 is bundled).
 * Covers Stripe Tax, shipping rates and allowed countries, promotion codes,
 * order tracking read live from Stripe, the role engine, the
 * signature-verified webhook endpoint, checkout-session creation, and the
 * atomic stock deduction that runs when payment completes.
 *
 * Also contains the role rename migration (registered_user, donor, customer,
 * plan_subscriber, plan_member): new-role registration, a one-time user
 * migration and cleanup of the retired slugs. All helper layers are
 * integrated within the plugin; no external must-use plugins are required.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class Stripe_Shop_Lite_Stripe {

    public static function init() {
        add_action( 'rest_api_init', [ __CLASS__, 'webhook_route' ] );
        add_action( 'wp_ajax_stripe_shop_lite_checkout', 'stripe_shop_lite_ajax_checkout' );
        add_action( 'wp_ajax_nopriv_stripe_shop_lite_checkout', 'stripe_shop_lite_ajax_checkout' );
        add_action( 'template_redirect', [ __CLASS__, 'confirm_success' ] );
        // Role rename migration (defined at the bottom of this file).
        add_action( 'init', 'stripe_shop_lite_role_rename_init', 999 );
    }

    /**
     * Webhook endpoint. The signature check is the authentication; the
     * endpoint must be subscribed to these events in the Stripe dashboard:
     *   checkout.session.completed, checkout.session.async_payment_succeeded,
     *   invoice.paid, invoice.payment_failed, charge.refunded,
     *   credit_note.created, customer.subscription.*
     * checkout.session.completed (when paid) and async_payment_succeeded are
     * what deduct stock. Expired and failed sessions never held any stock,
     * so checkout.session.expired and async_payment_failed need no handler.
     */
    public static function webhook_route() {
        // Both namespaces point at the same handler: new Stripe webhook
        // endpoints use storefront-for-stripe/v1, and endpoints registered
        // with the legacy stripe-shop-lite/v1 keep working.
        $route = [
            'methods'             => [ 'POST', 'GET' ],
            'callback'            => 'stripe_shop_lite_handle_webhook',
            'permission_callback' => '__return_true',
        ];
        register_rest_route( 'storefront-for-stripe/v1', '/webhook', $route );
        register_rest_route( 'stripe-shop-lite/v1', '/webhook', $route );
    }

    /**
     * Key validation: a one-product list read, which every key can perform.
     */
    public static function validate_key( $key ) {
        if ( ! class_exists( '\Stripe\StripeClient' ) ) {
            return [ 'ok' => false, 'error' => 'The bundled Stripe library is missing or too old: vendor/stripe-php/init.php must be the bundled stripe-php 21.3.2.' ];
        }
        try {
            $client = new \Stripe\StripeClient( $key );
            // A list of one product: a read every store key has. Balance is not
            // in scope for a restricted key, so it must not be the check.
            $client->products->all( [ 'limit' => 1 ] );
            return [ 'ok' => true, 'account' => 'Key verified with Stripe.' ];
        } catch ( \Stripe\Exception\AuthenticationException $e ) {
            return [ 'ok' => false, 'error' => 'Stripe rejected that key: ' . $e->getMessage() ];
        } catch ( \Throwable $e ) {
            return [ 'ok' => false, 'error' => 'Stripe error: ' . $e->getMessage() ];
        }
    }

    public static function ready() {
        return (bool) stripe_shop_lite_opt( 'stripe_key' );
    }

    /** Set the global API key from settings. Returns true when usable. */
    public static function key() {
        $k = stripe_shop_lite_opt( 'stripe_key' );
        if ( ! $k ) return false;
        \Stripe\Stripe::setApiKey( $k );
        // A Stripe slowdown must not stall a page for the stripe-php default
        // of 80 seconds: bound the timeout and allow up to two automatic
        // retries. Both are guarded so very old stripe-php versions still work.
        static $tuned = false;
        if ( ! $tuned ) {
            if ( method_exists( '\Stripe\Stripe', 'setMaxNetworkRetries' ) ) {
                \Stripe\Stripe::setMaxNetworkRetries( 2 );
            }
            if ( class_exists( '\Stripe\HttpClient\CurlClient' ) ) {
                $client = new \Stripe\HttpClient\CurlClient();
                $client->setTimeout( 10 );
                \Stripe\ApiRequestor::setHttpClient( $client );
            }
            $tuned = true;
        }
        return true;
    }

    /* ---------- Role engine - derived from Stripe live state ---------- */

    public static function customer_id( $user_id, $create = false ) {
        $cid = get_user_meta( $user_id, '_stripe_shop_lite_stripe_customer', true );
        if ( $cid || ! $create || ! self::key() ) return $cid;
        $u = get_userdata( $user_id );
        try {
            $c = \Stripe\Customer::create( [ 'email' => $u->user_email, 'metadata' => [ 'wp_user_id' => $user_id ] ] );
            update_user_meta( $user_id, '_stripe_shop_lite_stripe_customer', $c->id );
            return $c->id;
        } catch ( \Exception $e ) { return ''; }
    }

    public static function derive_role( $user_id ) {
        if ( stripe_shop_lite_is_exempt( $user_id ) ) return null;
        $cid = self::customer_id( $user_id );
        if ( ! $cid || ! self::key() ) return 'registered_user';

        $has_sub = false;
        try {
            $subs = \Stripe\Subscription::all( [ 'customer' => $cid, 'limit' => 100 ] );
            foreach ( $subs->data as $s ) {
                if ( in_array( $s->status, [ 'active', 'past_due' ], true ) ) $has_sub = true;
            }
        } catch ( \Exception $e ) { return null; } // Stripe unreachable: change nothing

        $bought_physical = get_user_meta( $user_id, '_stripe_shop_lite_bought_physical', true ); // history: permanent
        $bought_donation = get_user_meta( $user_id, '_stripe_shop_lite_bought_donation', true ); // history: permanent

        if ( $has_sub && $bought_physical ) return 'plan_member';
        if ( $has_sub )                    return 'plan_subscriber';
        if ( $bought_physical )            return 'customer';
        if ( $bought_donation )            return 'donor';
        return 'registered_user'; // registered, no purchases
    }

    /* ---------- Webhook ---------- */

    public static function handle_webhook( WP_REST_Request $request ) {
        if ( 'GET' === $request->get_method() ) return new WP_REST_Response( [ 'ok' => true ] );
        $secret = stripe_shop_lite_opt( 'webhook_secret' );
        if ( ! $secret ) return new WP_Error( 'stripe_shop_lite_no_secret', 'Webhook secret not configured.', [ 'status' => 500 ] );
        try {
            $event = \Stripe\Webhook::constructEvent( $request->get_body(), $request->get_header( 'stripe_signature' ), $secret );
        } catch ( \Exception $e ) {
            return new WP_Error( 'stripe_shop_lite_bad_sig', 'Invalid signature.', [ 'status' => 400 ] );
        }

        // Stripe redelivers any event whose response was not a
        // 2xx, so the same event can arrive more than once. An event that was
        // processed completely is remembered for 72 h (Stripe's retry window)
        // and a repeat is acknowledged without processing. The marker is set
        // only after processing, so a run that stops half-way (timeout, fatal
        // error) is retried by Stripe instead of being skipped. The stock
        // deduction has its own atomic once-only marker per Checkout Session.
        $seen_key = 'stripe_shop_lite_wh_' . md5( (string) $event->id );
        if ( get_transient( $seen_key ) ) return new WP_REST_Response( [ 'received' => true ] );

        $obj = $event->data->object;

        // Paid checkout: deduct the stock now. Card payments arrive paid on
        // checkout.session.completed; delayed methods (bank debits) arrive
        // unpaid there and paid on checkout.session.async_payment_succeeded.
        // Unpaid, expired and failed sessions never held any stock.
        if ( in_array( $event->type, [ 'checkout.session.completed', 'checkout.session.async_payment_succeeded' ], true ) ) {
            self::deduct_paid_session( $obj );
        }

        if ( in_array( $event->type, [ 'checkout.session.completed', 'invoice.paid', 'invoice.payment_failed', 'charge.refunded', 'credit_note.created' ], true )
             || 0 === strpos( $event->type, 'customer.subscription.' ) ) {

            $user_id = 0;
            if ( isset( $obj->metadata->wp_user_id ) ) $user_id = (int) $obj->metadata->wp_user_id;
            if ( ! $user_id && isset( $obj->customer ) ) {
                $found = get_users( [ 'meta_key' => '_stripe_shop_lite_stripe_customer', 'meta_value' => $obj->customer, 'number' => 1 ] );
                if ( $found ) $user_id = $found[0]->ID;
            }
            if ( $user_id ) {
                if ( 'checkout.session.completed' === $event->type ) self::record_purchase( $user_id, $obj );
                stripe_shop_lite_apply_role( $user_id );
            }
        }
        set_transient( $seen_key, '1', 72 * HOUR_IN_SECONDS );
        return new WP_REST_Response( [ 'received' => true ] );
    }

    public static function record_purchase( $user_id, $session ) {
        $classes = array_filter( explode( ',', (string) ( $session->metadata->stripe_shop_lite_classes ?? '' ) ) );
        foreach ( $classes as $c ) {
            if ( 'physical' === $c ) update_user_meta( $user_id, '_stripe_shop_lite_bought_physical', '1' );
            if ( 'donation' === $c ) update_user_meta( $user_id, '_stripe_shop_lite_bought_donation', '1' );
            if ( 'digital' === $c )  update_user_meta( $user_id, '_stripe_shop_lite_bought_digital', '1' );
        }
        // A recurring price (from Stripe, not a field) marks the subscription holder.
        if ( ! empty( $session->metadata->stripe_shop_lite_recurring ) ) {
            update_user_meta( $user_id, '_stripe_shop_lite_has_subscription', '1' );
        }
        // Track owned products for verified-buyer reviews.
        $owned = get_user_meta( $user_id, '_stripe_shop_lite_owned_products', true );
        $owned = is_array( $owned ) ? $owned : [];
        foreach ( (array) ( json_decode( (string) ( $session->metadata->stripe_shop_lite_items ?? '[]' ) ) ) as $pid ) {
            $owned[] = (int) $pid;
        }
        update_user_meta( $user_id, '_stripe_shop_lite_owned_products', array_unique( $owned ) );
        // Stock is deducted separately, by deduct_paid_session().
    }

    public static function notify_waitlist( $post_id ) {
        $emails = get_post_meta( $post_id, '_stripe_shop_lite_waitlist', true );
        if ( ! is_array( $emails ) || ! $emails ) return;
        foreach ( $emails as $email ) {
            wp_mail( $email, 'Back in stock: ' . get_the_title( $post_id ), get_permalink( $post_id ) );
        }
        delete_post_meta( $post_id, '_stripe_shop_lite_waitlist' );
    }

    /* ---------- Price cache ---------- */

    /**
     * The unit amount + currency for a Stripe price, cached for an hour.
     * Prices always come from Stripe, never from the browser.
     */
    public static function price_info( $price_id ) {
        if ( ! $price_id ) return null;
        $cached = get_transient( 'stripe_shop_lite_price_' . md5( $price_id ) );
        if ( false !== $cached ) return $cached;
        if ( ! self::key() ) return null;
        try {
            $p = \Stripe\Price::retrieve( $price_id );
            $info = [
                'unit'      => $p->unit_amount,               // cents
                'currency'  => strtolower( $p->currency ),
                'recurring' => ! empty( $p->recurring ),
                // Customer-defined-amount prices are flagged so checkout can
                // reject them with a clear message instead of a raw Stripe
                // error mid-session.
                'custom'    => ! empty( $p->custom_unit_amount ),
            ];
            set_transient( 'stripe_shop_lite_price_' . md5( $price_id ), $info, HOUR_IN_SECONDS );
            return $info;
        } catch ( \Exception $e ) { return null; }
    }

    /* ---------- Success page: confirm with Stripe, apply role ---------- */

    public static function confirm_success() {
        if ( empty( $_GET['session_id'] ) || ! is_page( trim( stripe_shop_lite_opt( 'success_page', 'checkout-success' ), '/' ) ) ) return;
        if ( ! self::key() ) return;
        try {
            $session = \Stripe\Checkout\Session::retrieve( sanitize_text_field( wp_unslash( $_GET['session_id'] ) ) );
            // Deduct stock as soon as the buyer is back, in case the webhook
            // is late; the once-only marker turns the webhook into a no-op.
            if ( 'complete' === $session->status ) self::deduct_paid_session( $session );
            if ( 'complete' === $session->status && (int) ( $session->metadata->wp_user_id ?? 0 ) ) {
                self::record_purchase( (int) $session->metadata->wp_user_id, $session );
                stripe_shop_lite_apply_role( (int) $session->metadata->wp_user_id );
            }
        } catch ( \Exception $e ) {}
    }

    /* ---------- Stock: atomic compare-and-swap ---------- */

    /**
     * Atomic compare-and-swap on a product's stock field. Returns the new
     * value, false if the change would go negative (or lost every retry),
     * or null when stock isn't tracked for the product.
     */
    public static function stock_cas( $post_id, $delta ) {
        global $wpdb;
        for ( $try = 0; $try < 5; $try++ ) {
            $cur = get_post_meta( $post_id, '_stock_level', true );
            if ( $cur === '' || ! is_numeric( $cur ) ) return null;
            if ( (int) $cur + (int) $delta < 0 ) return false; // not enough stock
            $new = (int) $cur + (int) $delta;
            $rows = $wpdb->query( $wpdb->prepare(
                "UPDATE {$wpdb->postmeta} SET meta_value = %s WHERE post_id = %d AND meta_key = '_stock_level' AND meta_value = %s",
                (string) $new, $post_id, (string) $cur
            ) );
            // The raw UPDATE bypasses the object cache; without this, the next
            // get_post_meta() (and the next CAS attempt) reads the old value.
            wp_cache_delete( $post_id, 'post_meta' );
            if ( 1 === (int) $rows ) {
                // Back in stock from zero (a restock).
                if ( (int) $cur <= 0 && $new > 0 ) self::notify_waitlist( $post_id );
                return $new;
            }
        }
        return false;
    }

    /* ---------- Stock: deducted when payment completes ---------- */

    /**
     * Deducts the stock of one paid Checkout Session, exactly once.
     *
     * Checkout only checks availability, so an unpaid, expired or failed
     * session never holds stock. This runs from the webhook
     * (checkout.session.completed when paid, and
     * checkout.session.async_payment_succeeded) and from the success page;
     * the atomic marker lets the first caller win, so redeliveries and the
     * success page never deduct twice.
     *
     * If another buyer took the last unit first, the lines already deducted
     * for this session are put back, the payment is refunded in full, and
     * the buyer and the site admin are emailed.
     */
    public static function deduct_paid_session( $session ) {
        if ( ! in_array( (string) ( $session->payment_status ?? '' ), [ 'paid', 'no_payment_required' ], true ) ) return;
        $stock = json_decode( (string) ( $session->metadata->stripe_shop_lite_stock ?? '' ), true );
        if ( ! is_array( $stock ) || ! $stock ) return;
        if ( ! self::mark_once( 'stripe_shop_lite_paid_' . md5( (string) $session->id ) ) ) return;

        $taken = [];
        foreach ( $stock as $post_id => $qty ) {
            $new = self::stock_cas( (int) $post_id, -1 * (int) $qty );
            if ( false === $new ) {
                foreach ( $taken as $pid => $q ) self::stock_cas( $pid, $q );
                self::refund_oversold( $session, (int) $post_id );
                return;
            }
            if ( null !== $new ) $taken[ (int) $post_id ] = (int) $qty;
        }
    }

    /**
     * An atomic one-time marker: true only for the first caller with this name.
     * One INSERT IGNORE against the unique option_name key, so two
     * simultaneous deliveries cannot both pass, which a read-then-write
     * transient check allows. The row is a small, non-autoloaded marker.
     */
    private static function mark_once( $name ) {
        global $wpdb;
        $rows = $wpdb->query( $wpdb->prepare(
            "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'off')",
            $name, (string) time()
        ) );
        if ( false === $rows ) {
            // A database error must not pass silently: the paid order's stock
            // was not deducted and needs checking by hand.
            error_log( 'Storefront for Stripe: paid-session marker ' . $name . ' failed: ' . $wpdb->last_error );
        }
        return 1 === (int) $rows;
    }

    /**
     * A paid session lost the race for the last unit: refund it in full and
     * tell the buyer and the site admin. Any other lines in the order were
     * already put back by deduct_paid_session().
     */
    private static function refund_oversold( $session, $post_id ) {
        $title  = get_the_title( $post_id );
        $pi     = $session->payment_intent ?? '';
        $pi     = is_string( $pi ) ? $pi : (string) ( $pi->id ?? '' );
        $refund = '';
        $free   = 'no_payment_required' === (string) ( $session->payment_status ?? '' ); // e.g. a 100% promo code
        if ( $pi && ! $free && self::key() ) {
            try {
                $refund = (string) \Stripe\Refund::create( [
                    'payment_intent' => $pi,
                    'metadata'       => [ 'stripe_shop_lite_reason' => 'sold_out', 'checkout_session' => (string) $session->id ],
                ] )->id;
            } catch ( \Exception $e ) {
                error_log( 'Storefront for Stripe: refund for oversold session ' . $session->id . ' failed: ' . $e->getMessage() );
            }
        }
        $buyer = (string) ( $session->customer_details->email ?? '' );
        if ( $buyer && ( $refund || $free ) ) {
            wp_mail( $buyer, 'Your order could not be completed',
                '"' . $title . '" sold out just before your order went through, so we could not complete it. '
                . ( $free ? 'No payment was taken.' : 'We have refunded your payment in full.' )
                . ' We apologize for the inconvenience.' . "\n\n" . home_url( '/' ) );
        }
        wp_mail( get_option( 'admin_email' ), 'Oversold: ' . $title,
            'Checkout Session ' . $session->id . ' completed after "' . $title . '" (post ' . $post_id . ') sold out. '
            . ( $free ? 'No payment was taken.' : ( $refund ? 'Refunded in full: ' . $refund . '.' : 'The automatic refund FAILED - refund this payment in the Stripe dashboard.' ) )
            . ' Any other items in that order were put back in stock.' );
    }

    /* ---------- Live active subscriber count (staff view) ---------- */

    public static function active_subscriber_count() {
        $cached = get_transient( 'stripe_shop_lite_sub_count' );
        if ( false !== $cached ) return (int) $cached;
        if ( ! self::key() ) return 0;
        $users = get_users( [
            'fields' => [ 'ID' ],
            'role__in' => [ 'plan_subscriber', 'plan_member' ],
        ] );
        $ids = [];
        foreach ( $users as $u ) {
            $cid = self::customer_id( $u->ID );
            if ( ! $cid ) continue;
            try {
                $subs = \Stripe\Subscription::all( [ 'customer' => $cid, 'limit' => 100 ] );
                foreach ( $subs->data as $sub ) {
                    if ( in_array( $sub->status, [ 'active', 'past_due' ], true ) ) {
                        $ids[ (int) $u->ID ] = true;
                        break;
                    }
                }
            } catch ( \Exception $e ) {
                continue;
            }
        }
        $count = count( $ids );
        set_transient( 'stripe_shop_lite_sub_count', $count, 15 * MINUTE_IN_SECONDS );
        return $count;
    }

    /* ---------- Month-to-date gross revenue (staff view) ---------- */

    public static function month_revenue() {
        $cached = get_transient( 'stripe_shop_lite_month_revenue' );
        if ( false !== $cached ) return $cached;
        if ( ! self::key() ) return '-';
        $value = '-';
        try {
            $since = strtotime( 'first day of this month 00:00:00' );
            $sum = 0; $currency = '';
            $charges = \Stripe\Charge::all( [ 'created' => [ 'gte' => $since ], 'limit' => 100 ] );
            foreach ( $charges->data as $ch ) {
                if ( $ch->paid && ! $ch->refunded ) { $sum += $ch->amount; $currency = $ch->currency; }
            }
            $value = $sum ? stripe_shop_lite_format_money( $sum, $currency ?: 'USD' ) : '-';
        } catch ( \Exception $e ) { $value = '-'; }
        set_transient( 'stripe_shop_lite_month_revenue', $value, 15 * MINUTE_IN_SECONDS );
        return $value;
    }
}

/*
 * Global prefixed helpers used across modules and templates.
 */

function stripe_shop_lite_stripe() {
    return Stripe_Shop_Lite_Stripe::key();
}

function stripe_shop_lite_format_money( $cents, $currency ) {
    $symbols = [ 'usd' => '$', 'gbp' => '£', 'eur' => '¬', 'cad' => 'C$' ];
    $s = $symbols[ $currency ] ?? strtoupper( $currency ) . ' ';
    return $s . number_format( $cents / 100, 2 );
}

/** Price label from stored display meta ("25.00" + "USD") - no Stripe call, cache-safe. */
function stripe_shop_lite_money_label( $dp, $currency = 'USD' ) {
    $symbols = [ 'usd' => '$', 'gbp' => '£', 'eur' => '¬', 'cad' => 'C$' ];
    $s = $symbols[ strtolower( (string) $currency ) ] ?? strtoupper( (string) $currency ) . ' ';
    return $s . $dp;
}

function stripe_shop_lite_customer_id( $user_id, $create = false ) {
    return Stripe_Shop_Lite_Stripe::customer_id( $user_id, $create );
}

function stripe_shop_lite_derive_role( $user_id ) {
    return Stripe_Shop_Lite_Stripe::derive_role( $user_id );
}

function stripe_shop_lite_apply_role( $user_id ) {
    $role = Stripe_Shop_Lite_Stripe::derive_role( $user_id );
    if ( ! $role ) return;
    $u = get_userdata( $user_id );
    if ( $u && ! in_array( $role, (array) $u->roles, true ) ) $u->set_role( $role );
    update_user_meta( $user_id, '_stripe_shop_lite_role_checked', time() );
}

/*
 * Role rename migration. The derive_role() ladder returns the renamed slugs:
     stripe_user       -> registered_user
     stripe_donor      -> donor
     stripe_customer   -> customer
     stripe_subscriber -> plan_subscriber   (not WP's "Subscriber")
     stripe_member     -> plan_member
   This runs on init at priority 999 - AFTER the Roles class has
   registered/renumbered anything it wants - and does three things:
     1. registers each new role, copying capabilities from the old
        role ('read' as a safe fallback),
     2. ONE-TIME migration (guarded by an option flag): every user
        holding an old slug gets the new one ADDED and the old one
        REMOVED (add_role + remove_role - other roles like
        Administrator are preserved),
     3. removes old role definitions once they hold no users, and
        keeps doing so on later requests in case the Roles class
        re-adds an old slug with add_role().
 */

if ( ! function_exists( 'stripe_shop_lite_role_rename_map' ) ) {
    function stripe_shop_lite_role_rename_map() {
        // old slug => [ new slug, display name ]
        return [
            'stripe_user'       => [ 'registered_user', 'Registered User' ],
            'stripe_donor'      => [ 'donor',           'Donor'           ],
            'stripe_customer'   => [ 'customer',        'Customer'        ],
            'stripe_subscriber' => [ 'plan_subscriber', 'Plan Subscriber' ],
            'stripe_member'     => [ 'plan_member',     'Plan Member'     ],
        ];
    }
}

if ( ! function_exists( 'stripe_shop_lite_role_rename_init' ) ) {
    function stripe_shop_lite_role_rename_init() {
        $map = stripe_shop_lite_role_rename_map();

        // 1. Make sure every new role exists (copy caps from the old role).
        foreach ( $map as $old => $info ) {
            list( $new, $label ) = $info;
            if ( get_role( $new ) ) continue;
            $old_role = get_role( $old );
            $caps = ( $old_role && ! empty( $old_role->capabilities ) ) ? $old_role->capabilities : [ 'read' => true ];
            add_role( $new, $label, $caps );
        }

        // 2. One-time user migration, then drop the old role definitions.
        if ( ! get_option( 'stripe_shop_lite_role_rename_v1' ) ) {
            foreach ( $map as $old => $info ) {
                list( $new, $label ) = $info;
                $users = get_users( [ 'role' => $old, 'fields' => [ 'ID' ] ] );
                foreach ( $users as $u ) {
                    $wu = new WP_User( $u->ID );
                    $wu->add_role( $new );     // add first - never leaves the
                    $wu->remove_role( $old );  // user without a store role
                }
            }
            update_option( 'stripe_shop_lite_role_rename_v1', 1 );
        }

        // 3. Remove old role definitions once they hold no users.
        foreach ( $map as $old => $info ) {
            if ( ! get_role( $old ) ) continue;
            $remaining = get_users( [ 'role' => $old, 'fields' => [ 'ID' ] ] );
            if ( empty( $remaining ) ) remove_role( $old );
        }
    }
}

function stripe_shop_lite_price_info( $price_id ) {
    return Stripe_Shop_Lite_Stripe::price_info( $price_id );
}

function stripe_shop_lite_stock_cas( $post_id, $delta ) {
    return Stripe_Shop_Lite_Stripe::stock_cas( $post_id, $delta );
}

function stripe_shop_lite_handle_webhook( WP_REST_Request $request ) {
    return Stripe_Shop_Lite_Stripe::handle_webhook( $request );
}

/*
 * Checkout: creates the Stripe Checkout Session (the cart engine).
 * The client cart holds only product IDs and quantities; the server
 * re-validates everything and rebuilds prices from Stripe. Stock for
 * physical items is only checked here; it is deducted when payment
 * completes (deduct_paid_session()), so an unpaid session holds nothing.
 */

function stripe_shop_lite_ajax_checkout() {
    /*
     * The checkout request must carry a fresh nonce: without it a plain POST
     * could create Stripe Checkout Sessions. The nonce
     * ships with the localized script config and the client sends it as a
     * field on both the admin-ajax and the REST dispatch path.
     */
    $nonce = isset( $_REQUEST['nonce'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['nonce'] ) ) : '';
    if ( ! wp_verify_nonce( $nonce, 'stripe_shop_lite_store' ) ) {
        wp_send_json_error( [ 'message' => 'Your session expired. Please refresh the page and try again.' ] );
    }

    /*
     * Rate limit: at most 5 Checkout Session creations per minute, per user
     * (or per IP for guests; Cloudflare's CF-Connecting-IP is preferred when
     * present). The counter only ticks on successful session creation below,
     * so a shopper retrying after an error is never counted.
     */
    $rl_key = 'stripe_shop_lite_checkout_rl_' . ( get_current_user_id()
        ? 'u' . get_current_user_id()
        : 'i' . md5( strtolower( (string) ( $_SERVER['HTTP_CF_CONNECTING_IP'] ?? '' ) ?: ( $_SERVER['REMOTE_ADDR'] ?? '' ) ) ) );
    if ( (int) get_transient( $rl_key ) >= 5 ) {
        wp_send_json_error( [ 'message' => 'Too many checkout attempts. Please wait a minute and try again.' ] );
    }

    /*
     * The visible cart is the authoritative cart. The posted client cart is
     * primary; the user-meta mirror is only a fallback when nothing was
     * posted, so a stale mirror can never bill lines the cart page does not
     * display.
     */
    $raw  = isset( $_POST['cart'] ) ? wp_unslash( $_POST['cart'] ) : '{}';
    $cart = is_array( $raw ) ? $raw : json_decode( (string) $raw, true );
    if ( ! is_array( $cart ) || ! $cart ) $cart = stripe_shop_lite_cart_read();
    if ( ! is_array( $cart ) || ! $cart ) wp_send_json_error( [ 'message' => 'Your cart is empty.' ] );

    // Required sign-in (WordPress session is always sufficient; Auth0 only
    // authenticates - purchase linkage never depends on it).
    if ( stripe_shop_lite_opt( 'require_signin' ) && ! is_user_logged_in() ) {
        wp_send_json_error( [
            'signin_required' => true,
            'redirect'        => wp_login_url( home_url( stripe_shop_lite_opt( 'cart_page', 'cart' ) ) ),
            'message'         => 'Please sign in to complete your purchase.',
        ] );
    }
    if ( ! stripe_shop_lite_stripe() ) wp_send_json_error( [ 'message' => 'Store is not configured.' ] );

    $user_id = get_current_user_id();
    $line_items = []; $classes = []; $stock = []; $item_ids = []; $needs_address = false; $is_recurring = false; $is_gift = false;

    foreach ( $cart as $line_key => $qty ) {
        // Per-line quantity ceiling (filterable via stripe_shop_lite_max_per_line):
        // no line can bill an arbitrary quantity.
        $qty = max( 1, min( (int) $qty, (int) apply_filters( 'stripe_shop_lite_max_per_line', 10 ) ) );
        $line_key = sanitize_text_field( (string) $line_key );
        $product_id = 0;
        $variant_price_id = '';
        if ( preg_match( '/^(\d+):(price_[A-Za-z0-9_]+)$/', $line_key, $m ) ) {
            $product_id = (int) $m[1];
            $variant_price_id = $m[2];
        } else {
            $product_id = absint( $line_key );
        }
        if ( ! $product_id || get_post_type( $product_id ) !== 'product' || get_post_status( $product_id ) !== 'publish' ) continue;

        $price_id = $variant_price_id ?: get_post_meta( $product_id, '_stripe_price_id', true );
        if ( $variant_price_id && ! stripe_shop_lite_find_variant( $product_id, $variant_price_id ) ) continue;
        if ( ! $price_id ) continue;
        $line_items[] = [ 'price' => $price_id, 'quantity' => $qty ];
        $kind = stripe_shop_lite_product_kind( $product_id );
        if ( $kind ) {
            $classes[] = $kind;
            if ( 'physical' === $kind ) {
                $needs_address = true;
                $stock[ $product_id ] = ( $stock[ $product_id ] ?? 0 ) + $qty;
            }
        }
        // Billing type comes from the selected Stripe Price, not from the browser.
        $pr = stripe_shop_lite_price_info( $price_id );
        // A customer-defined-amount price cannot be sold in a multi-item
        // cart (Stripe allows it only alone and at quantity 1). Reject it
        // up front with a clear message, before the session is created.
        if ( $pr && ! empty( $pr['custom'] ) ) {
            wp_send_json_error( [ 'message' => '"' . get_the_title( $product_id ) . '" uses a customer-defined amount price, which Stripe can only sell alone with a quantity of 1. Set a fixed price for it in Stripe or remove it from the cart.' ] );
        }
        if ( $pr && $pr['recurring'] ) $is_recurring = true;
        // Gift products: only one-off prices collect recipient fields.
        if ( stripe_shop_lite_product_is_gift( $product_id ) && ! ( $pr && $pr['recurring'] ) ) $is_gift = true;
        $item_ids[] = $product_id;
    }
    if ( ! $line_items ) wp_send_json_error( [ 'message' => 'Nothing in the cart can be bought.' ] );
    // Stripe does not accept shipping rates on a subscription-mode session.
    if ( $is_recurring && $needs_address ) {
        wp_send_json_error( [ 'message' => 'Subscriptions and shipped items need to be bought separately. Please check out one of them first.' ] );
    }

    // Availability check only. Stock is deducted when payment completes
    // (deduct_paid_session()), so an unpaid or abandoned session holds
    // nothing; a buyer who loses the race for the last unit while paying is
    // refunded automatically.
    foreach ( $stock as $product_id => $qty ) {
        $left = get_post_meta( $product_id, '_stock_level', true );
        if ( '' === $left || ! is_numeric( $left ) ) continue; // stock not tracked
        if ( (int) $left < $qty ) {
            $title = get_the_title( $product_id );
            wp_send_json_error( [ 'message' => (int) $left > 0
                ? sprintf( 'Only %d of "%s" left. Please lower the quantity and try again.', (int) $left, $title )
                : '"' . $title . '" just sold out.' ] );
        }
    }

    $params = [
        'line_items'  => $line_items,
        'mode'        => $is_recurring ? 'subscription' : 'payment',
        // A short window also narrows the time in which the last unit can
        // sell out before this buyer pays. 30 minutes is Stripe's minimum.
        'expires_at'  => time() + 30 * MINUTE_IN_SECONDS,
        'success_url' => home_url( trailingslashit( stripe_shop_lite_opt( 'success_page', 'checkout-success' ) ) ) . '?session_id={CHECKOUT_SESSION_ID}',
        'cancel_url'  => home_url( stripe_shop_lite_opt( 'cart_page', 'cart' ) ),
        'metadata'    => [
            'wp_user_id'                 => $user_id,
            'stripe_shop_lite_classes'   => implode( ',', array_unique( $classes ) ),
            'stripe_shop_lite_recurring' => $is_recurring ? '1' : '',
            'stripe_shop_lite_stock'     => $stock ? wp_json_encode( $stock ) : '',
            'stripe_shop_lite_items'     => wp_json_encode( $item_ids ),
            'stripe_shop_lite_gift'      => $is_gift ? '1' : '',
        ],
    ];
    if ( $user_id ) {
        $cid = Stripe_Shop_Lite_Stripe::customer_id( $user_id, true );
        if ( $cid ) $params['customer'] = $cid;
    }
    if ( stripe_shop_lite_opt( 'promo_codes_on' ) ) $params['allow_promotion_codes'] = true;
    if ( stripe_shop_lite_opt( 'tax_on' ) ) {
        $params['automatic_tax'] = [ 'enabled' => true ];
        // customer_update may only be sent together with customer (Stripe API
        // contract). With a Customer attached, Checkout must save what it
        // collects, because Stripe Tax computes the rate from the address on
        // the Customer object:
        //   - customer_update[address]: the billing address used for the rate;
        //   - customer_update[shipping]: required whenever automatic_tax and
        //     shipping_address_collection are combined; without it Checkout
        //     refuses to start. 'auto' saves only when Checkout actually
        //     collects the field, so it is safe for digital-only carts.
        if ( ! empty( $params['customer'] ) ) {
            $params['customer_update'] = [ 'address' => 'auto', 'shipping' => 'auto' ];
        } elseif ( 'payment' === $params['mode'] ) {
            // Guests (no customer yet): automatic tax still needs a Customer
            // to store the address on. In payment mode opt in with
            // customer_creation; subscription mode already creates one.
            $params['customer_creation'] = 'always';
        }
    }
    if ( $needs_address ) {
        $countries = array_map( 'trim', explode( ',', stripe_shop_lite_opt( 'shipping_countries', 'US,CA,GB' ) ) );
        $params['shipping_address_collection'] = [ 'allowed_countries' => $countries ];
        if ( stripe_shop_lite_opt( 'shipping_rate' ) ) $params['shipping_options'] = [ [ 'shipping_rate' => stripe_shop_lite_opt( 'shipping_rate' ) ] ];
    }
    // Gift: recipient details collected on the Stripe-hosted page itself.
    if ( $is_gift && 'payment' === $params['mode'] ) {
        $params['custom_fields'] = [
            [ 'key' => 'gift_recipient_name', 'label' => [ 'type' => 'custom', 'custom' => 'Recipient name' ], 'type' => 'text' ],
            [ 'key' => 'gift_recipient_email', 'label' => [ 'type' => 'custom', 'custom' => 'Recipient email' ], 'type' => 'text' ],
            [ 'key' => 'gift_message', 'label' => [ 'type' => 'custom', 'custom' => 'Gift message' ], 'type' => 'text', 'optional' => true ],
        ];
    }

    try {
        $session = \Stripe\Checkout\Session::create( $params );
        // Session created: this is what the rate limit counts.
        set_transient( $rl_key, (int) get_transient( $rl_key ) + 1, MINUTE_IN_SECONDS );
        wp_send_json_success( [ 'checkout_url' => $session->url ] );
    } catch ( \Exception $e ) {
        // The raw Stripe message can contain account-level details; log it
        // server-side and keep the browser response generic.
        error_log( 'Storefront for Stripe: checkout session creation failed: ' . $e->getMessage() );
        wp_send_json_error( [ 'message' => 'Checkout could not be started. Please try again in a moment; if the problem persists, contact the store.' ] );
    }
}
