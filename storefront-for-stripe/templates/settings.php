<?php
/**
 * Profile Settings template - Storefront for Stripe (/settings/ page).
 * Override by copying to yourtheme/storefront-for-stripe/settings.php.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! is_user_logged_in() ) {
    echo '<p>Please <a href="' . esc_url( wp_login_url( get_permalink() ) ) . '">sign in</a> to edit your profile.</p>';
    return;
}
?>
<div class="stripe-shop-lite-settings-page">
    <h2>Profile settings</h2>
    <?php echo do_shortcode( '[store_profile_notice]' ); ?>
    <?php echo do_shortcode( '[store_profile]' ); ?>
    <hr>
    <h2>Billing</h2>
    <p>Invoices, payment methods and your billing address live in the Stripe customer portal.</p>
    <?php echo do_shortcode( '[store_portal]' ); ?>
</div>
