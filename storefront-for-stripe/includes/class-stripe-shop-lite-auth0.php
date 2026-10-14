<?php
/**
 * 6. Auth0 / OIDC settings.
 *
 * The OIDC plugin (whichever one the site uses) handles SIGN-IN - it is
 * optional and never a dependency of this plugin. The Auth0 M2M credentials
 * here are only for PROFILE SYNC: when an Auth0 domain is configured, the
 * profile form PATCHes Auth0 via the Management API first, then mirrors to
 * WordPress. With no M2M keys, profile edits save locally only.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class Stripe_Shop_Lite_Auth0 {

    public static function init() {
        // Settings storage lives in the Admin class; this class owns the API calls.
    }

    public static function opt( $key ) {
        return stripe_shop_lite_opt( $key );
    }

    /**
     * PATCH an Auth0 user via the Management API. Uses client credentials
     * if a Client ID/Secret pair is stored; otherwise expects a
     * pre-provisioned Management API token (fine for testing, expires).
     * $new_pass is optional; when given, the password is PATCHed in a
     * SEPARATE call (Auth0 sends the user a confirmation email for it).
     * Returns true or WP_Error.
     */
    public static function patch_user( $current_email, $fields, $new_pass = '' ) {
        $domain = self::opt( 'auth0_domain' );   // e.g. dev-abc123.us.auth0.com
        $token  = self::opt( 'auth0_m2m_token' );
        $cid    = self::opt( 'auth0_m2m_client_id' );
        $csec   = self::opt( 'auth0_m2m_client_secret' );

        if ( ! $domain ) return new WP_Error( 'auth0_unconfigured', 'Auth0 domain not set in the Store settings' );

        // Fetch a machine-to-machine token if a client is configured.
        if ( ! $token && $cid && $csec ) {
            $res = wp_remote_post( "https://{$domain}/oauth/token", [ 'body' => [
                'grant_type'    => 'client_credentials',
                'client_id'     => $cid,
                'client_secret' => $csec,
                'audience'      => "https://{$domain}/api/v2/",
            ] ] );
            if ( is_wp_error( $res ) ) return $res;
            $body = json_decode( wp_remote_retrieve_body( $res ), true );
            if ( empty( $body['access_token'] ) ) return new WP_Error( 'auth0_token', 'Could not authenticate to Auth0 (check the M2M client settings).' );
            $token = $body['access_token'];
            update_option( 'stripe_shop_lite_auth0_m2m_token', $token ); // cached; a 401 below invalidates the retry
        }
        if ( ! $token ) return new WP_Error( 'auth0_no_token', 'No Auth0 API credentials configured' );

        // Look up the Auth0 user by the CURRENT email. The parameter is `email`
        // (`q` belongs to the /users search endpoint and is ignored here).
        $res = wp_remote_get( "https://{$domain}/api/v2/users-by-email?email=" . rawurlencode( $current_email ), [
            'headers' => [ 'Authorization' => 'Bearer ' . $token ],
        ] );
        if ( is_wp_error( $res ) ) return $res;
        $code = wp_remote_retrieve_response_code( $res );
        if ( 401 === $code ) { delete_option( 'stripe_shop_lite_auth0_m2m_token' ); return new WP_Error( 'auth0_401', 'Auth0 token rejected (it may have expired - try saving again).' ); }
        $users = json_decode( wp_remote_retrieve_body( $res ), true );
        if ( ! is_array( $users ) || empty( $users[0]['user_id'] ) ) {
            return new WP_Error( 'auth0_nouser', 'no Auth0 account found for this email' );
        }
        $auth0_id = $users[0]['user_id'];
        $connection = $users[0]['identities'][0]['connection'] ?? 'Username-Password-Authentication';

        // 1. Profile fields. verify_email only makes sense alongside a changed
        //    email, and Auth0 rejects it otherwise.
        if ( isset( $fields['email'] ) && 0 !== strcasecmp( $fields['email'], $current_email ) ) {
            $fields['verify_email'] = true;
        }
        $fields['connection'] = $connection;
        $res = wp_remote_request( "https://{$domain}/api/v2/users/{$auth0_id}", [
            'method'  => 'PATCH',
            'headers' => [ 'Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json' ],
            'body'    => wp_json_encode( $fields ),
        ] );
        if ( is_wp_error( $res ) ) return $res;
        if ( wp_remote_retrieve_response_code( $res ) >= 300 ) {
            $err = json_decode( wp_remote_retrieve_body( $res ), true );
            return new WP_Error( 'auth0_patch', $err['message'] ?? 'Auth0 update failed' );
        }

        // 2. Optional password change: separate PATCH; Auth0 emails the user a
        //    confirmation link and applies the new password only after they confirm.
        if ( $new_pass !== '' ) {
            $res = wp_remote_request( "https://{$domain}/api/v2/users/{$auth0_id}", [
                'method'  => 'PATCH',
                'headers' => [ 'Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json' ],
                'body'    => wp_json_encode( [ 'password' => $new_pass, 'connection' => $connection ] ),
            ] );
            if ( is_wp_error( $res ) ) return $res;
            if ( wp_remote_retrieve_response_code( $res ) >= 300 ) {
                $err = json_decode( wp_remote_retrieve_body( $res ), true );
                return new WP_Error( 'auth0_password', $err['message'] ?? 'Auth0 password change failed' );
            }
        }
        return true;
    }
}

function stripe_shop_lite_auth0_patch_user( $current_email, $fields, $new_pass = '' ) {
    return Stripe_Shop_Lite_Auth0::patch_user( $current_email, $fields, $new_pass );
}
