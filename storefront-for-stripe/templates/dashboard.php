<?php
/**
 * Dashboard template (staff view: Administrator / Shop Manager) - Storefront for Stripe.
 * Override by copying to yourtheme/storefront-for-stripe/dashboard.php.
 * Bootstrap 5 owns the layout: stat cards use BS5 rows and Orders/My details/Billing use tabs.
 * Available vars: $user (WP_User), $is_staff (bool).
 */

if ( ! defined( 'ABSPATH' ) ) exit;
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- template variables are local to Stripe_Shop_Lite::load_template()

$u = isset( $user ) ? $user : wp_get_current_user();
$initial = strtoupper( mb_substr( $u->display_name ?: $u->user_login, 0, 1 ) );
?>
<div class="stripe-shop-lite-db stripe-shop-lite-db-staff">

    <nav class="stripe-shop-lite-db-topbar">
        <span class="stripe-shop-lite-db-brand">Store dashboard</span>
        <span class="stripe-shop-lite-db-topuser">
            <span class="stripe-shop-lite-db-avatar"><?php echo esc_html( $initial ); ?></span>
            <span class="stripe-shop-lite-db-topuser-name"><?php echo esc_html( $u->display_name ?: $u->user_login ); ?> &mdash; Store team</span>
        </span>
    </nav>

    <section class="stripe-shop-lite-db-main">
        <section id="stripe-shop-lite-db-overview" class="stripe-shop-lite-db-section">
            <h2 class="stripe-shop-lite-db-h1">Store dashboard</h2>
            <?php
            $month_revenue = Stripe_Shop_Lite_Stripe::month_revenue();
            $live = (int) wp_count_posts( 'product' )->publish;
            $drafts = (int) wp_count_posts( 'product' )->draft;
            $subs = Stripe_Shop_Lite_Stripe::active_subscriber_count();
            ?>
            <div class="row row-cols-1 row-cols-md-2 row-cols-xl-4 g-4 stripe-shop-lite-db-cards stripe-shop-lite-db-stats">
                <div class="col"><div class="stripe-shop-lite-db-card h-100"><h6>Live products</h6><span class="stripe-shop-lite-db-big"><?php echo esc_html( $live ); ?></span></div></div>
                <div class="col"><div class="stripe-shop-lite-db-card h-100"><h6>Drafts</h6><span class="stripe-shop-lite-db-big"><?php echo esc_html( $drafts ); ?></span></div></div>
                <div class="col"><div class="stripe-shop-lite-db-card h-100"><h6>Active subscribers</h6><span class="stripe-shop-lite-db-big"><?php echo esc_html( (int) $subs ); ?></span></div></div>
                <div class="col"><div class="stripe-shop-lite-db-card h-100"><h6>Revenue this month</h6><span class="stripe-shop-lite-db-big"><?php echo esc_html( $month_revenue ); ?></span></div></div>
            </div>
            <div class="stripe-shop-lite-db-actions">
                <a class="btn btn-primary" href="<?php echo esc_url( admin_url( 'edit.php?post_type=product' ) ); ?>">Manage products</a>
                <a class="btn btn-outline-secondary" href="<?php echo esc_url( admin_url( 'edit.php?post_type=product&post_status=draft' ) ); ?>">Review drafts</a>
                <?php if ( current_user_can( 'manage_options' ) ) : ?>
                <a class="btn btn-outline-secondary" href="<?php echo esc_url( admin_url( 'admin.php?page=storefront-for-stripe-import' ) ); ?>">Import from Stripe</a>
                <a class="btn btn-outline-secondary" href="<?php echo esc_url( admin_url( 'admin.php?page=storefront-for-stripe-settings' ) ); ?>">Store settings</a>
                <?php endif; ?>
            </div>
        </section>

        <ul class="nav nav-tabs stripe-shop-lite-db-tabs" id="stripe-shop-lite-staff-tabs" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link active" id="stripe-shop-lite-staff-orders-tab" data-bs-toggle="tab" data-bs-target="#stripe-shop-lite-staff-orders" type="button" role="tab" aria-controls="stripe-shop-lite-staff-orders" aria-selected="true">Orders</button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="stripe-shop-lite-staff-profile-tab" data-bs-toggle="tab" data-bs-target="#stripe-shop-lite-staff-profile" type="button" role="tab" aria-controls="stripe-shop-lite-staff-profile" aria-selected="false">My details</button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="stripe-shop-lite-staff-billing-tab" data-bs-toggle="tab" data-bs-target="#stripe-shop-lite-staff-billing" type="button" role="tab" aria-controls="stripe-shop-lite-staff-billing" aria-selected="false">Billing</button>
            </li>
        </ul>

        <div class="tab-content stripe-shop-lite-db-tab-content pt-4" id="stripe-shop-lite-staff-tab-content">
            <div class="tab-pane fade show active stripe-shop-lite-db-section" id="stripe-shop-lite-staff-orders" role="tabpanel" aria-labelledby="stripe-shop-lite-staff-orders-tab" tabindex="0">
                <h2 class="stripe-shop-lite-db-h2">Orders</h2>
                <p class="stripe-shop-lite-db-note">The full, always-current order list (delivery addresses, gift flags, refunds) lives in <a href="<?php echo esc_url( admin_url( 'admin.php?page=storefront-for-stripe-settings&tab=orders' ) ); ?>">Store -&gt; Settings -&gt; Orders</a>.</p>
            </div>
            <div class="tab-pane fade stripe-shop-lite-db-section" id="stripe-shop-lite-staff-profile" role="tabpanel" aria-labelledby="stripe-shop-lite-staff-profile-tab" tabindex="0">
                <h2 class="stripe-shop-lite-db-h2">My details</h2>
                <?php echo do_shortcode( '[store_profile_notice]' ); ?>
                <?php echo do_shortcode( '[store_profile]' ); ?>
            </div>
            <div class="tab-pane fade stripe-shop-lite-db-section" id="stripe-shop-lite-staff-billing" role="tabpanel" aria-labelledby="stripe-shop-lite-staff-billing-tab" tabindex="0">
                <h2 class="stripe-shop-lite-db-h2">Billing</h2>
                <p>Invoices, payment methods and your billing address live in the Stripe customer portal.</p>
                <?php echo do_shortcode( '[store_portal]' ); ?>
            </div>
        </div>
    </section>
</div>
