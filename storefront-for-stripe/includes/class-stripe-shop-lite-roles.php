<?php
/**
 * 1. Custom User Roles & Capabilities.
 *
 * stripe_user - registered, free account (default for everyone)
 * stripe_donor - donation purchase history
 * stripe_customer - non-subscription physical purchases
 * stripe_subscriber - active Stripe subscription ("Stripe Subscriber")
 * stripe_member - both a subscription and physical purchases
 * stripe_shop_manager - manages the product post type, nothing else
 *
 * Roles are derived from live Stripe state; see the Stripe class.
 *
 * Every role this plugin defines carries the stripe_ prefix, so none of them
 * can ever collide with a WordPress core role (user, subscriber, contributor,
 * author, editor, administrator) or with a role added by another plugin.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

function stripe_shop_lite_roles() {
    return [
        'stripe_user' => [ 'read' => true ],
        'stripe_donor' => [ 'read' => true ],
        'stripe_customer' => [ 'read' => true ],
        'stripe_subscriber' => [ 'read' => true, 'view_premium_content' => true ],
        'stripe_member' => [ 'read' => true, 'view_premium_content' => true ],
    ];
}

function stripe_shop_lite_product_caps() {
    $caps = [];
    foreach ( [ 'edit_', 'delete_', 'edit_others_', 'delete_others_', 'publish_', 'read_' ] as $p ) {
        foreach ( [ 'product', 'products' ] as $s ) $caps[ $p . $s ] = true;
    }
    $caps['read_private_products']     = true;
    $caps['edit_published_products']   = true;
    $caps['delete_published_products'] = true;
    $caps['edit_private_products']     = true;
    $caps['delete_private_products']   = true;
    $caps['manage_product_terms']      = true;
    $caps['edit_product_terms']        = true;
    $caps['delete_product_terms']      = true;
    $caps['assign_product_terms']      = true;
    return $caps;
}

// Shop Manager = product management only. No posts, no pages, no settings.
function stripe_shop_lite_shop_manager_caps() {
    return array_merge( stripe_shop_lite_product_caps(), [ 'read' => true, 'upload_files' => true ] );
}

function stripe_shop_lite_sync_roles() {
    foreach ( stripe_shop_lite_roles() as $role => $caps ) {
        $existing = get_role( $role );
        if ( $existing ) { foreach ( $caps as $c => $g ) $existing->add_cap( $c, $g ); }
        else {
            $label = [ 'stripe_subscriber' => 'Stripe Subscriber', 'stripe_shop_manager' => 'Shop Manager' ][ $role ] ?? ucfirst( str_replace( '_', ' ', $role ) );
            add_role( $role, $label, $caps );
        }
    }
    $sm = get_role( 'stripe_shop_manager' );
    if ( $sm ) { foreach ( stripe_shop_lite_shop_manager_caps() as $c => $g ) $sm->add_cap( $c, $g ); }
    else { add_role( 'stripe_shop_manager', 'Shop Manager', stripe_shop_lite_shop_manager_caps() ); }
    $admin = get_role( 'administrator' );
    if ( $admin ) { foreach ( stripe_shop_lite_product_caps() as $c => $g ) $admin->add_cap( $c, $g ); }

    // Safety net: if an earlier version of this plugin granted view_premium_content
    // to WordPress's own `subscriber` role, take it back. No user's role is changed.
    $core_sub = get_role( 'subscriber' );
    if ( $core_sub ) $core_sub->remove_cap( 'view_premium_content' );
    // Pre-1.0 builds created an unprefixed `shop_manager` role; its capabilities
    // stay on that role but nothing reads them anymore (the plugin now checks
    // stripe_shop_manager). Remove the caps so the old role is inert.
    $legacy_sm = get_role( 'shop_manager' );
    if ( $legacy_sm && ! get_role( 'stripe_shop_manager' ) ) {
        // shop_manager existed before this plugin: leave it entirely alone.
    } elseif ( $legacy_sm ) {
        foreach ( stripe_shop_lite_shop_manager_caps() as $c => $g ) $legacy_sm->remove_cap( $c );
    }
}

function stripe_shop_lite_is_exempt( $user_id ) {
    $u = get_userdata( $user_id );
    if ( ! $u ) return true;
    // Administrators, Shop Managers, and anyone who can write content
    // (Editor, Author, Contributor) keep their role and their wp-admin access.
    return user_can( $user_id, 'manage_options' ) || user_can( $user_id, 'edit_posts' ) || in_array( 'stripe_shop_manager', (array) $u->roles, true );
}

class Stripe_Shop_Lite_Roles {

    public static function init() {
        // Everyone who registers - Auth0/OIDC, front-end form, wp-admin - starts as registered_user.
        add_action( 'user_register', [ __CLASS__, 'default_role' ], 5 );
        add_action( 'stripe_shop_lite_daily_role_check', [ __CLASS__, 'daily_check' ] );
    }

    public static function default_role( $user_id ) {
        if ( stripe_shop_lite_is_exempt( $user_id ) ) return;
        $u = get_userdata( $user_id );
        if ( ! $u ) return;
        // Replace only the site's default role. A role somebody chose on purpose
        // (an Editor added in wp-admin, say) is left alone.
        if ( (array) $u->roles !== [ get_option( 'default_role', 'subscriber' ) ] ) return;
        $u->set_role( 'registered_user' );
    }

        /**
     * Daily pass: only subscription holders can change; look at them roughly monthly.
     * Role derivation itself lives in the Stripe class (it needs the Stripe client).
     */
    public static function daily_check() {
        $subs = get_users( [ 'meta_key' => '_stripe_shop_lite_has_subscription', 'meta_value' => '1', 'number' => 500 ] );
        foreach ( $subs as $u ) {
            if ( time() - (int) get_user_meta( $u->ID, '_stripe_shop_lite_role_checked', true ) > DAY_IN_SECONDS * 20 ) {
                stripe_shop_lite_apply_role( $u->ID );
            }
        }
    }
}
