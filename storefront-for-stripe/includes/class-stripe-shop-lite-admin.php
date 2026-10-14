<?php
/**
 * 10. Tabbed Admin Settings UI - admin.php?page=storefront-for-stripe-settings
 *
 * Tabs: Stripe | My Account | Pages | Cart and Checkout | Orders | Extra
 *   ?tab=stripe   secret key, webhook secret
 *   ?tab=account  Auth0/OIDC settings, account page, dashboard/profile, portal note
 *   ?tab=pages    cart/checkout/success pages + review settings
 *   ?tab=cart     tax, shipping, promotion codes, required sign-in
 *   ?tab=orders   recent orders from Stripe (2-minute cache)
 *   ?tab=extra    default demo style, pagination mode, product limit, shortcodes & API
 *
 * Settings save: Post-Redirect-Get - the POST is handled on admin_init, the
 * result flashed in a transient, then a redirect back, so refresh never
 * resubmits the form.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

function stripe_shop_lite_opt( $key, $default = '' ) {
    return get_option( 'stripe_shop_lite_' . $key, $default );
}

class Stripe_Shop_Lite_Admin {

    public static function init() {
        add_action( 'admin_menu', [ __CLASS__, 'menu' ] );
        add_action( 'admin_init', [ __CLASS__, 'save' ] );
        add_action( 'admin_notices', [ __CLASS__, 'flash' ] );
    }

    public static function menu() {
        add_menu_page( 'Store', 'Store', 'manage_options', 'storefront-for-stripe-settings', [ __CLASS__, 'page' ], 'dashicons-cart', 26 );
        add_submenu_page( 'storefront-for-stripe-settings', 'Settings', 'Settings', 'manage_options', 'storefront-for-stripe-settings', [ __CLASS__, 'page' ] );
        add_submenu_page( 'storefront-for-stripe-settings', 'Import from Stripe', 'Import from Stripe', 'manage_options', 'storefront-for-stripe-import', 'stripe_shop_lite_import_page' );
    }

    public static function current_tab() {
        return isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'stripe';
    }

    public static function save() {
        if ( ! isset( $_POST['stripe_shop_lite_save'] ) || ! current_user_can( 'manage_options' ) ) return;
        check_admin_referer( 'stripe_shop_lite_settings', 'stripe_shop_lite_settings' );

        $key = trim( sanitize_text_field( wp_unslash( $_POST['stripe_shop_lite_stripe_key'] ?? '' ) ) );
        $result = [ 'ok' => true, 'account' => '' ];
        if ( '' !== $key && $key !== stripe_shop_lite_opt( 'stripe_key' ) ) {
            $result = Stripe_Shop_Lite_Stripe::validate_key( $key );
            if ( $result['ok'] ) update_option( 'stripe_shop_lite_stripe_key', $key );
        }
        // Each tab posts only its own fields. An unchecked box and an absent box
        // look the same in $_POST, so checkboxes are saved per tab, and text
        // fields only when the field was actually on the form.
        $tab = isset( $_POST['stripe_shop_lite_tab'] ) ? sanitize_key( $_POST['stripe_shop_lite_tab'] ) : 'stripe';
        $checkboxes = [
            'pages' => [ 'reviews_on', 'ratings_on', 'buyers_only_reviews' ],
            'cart'  => [ 'tax_on', 'promo_codes_on', 'require_signin' ],
            'extra' => [ 'bootstrap_on' ],
        ];
        foreach ( $checkboxes[ $tab ] ?? [] as $b ) {
            update_option( 'stripe_shop_lite_' . $b, isset( $_POST[ 'stripe_shop_lite_' . $b ] ) ? '1' : '' );
        }
        foreach ( [ 'webhook_secret', 'account_page', 'cart_page', 'success_page', 'shipping_rate', 'shipping_countries', 'awin_advertiser_id', 'card_style', 'pagination', 'product_limit', 'auth0_domain', 'auth0_m2m_client_id', 'auth0_m2m_client_secret', 'auth0_m2m_token' ] as $t ) {
            if ( ! isset( $_POST[ 'stripe_shop_lite_' . $t ] ) ) continue;
            update_option( 'stripe_shop_lite_' . $t, sanitize_text_field( wp_unslash( $_POST[ 'stripe_shop_lite_' . $t ] ) ) );
        }
        set_transient( 'stripe_shop_lite_settings_notice', $result, 45 );
        wp_safe_redirect( admin_url( 'admin.php?page=storefront-for-stripe-settings&tab=' . $tab ) );
        exit;
    }

    // The flashed notice prints exactly once, then is gone.
    public static function flash() {
        if ( ! current_user_can( 'manage_options' ) ) return;
        $n = get_transient( 'stripe_shop_lite_settings_notice' );
        if ( ! $n ) return;
        delete_transient( 'stripe_shop_lite_settings_notice' );
        $ok = ! empty( $n['ok'] );
        $msg = $ok ? ( 'Saved. ' . ( $n['account'] ?? '' ) ) : ( $n['error'] ?? 'Unknown error.' );
        echo '<div class="notice ' . ( $ok ? 'notice-success' : 'notice-error' ) . ' is-dismissible"><p><strong>' . esc_html( $msg ) . '</strong></p></div>';
    }

    public static function page() {
        if ( ! current_user_can( 'manage_options' ) ) return;
        $tab = self::current_tab();
        $tabs = [
            'stripe' => 'Stripe',
            'account' => 'My Account',
            'pages' => 'Pages',
            'cart' => 'Cart and Checkout',
            'orders' => 'Orders',
            'extra' => 'Extra',
        ];
        ?>
        <div class="wrap"><h1>Store Settings</h1>
        <h2 class="nav-tab-wrapper">
            <?php foreach ( $tabs as $slug => $label ) : ?>
                <a href="?page=storefront-for-stripe-settings&tab=<?php echo esc_attr( $slug ); ?>" class="nav-tab <?php echo $slug === $tab ? 'nav-tab-active' : ''; ?>"><?php echo esc_html( $label ); ?></a>
            <?php endforeach; ?>
        </h2>
        <?php
        switch ( $tab ) {
            case 'account': self::tab_account(); break;
            case 'pages':   self::tab_pages(); break;
            case 'cart':    self::tab_cart(); break;
            case 'orders':  self::tab_orders(); break;
            case 'extra':   self::tab_extra(); break;
            default:       self::tab_stripe(); break;
        }
        echo '</div>';
    }

    private static function tab_stripe() {
        ?>
        <form method="post"><?php wp_nonce_field( 'stripe_shop_lite_settings', 'stripe_shop_lite_settings' ); ?><input type="hidden" name="stripe_shop_lite_tab" value="stripe" />
            <h2>Stripe</h2>
            <table class="form-table">
                <tr><th>Secret key</th><td><input type="password" class="regular-text" name="stripe_shop_lite_stripe_key" autocomplete="off" value="<?php echo esc_attr( stripe_shop_lite_opt( 'stripe_key' ) ); ?>" /><p class="description">Test key first. Leave blank to keep the current key. A restricted key needs read access to Products (that is what the check uses).</p></td></tr>
                <tr><th>Webhook signing secret</th><td><input type="password" class="regular-text" name="stripe_shop_lite_webhook_secret" autocomplete="off" value="<?php echo esc_attr( stripe_shop_lite_opt( 'webhook_secret' ) ); ?>" />
                <p class="description">Webhook URL: <code><?php echo esc_html( home_url( '/wp-json/storefront-for-stripe/v1/webhook' ) ); ?></code><br />Events to send: <code>checkout.session.completed</code>, <code>checkout.session.async_payment_succeeded</code>, <code>customer.subscription.*</code>, <code>invoice.paid</code>, <code>invoice.payment_failed</code>, <code>charge.refunded</code>, <code>credit_note.created</code>. Stock is deducted on the paid <code>checkout.session.completed</code> and <code>checkout.session.async_payment_succeeded</code> events, so bank-debit orders need the async event.</p></td></tr>
            </table>
            <?php submit_button( 'Save settings', 'primary', 'stripe_shop_lite_save' ); ?>
        </form>
        <?php
    }

    private static function tab_account() {
        ?>
        <form method="post"><?php wp_nonce_field( 'stripe_shop_lite_settings', 'stripe_shop_lite_settings' ); ?><input type="hidden" name="stripe_shop_lite_tab" value="account" />
            <h2>Sign-in account (Auth0 / OIDC)</h2>
            <table class="form-table">
                <tr><th>Account page</th><td><input type="text" class="regular-text" name="stripe_shop_lite_account_page" value="<?php echo esc_attr( stripe_shop_lite_opt( 'account_page' ) ); ?>" /><p class="description">Where customers land instead of wp-admin. Put [store_my_account] on it.</p></td></tr>
                <tr><th>Auth0 / OIDC domain</th><td><input type="text" class="regular-text" name="stripe_shop_lite_auth0_domain" value="<?php echo esc_attr( stripe_shop_lite_opt( 'auth0_domain' ) ); ?>" placeholder="dev-abc123.us.auth0.com" /><p class="description">Your OIDC plugin (Auth0 or any other) handles sign-in - it is optional and never required. Set the domain here only when you want [store_profile] edits to sync to Auth0 via the Management API. Leave empty if users sign in with WordPress credentials - the form then saves locally only.</p></td></tr>
                <tr><th>M2M Client ID</th><td><input type="password" class="regular-text" name="stripe_shop_lite_auth0_m2m_client_id" value="<?php echo esc_attr( stripe_shop_lite_opt( 'auth0_m2m_client_id' ) ); ?>" /><p class="description">A Machine-to-Machine Application in Auth0, granted the <code>update:users</code> and <code>read:users</code> scopes of the Management API. Only needed for profile sync - sign-in works without it.</p></td></tr>
                <tr><th>M2M Client Secret</th><td><input type="password" class="regular-text" name="stripe_shop_lite_auth0_m2m_client_secret" value="<?php echo esc_attr( stripe_shop_lite_opt( 'auth0_m2m_client_secret' ) ); ?>" /></td></tr>
                <tr><th>Management token (optional)</th><td><input type="password" class="regular-text" name="stripe_shop_lite_auth0_m2m_token" value="<?php echo esc_attr( stripe_shop_lite_opt( 'auth0_m2m_token' ) ); ?>" /><p class="description">Normally left empty - the plugin fetches and caches a token using the client above. Paste one manually only for testing.</p></td></tr>
                <tr><th>Customer Stripe Portal</th><td><p class="description">Payment methods, invoices and subscriptions are managed by the customer through the Stripe Customer Portal - add <code>[store_portal]</code> to the account page (the "Billing" section of [store_my_account] already includes it).</p></td></tr>
            </table>
            <?php submit_button( 'Save settings', 'primary', 'stripe_shop_lite_save' ); ?>
        </form>
        <?php
    }

    private static function tab_pages() {
        ?>
        <form method="post"><?php wp_nonce_field( 'stripe_shop_lite_settings', 'stripe_shop_lite_settings' ); ?><input type="hidden" name="stripe_shop_lite_tab" value="pages" />
            <h2>Pages</h2>
            <table class="form-table">
                <tr><th>Cart page</th><td><input type="text" class="regular-text" name="stripe_shop_lite_cart_page" value="<?php echo esc_attr( stripe_shop_lite_opt( 'cart_page' ) ); ?>" /><p class="description">Path, e.g. /cart/ - put [store_cart] on it.</p></td></tr>
                <tr><th>Checkout page</th><td><input type="text" class="regular-text" name="stripe_shop_lite_success_page" value="<?php echo esc_attr( stripe_shop_lite_opt( 'success_page' ) ); ?>" /><p class="description">Checkout itself is the Stripe-hosted page; this is the success/return page, e.g. /checkout-success/.</p></td></tr>
                <tr><th>Account page</th><td><input type="text" class="regular-text" name="stripe_shop_lite_account_page" value="<?php echo esc_attr( stripe_shop_lite_opt( 'account_page' ) ); ?>" /><p class="description">Where customers land instead of wp-admin. Put [store_my_account] on it.</p></td></tr>
            </table>
            <h2>Reviews (WordPress comments on products)</h2>
            <table class="form-table">
                <tr><th>Reviews</th><td><label><input type="checkbox" name="stripe_shop_lite_reviews_on" value="1" <?php checked( stripe_shop_lite_opt( 'reviews_on' ), '1'); ?> /> Enable</label></td></tr>
                <tr><th>Star ratings</th><td><label><input type="checkbox" name="stripe_shop_lite_ratings_on" value="1" <?php checked( stripe_shop_lite_opt( 'ratings_on' ), '1'); ?> /></label></td></tr>
                <tr><th>Verified buyers only</th><td><label><input type="checkbox" name="stripe_shop_lite_buyers_only_reviews" value="1" <?php checked( stripe_shop_lite_opt( 'buyers_only_reviews' ), '1'); ?> /></label></td></tr>
            </table>
            <?php submit_button( 'Save settings', 'primary', 'stripe_shop_lite_save' ); ?>
        </form>
        <?php
    }

    private static function tab_cart() {
        ?>
        <form method="post"><?php wp_nonce_field( 'stripe_shop_lite_settings', 'stripe_shop_lite_settings' ); ?><input type="hidden" name="stripe_shop_lite_tab" value="cart" />
            <h2>Cart &amp; Checkout</h2>
            <table class="form-table">
                <tr><th>Require Sign-In to Purchase</th><td><label><input type="checkbox" name="stripe_shop_lite_require_signin" value="1" <?php checked( stripe_shop_lite_opt( 'require_signin' ), '1'); ?> /> Prevent Guest Checkout</label><p class="description">Customers must be signed in before they can check out. A WordPress session is always sufficient - purchase linkage never depends on Auth0 or OIDC being reachable or in sync.</p></td></tr>
                <tr><th>Collect Tax</th><td><label><input type="checkbox" name="stripe_shop_lite_tax_on" value="1" <?php checked( stripe_shop_lite_opt( 'tax_on' ), '1'); ?> /> Enable Stripe Tax</label><p class="description">Stripe Tax calculates and collects tax on the Stripe-hosted checkout page. Requires a Stripe Tax subscription.</p></td></tr>
                <tr><th>Allow Promotion Codes</th><td><label><input type="checkbox" name="stripe_shop_lite_promo_codes_on" value="1" <?php checked( stripe_shop_lite_opt( 'promo_codes_on' ), '1'); ?> /> Accept Promotion Codes at Checkout</label><p class="description">Customers can apply Stripe promotion codes on the Stripe-hosted checkout page.</p></td></tr>
                <tr><th>Shipping Rate ID</th><td><input type="text" class="regular-text" name="stripe_shop_lite_shipping_rate" value="<?php echo esc_attr( stripe_shop_lite_opt( 'shipping_rate' ) ); ?>" placeholder="shr_..." /><p class="description">A single flat shipping rate: paste your Stripe shipping rate (shr_...). Leave empty to collect a delivery address without charging shipping.</p></td></tr>
                <tr><th>Shipping Countries</th><td><input type="text" class="regular-text" name="stripe_shop_lite_shipping_countries" value="<?php echo esc_attr( stripe_shop_lite_opt( 'shipping_countries' ) ); ?>" placeholder="US,CA,GB" /><p class="description">Two-letter country codes, comma-separated. Limits the delivery countries offered at checkout.</p></td></tr>
            </table>
            <?php submit_button( 'Save settings', 'primary', 'stripe_shop_lite_save' ); ?>
        </form>
        <?php
    }

    private static function tab_orders() {
        $cache = get_transient( 'stripe_shop_lite_orders_cache' );
        if ( false === $cache && stripe_shop_lite_stripe() ) {
            $cache = [];
            try {
                $sessions = \Stripe\Checkout\Session::all( [ 'limit' => 50 ] );
                foreach ( $sessions->data as $s ) {
                    if ( 'complete' !== $s->status ) continue;
                    // Delivery address: shipping_details (older API versions) or
                    // collected_information.shipping_details (2025+). customer_details
                    // is the BILLING address and must not be used as a shipping list.
                    $a = $s->shipping_details->address ?? ( $s->collected_information->shipping_details->address ?? null );
                    $cache[] = [
                        'date' => wp_date( get_option( 'date_format' ), $s->created ),
                        'email' => $s->customer_details->email ?? '',
                        'amount' => $s->amount_total ? number_format( $s->amount_total / 100, 2 ) : '-',
                        'mode' => $s->mode,
                        'gift' => ! empty( $s->metadata->stripe_shop_lite_gift ),
                        'address' => $a ? trim( ( $a->line1 ?? '' ) . ', ' . ( $a->city ?? '' ) . ' ' . ( $a->postal_code ?? '' ) ) : '',
                    ];
                }
            } catch ( \Exception $e ) {}
            set_transient( 'stripe_shop_lite_orders_cache', $cache, 120 );
        }
        echo '<p>Read live from Stripe (2-minute cache). The delivery address column is your shipping list. Refunds and disputes: <a href="https://dashboard.stripe.com/payments" target="_blank">open Stripe</a>.</p>';
        if ( $cache ) {
            echo '<table class="widefat"><thead><tr><th>When</th><th>Who</th><th>Amount</th><th>Type</th><th>Gift</th><th>Delivery address</th></tr></thead><tbody>';
            foreach ( $cache as $o ) {
                printf( '<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
                    esc_html( $o['date'] ), esc_html( $o['email'] ), esc_html( $o['amount'] ), esc_html( $o['mode'] ),
                    $o['gift'] ? 'yes' : '-', esc_html( $o['address'] ) );
            }
            echo '</tbody></table>';
        } else { echo '<p>No orders yet (or the Stripe key is not set).</p>'; }
    }

    private static function tab_extra() {
        $cur_style = stripe_shop_lite_opt( 'card_style' );
        if ( ! preg_match( '/^demo-[1-9]$/', (string) $cur_style ) ) $cur_style = 'demo-2';
        ?>
        <form method="post"><?php wp_nonce_field( 'stripe_shop_lite_settings', 'stripe_shop_lite_settings' ); ?><input type="hidden" name="stripe_shop_lite_tab" value="extra" />
            <h2>Catalog defaults</h2>
            <table class="form-table">
                <tr><th>Product card style</th><td><select name="stripe_shop_lite_card_style">
                    <?php foreach ( [ 'demo-1' => 'Demo 1: slide-up content panel', 'demo-2' => 'Demo 2: hover overlay + Add to cart', 'demo-3' => 'Demo 3: expand-on-hover card', 'demo-4' => 'Demo 4: round New/Sale badges', 'demo-5' => 'Demo 5: Select Options overlay', 'demo-6' => 'Demo 6: zoom image + icon buttons', 'demo-7' => 'Demo 7: 3D flip second image', 'demo-8' => 'Demo 8: wide cards, Free Shipping row', 'demo-9' => 'Demo 9: fade price to action button' ] as $k => $lbl ) : ?>
                        <option value="<?php echo esc_attr( $k ); ?>" <?php selected( $cur_style, $k ); ?>><?php echo esc_html( $lbl ); ?></option>
                    <?php endforeach; ?>
                </select><p class="description">Default card design for the /product/ archive and [store_products]; per-grid override with style="demo-N".</p></td></tr>
                <tr><th>Pagination</th><td><select name="stripe_shop_lite_pagination">
                    <?php $cur_pg = stripe_shop_lite_opt( 'pagination', 'numbered' ); ?>
                    <option value="numbered" <?php selected( $cur_pg, 'numbered' ); ?>>Numbered</option>
                    <option value="infinite" <?php selected( $cur_pg, 'infinite' ); ?>>Infinite scroll</option>
                    <option value="none" <?php selected( $cur_pg, 'none' ); ?>>None (limit only)</option>
                </select></td></tr>
                <tr><th>Default product limit</th><td><input type="number" min="1" max="200" class="regular-text" name="stripe_shop_lite_product_limit" value="<?php echo esc_attr( stripe_shop_lite_opt( 'product_limit', 24 ) ); ?>" /><p class="description">Products per page/grid unless a shortcode overrides limit="".</p></td></tr>
                <tr><th>Bootstrap 5</th><td><label><input type="checkbox" name="stripe_shop_lite_bootstrap_on" value="1" <?php checked( stripe_shop_lite_opt( 'bootstrap_on' ), '1' ); ?> /> Load Bootstrap 5 (CSS + JS bundle) from the plugin's local vendor files on shop pages</label>
                <p class="description">The shop templates already use Bootstrap-compatible markup with their own fallback styles. Never loaded in wp-admin.</p></td></tr>
            </table>
            <?php submit_button( 'Save settings', 'primary', 'stripe_shop_lite_save' ); ?>
        </form>
        <h2>Shortcodes</h2>
        <table class="widefat"><tbody>
            <tr><td><code>[store_products]</code></td><td>Product grid. Optional: limit, columns, category, style, pagination</td></tr>
            <tr><td><code>[store_filters]</code></td><td>Filter/sort bar (category, price range, search, sort)</td></tr>
            <tr><td><code>[store_cart]</code></td><td>Cart page contents (JS-rendered, cache-proof)</td></tr>
            <tr><td><code>[store_cart_button]</code></td><td>Header cart icon with live count; click opens the off-canvas drawer</td></tr>
            <tr><td><code>[store_my_account]</code></td><td>Account dashboard (customer view / staff view)</td></tr>
            <tr><td><code>[store_profile]</code></td><td>Profile form (Auth0-synced when configured)</td></tr>
            <tr><td><code>[store_profile_notice]</code></td><td>Flash notices for the profile form</td></tr>
            <tr><td><code>[store_orders]</code> / <code>[store_subscriptions]</code></td><td>Current user's orders / active subscriptions (live from Stripe)</td></tr>
            <tr><td><code>[store_portal]</code></td><td>Stripe Customer Portal link (billing, invoices, payment methods)</td></tr>
            <tr><td><code>[store_restrict roles="stripe_subscriber,stripe_member"]</code></td><td>Restrict content by role</td></tr>
            <tr><td><code>[store_waitlist]</code></td><td>The signed-in customer's own stock watchlist</td></tr>
        </tbody></table>
        <h2>API</h2>
        <table class="widefat"><tbody>
            <tr><td><code>POST <?php echo esc_html( rest_url( 'storefront-for-stripe/v1/webhook' ) ); ?></code></td><td>Stripe webhook (signature-verified)</td></tr>
            <tr><td><code>GET|POST <?php echo esc_html( rest_url( 'storefront-for-stripe/v1/cart' ) ); ?></code></td><td>Cart REST mirror (action=stripe_shop_lite_cart_items|stripe_shop_lite_checkout|stripe_shop_lite_waitlist_join|stripe_shop_lite_cart_get|stripe_shop_lite_cart_set)</td></tr>
        </tbody></table>
        <?php
    }
}
