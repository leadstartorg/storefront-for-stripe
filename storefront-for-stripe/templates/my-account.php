<?php
/**
 * My Account template (customer view) - Storefront for Stripe.
 * Override by copying to yourtheme/storefront-for-stripe/my-account.php.
 * Bootstrap 5 tabs for Orders, Subscriptions, My details, Billing and Stock watchlist.
 * Available vars: $user (WP_User), $is_staff (bool).
 */

if ( ! defined( 'ABSPATH' ) ) exit;
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- template variables are local to Stripe_Shop_Lite::load_template()

$u = isset( $user ) ? $user : wp_get_current_user();
$initial = strtoupper( mb_substr( $u->display_name ?: $u->user_login, 0, 1 ) );
$orders_html = do_shortcode( '[store_orders]' );
$subs_html   = do_shortcode( '[store_subscriptions]' );
?>
<div class="stripe-shop-lite-db stripe-shop-lite-db-customer">

    <nav class="stripe-shop-lite-db-topbar">
        <span class="stripe-shop-lite-db-brand">My account</span>
        <span class="stripe-shop-lite-db-topuser">
            <span class="stripe-shop-lite-db-avatar"><?php echo esc_html( $initial ); ?></span>
            <span class="stripe-shop-lite-db-topuser-name"><?php echo esc_html( $u->display_name ?: $u->user_login ); ?> &mdash; Customer</span>
        </span>
    </nav>

    <section class="stripe-shop-lite-db-main">
        <section id="stripe-shop-lite-db-overview" class="stripe-shop-lite-db-section">
            <h2 class="stripe-shop-lite-db-h1">Welcome back, <?php echo esc_html( $u->first_name ?: $u->display_name ); ?></h2>
            <div class="row row-cols-1 row-cols-md-2 row-cols-xl-3 g-4 stripe-shop-lite-db-cards">
                <div class="col">
                    <div class="stripe-shop-lite-db-card stripe-shop-lite-db-card-wide h-100">
                        <h6>Recent orders &amp; subscriptions</h6>
                        <?php echo wp_kses_post( $orders_html ); ?>
                        <?php echo wp_kses_post( $subs_html ); ?>
                    </div>
                </div>
                <div class="col">
                    <div class="stripe-shop-lite-db-card h-100">
                        <?php
                        $steps = [
                            'First name' => (string) $u->first_name,
                            'Last name'  => (string) $u->last_name,
                            'Email'      => (string) $u->user_email,
                        ];
                        $done = count( array_filter( $steps ) );
                        $pct = (int) round( 100 * $done / count( $steps ) );
                        ?>
                        <h6>Profile progress: <?php echo esc_html( $pct ); ?>%</h6>
                        <div class="progress stripe-shop-lite-db-progress"><div class="progress-bar" style="width:<?php echo esc_attr( $pct ); ?>%"></div></div>
                        <ul class="stripe-shop-lite-db-checklist">
                            <?php foreach ( $steps as $label => $val ) : ?>
                                <li class="<?php echo $val ? 'is-done' : ''; ?>"><?php echo esc_html( $label ); ?></li>
                            <?php endforeach; ?>
                        </ul>
                        <button class="btn btn-sm btn-outline-secondary" type="button" data-store-show-tab="stripe-shop-lite-customer-profile-tab">Complete my profile</button>
                    </div>
                </div>
            </div>
        </section>

        <ul class="nav nav-tabs stripe-shop-lite-db-tabs" id="stripe-shop-lite-customer-tabs" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link active" id="stripe-shop-lite-customer-orders-tab" data-bs-toggle="tab" data-bs-target="#stripe-shop-lite-customer-orders" type="button" role="tab" aria-controls="stripe-shop-lite-customer-orders" aria-selected="true">Orders</button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="stripe-shop-lite-customer-subs-tab" data-bs-toggle="tab" data-bs-target="#stripe-shop-lite-customer-subs" type="button" role="tab" aria-controls="stripe-shop-lite-customer-subs" aria-selected="false">Subscriptions</button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="stripe-shop-lite-customer-profile-tab" data-bs-toggle="tab" data-bs-target="#stripe-shop-lite-customer-profile" type="button" role="tab" aria-controls="stripe-shop-lite-customer-profile" aria-selected="false">My details</button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="stripe-shop-lite-customer-billing-tab" data-bs-toggle="tab" data-bs-target="#stripe-shop-lite-customer-billing" type="button" role="tab" aria-controls="stripe-shop-lite-customer-billing" aria-selected="false">Billing</button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="stripe-shop-lite-customer-watchlist-tab" data-bs-toggle="tab" data-bs-target="#stripe-shop-lite-customer-watchlist" type="button" role="tab" aria-controls="stripe-shop-lite-customer-watchlist" aria-selected="false">Stock watchlist</button>
            </li>
        </ul>

        <div class="tab-content stripe-shop-lite-db-tab-content pt-4" id="stripe-shop-lite-customer-tab-content">
            <div class="tab-pane fade show active stripe-shop-lite-db-section" id="stripe-shop-lite-customer-orders" role="tabpanel" aria-labelledby="stripe-shop-lite-customer-orders-tab" tabindex="0">
                <h2 class="stripe-shop-lite-db-h2">Orders</h2>
                <?php echo wp_kses_post( $orders_html ); ?>
            </div>
            <div class="tab-pane fade stripe-shop-lite-db-section" id="stripe-shop-lite-customer-subs" role="tabpanel" aria-labelledby="stripe-shop-lite-customer-subs-tab" tabindex="0">
                <h2 class="stripe-shop-lite-db-h2">Subscriptions</h2>
                <?php echo wp_kses_post( $subs_html ); ?>
            </div>
            <div class="tab-pane fade stripe-shop-lite-db-section" id="stripe-shop-lite-customer-profile" role="tabpanel" aria-labelledby="stripe-shop-lite-customer-profile-tab" tabindex="0">
                <h2 class="stripe-shop-lite-db-h2">My details</h2>
                <?php echo do_shortcode( '[store_profile_notice]' ); ?>
                <?php echo do_shortcode( '[store_profile]' ); ?>
            </div>
            <div class="tab-pane fade stripe-shop-lite-db-section" id="stripe-shop-lite-customer-billing" role="tabpanel" aria-labelledby="stripe-shop-lite-customer-billing-tab" tabindex="0">
                <h2 class="stripe-shop-lite-db-h2">Billing</h2>
                <p>Invoices, payment methods and your billing address live in the Stripe customer portal.</p>
                <?php echo do_shortcode( '[store_portal]' ); ?>
            </div>
            <div class="tab-pane fade stripe-shop-lite-db-section" id="stripe-shop-lite-customer-watchlist" role="tabpanel" aria-labelledby="stripe-shop-lite-customer-watchlist-tab" tabindex="0">
                <h2 class="stripe-shop-lite-db-h2">Stock watchlist</h2>
                <?php echo do_shortcode( '[store_waitlist]' ); ?>
            </div>
        </div>
    </section>
</div>
