<?php
/**
 * Checkout template - Storefront for Stripe (/checkout/ page, placeholder).
 * Override by copying to yourtheme/storefront-for-stripe/checkout.php.
 *
 * Checkout itself happens on the Stripe-hosted Checkout page (the Stripe
 * Checkout Session IS the cart engine: tax, shipping, country restrictions
 * and promotion codes all apply there). This page is a placeholder that
 * sends the customer to Stripe via the cart's checkout button, and links
 * the Stripe Customer Portal for billing management.
 */

if ( ! defined( 'ABSPATH' ) ) exit;
?>
<div class="stripe-shop-lite-checkout-page">
    <h2>Checkout</h2>
    <p>Review your cart, then click <strong>Checkout with Stripe</strong>. You will be taken to Stripe's secure, hosted payment page to complete your purchase.</p>
    <?php echo do_shortcode( '[store_cart]' ); ?>
    <hr>
    <h2>Manage billing</h2>
    <p>Already purchased? Invoices, payment methods and subscriptions are managed in the Stripe customer portal:</p>
    <?php echo do_shortcode( '[store_portal text="Open the Stripe customer portal"]' ); ?>
</div>
