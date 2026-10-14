<?php
/**
 * Uninstall cleanup for Storefront for Stripe.
 *
 * Removes plugin options, transients and scheduled events, the user meta
 * (Stripe customer links, cart copies, purchase flags), the plugin's roles
 * and the product capabilities it granted to Administrator. Product posts
 * and their meta are user content and are never touched; the Stripe-side
 * data (products, prices, customers) is of course untouched.
 *
 * @package storefront-for-stripe
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) exit;

// Scheduled events owned by the plugin. The reconciliation job was retired
// in 1.0.1 and is cleared here only in case an older install still has it
// scheduled. The pattern below also removes the paid-session marker
// rows (stripe_shop_lite_paid_*) and the processed-webhook transients.
wp_clear_scheduled_hook( 'stripe_shop_lite_reconcile_expired' );

global $wpdb;

// Plugin options, transients and their timeouts. On installs without a
// persistent object cache, transients live in the options table under
// _transient_ / _transient_timeout_ prefixes, so one LIKE pattern covers
// both plain options and every transient variant.
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '%stripe\_shop\_lite\_%'" );

// Per-user mirrors created by the plugin (Stripe customer links, cart
// mirror, purchase-class flags). Purchase history itself lives in Stripe;
// these are only the local linkage copies.
$wpdb->query( "DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE '%stripe\_shop\_lite\_%'" );

// Roles registered by the plugin are removed; users keep their accounts and
// any other roles. Two generations exist and both are cleaned:
//   the renamed slugs derived by the Stripe class
//     (plan_member, plan_subscriber, customer, donor, registered_user),
//   the original slugs registered by the Roles class
//     (stripe_user, stripe_donor, stripe_customer, stripe_subscriber,
//      stripe_member, stripe_shop_manager).
// A pre-existing unprefixed `shop_manager` role is NOT touched - it may have
// existed before the plugin (the Roles class leaves it alone too).
// `customer` and the product capabilities are also WooCommerce's: when
// WooCommerce is (or was) active on the site, its role definition and
// capabilities are left in place instead of leaving the role undefined.
$stripe_shop_lite_woo = (string) get_option( 'woocommerce_version' );
foreach ( [
	'plan_member', 'plan_subscriber', 'customer', 'donor', 'registered_user',
	'stripe_user', 'stripe_donor', 'stripe_customer', 'stripe_subscriber',
	'stripe_member', 'stripe_shop_manager',
] as $stripe_shop_lite_role ) {
	if ( 'customer' === $stripe_shop_lite_role && $stripe_shop_lite_woo ) continue;
	remove_role( $stripe_shop_lite_role );
}

// The Roles class grants the product capabilities to Administrator so admins
// can manage the CPT; take those back on uninstall. The Administrator role
// itself and every other capability are untouched. The same WooCommerce
// guard applies: on a site that came from WooCommerce the capabilities
// stay (they are WooCommerce's product capabilities too).
if ( ! $stripe_shop_lite_woo ) {
	$stripe_shop_lite_admin = get_role( 'administrator' );
	if ( $stripe_shop_lite_admin ) {
		$stripe_shop_lite_product_caps = [ 'edit_', 'delete_', 'edit_others_', 'delete_others_', 'publish_', 'read_' ];
		foreach ( $stripe_shop_lite_product_caps as $stripe_shop_lite_p ) {
			foreach ( [ 'product', 'products' ] as $stripe_shop_lite_s ) $stripe_shop_lite_admin->remove_cap( $stripe_shop_lite_p . $stripe_shop_lite_s );
		}
		foreach ( [ 'read_private_products', 'edit_published_products', 'delete_published_products', 'edit_private_products', 'delete_private_products', 'manage_product_terms', 'edit_product_terms', 'delete_product_terms', 'assign_product_terms' ] as $stripe_shop_lite_c ) {
			$stripe_shop_lite_admin->remove_cap( $stripe_shop_lite_c );
		}
	}
}
