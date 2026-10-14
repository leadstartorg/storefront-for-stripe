<?php
/**
 * 5 + 9. Shortcodes Manager.
 *
 * [store_products]   product grid (demo styles demo-1...demo-9)
 * [store_filters]     catalog filter/sort bar (category, price range, search, sort)
 * [store_orders]      current user's orders (live from Stripe)
 * [store_subscriptions] current user's active subscriptions
 * [store_portal]      Stripe Customer Portal link
 * [store_restrict]    restrict content by role
 * [store_waitlist]     the signed-in customer's own watchlist
 * [store_my_account]   account dashboard (loads templates/my-account.php or dashboard.php)
 * [store_profile]      profile form (Auth0-synced when OIDC is configured)
 * [store_profile_notice] flash notices for the profile form
 * [store_cart], [store_cart_button]  cart shortcodes (Cart class)
 *
 * Also: front-end template loading with theme overrides
 * (yourtheme/stripe-shop-lite/*.php wins), archive filters (pre_get_posts),
 * and the wp-admin -> account-page redirect for customers.
 *
 * This file also defines, at the bottom, the helper layers shared with the
 * templates and the cart:
 *   - Variant readers: stripe_shop_lite_product_attributes(),
 *     stripe_shop_lite_product_variants(), stripe_shop_lite_find_variant()
 *     and stripe_shop_lite_attribute_images().
 *   - The comment rating system: a 1-5 star required picker on the product
 *     comment form, ratings stored as comment meta, star rendering on rated
 *     comments, averages synced to _stripe_shop_lite_rating,
 *     _stripe_shop_lite_rating_avg and _stripe_shop_lite_rating_count, and
 *     stripe_shop_lite_product_rating_summary().
 *   - The inline store-page styles (star picker, comment stars, summary
 *     and active filter chip state).
 * All of these are guarded with function_exists() so they can never cause a
 * redeclare fatal.
 *
 * The pre_get_posts handler applies the color and size chip filters
 * (?color=, ?size=) on product archives and product searches, matched against
 * the imported _stripe_shop_lite_variants meta; no theme functions.php
 * snippet is needed. The match pattern uses exact serialize() byte lengths
 * because WordPress escapes % wildcards in meta_query LIKE values.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class Stripe_Shop_Lite_Shortcodes {

    public static function init() {
        add_shortcode( 'store_products', [ __CLASS__, 'products' ] );
        add_shortcode( 'store_filters', [ __CLASS__, 'filters' ] );
        add_shortcode( 'store_orders', [ __CLASS__, 'orders' ] );
        add_shortcode( 'store_subscriptions', [ __CLASS__, 'subscriptions' ] );
        add_shortcode( 'store_portal', [ __CLASS__, 'portal' ] );
        add_shortcode( 'store_restrict', [ __CLASS__, 'restrict' ] );
        add_shortcode( 'store_waitlist', [ __CLASS__, 'waitlist' ] );
        add_shortcode( 'store_my_account', [ __CLASS__, 'my_account' ] );
        add_shortcode( 'store_profile', [ __CLASS__, 'profile' ] );
        add_shortcode( 'store_profile_notice', [ __CLASS__, 'profile_notice' ] );
        // Full-page templates (cart, checkout placeholder, profile settings).
        add_shortcode( 'store_cart_page', [ __CLASS__, 'cart_page' ] );
        add_shortcode( 'store_checkout_page', [ __CLASS__, 'checkout_page' ] );
        add_shortcode( 'store_settings_page', [ __CLASS__, 'settings_page' ] );

        add_action( 'template_redirect', [ __CLASS__, 'maybe_render_template' ] );
        add_action( 'pre_get_posts', [ __CLASS__, 'apply_filters' ] );
        // Runs last (priority 100000) so a theme or plugin search filter
        // registered at a later pre_get_posts priority cannot flip a store
        // search back to posts.
        add_action( 'pre_get_posts', [ __CLASS__, 'enforce_product_search_type' ], 100000 );
        add_filter( 'template_include', [ __CLASS__, 'product_search_template_routing' ], 99 );
        add_filter( 'posts_search', [ __CLASS__, 'product_search_slug' ], 10, 2 );
        add_action( 'template_redirect', [ __CLASS__, 'profile_save' ] );
        add_action( 'admin_init', [ __CLASS__, 'customers_to_account' ] );
    }

    /* ---------- Templates (theme override: yourtheme/stripe-shop-lite/) ---------- */

    public static function template_include( $template ) {
        if ( is_singular( 'product' ) ) {
            $override = locate_template( 'stripe-shop-lite/product-detail.php' );
            return $override ?: $template;
        }
        if ( is_tax( 'product_category' ) ) {
            $override = locate_template( 'stripe-shop-lite/product-category.php' );
            return $override ?: $template;
        }
        if ( is_tax( 'product_keyword' ) ) {
            $override = locate_template( 'stripe-shop-lite/product-keyword.php' );
            return $override ?: $template;
        }
        if ( is_post_type_archive( 'product' ) || is_tax( 'product_tag' ) ) {
            $override = locate_template( 'stripe-shop-lite/product-catalog.php' );
            return $override ?: $template;
        }
        return $template;
    }

    /**
     * Route product searches to the custom product-search.php template.
     *
     * A product search is explicitly identified by the hidden
     * post_type=product field. Normal WordPress/site searches are untouched.
     */
    public static function product_search_template_routing( $template ) {
        if (
            is_search()
            && isset( $_GET['post_type'] )
            && 'product' === sanitize_key( wp_unslash( $_GET['post_type'] ) )
        ) {
            $custom_template = locate_template(
                array(
                    'storefront-for-stripe/product-search.php',
                    'stripe-shop-lite/product-search.php',
                    'product-search.php',
                )
            );

            if ( ! empty( $custom_template ) ) {
                return $custom_template;
            }
        }

        return $template;
    }

    /**
     * Render the product page and the catalog. Stripe_Shop_Lite::load_template()
     * prefers yourtheme/storefront-for-stripe/<file> when it exists, so a theme
     * override goes through the same header/footer wrapper as the plugin's own
     * template (the old template_include route handed WordPress the unwrapped
     * override file, which rendered with no header or footer).
     */
    public static function maybe_render_template() {
        $file = '';
        if ( is_singular( 'product' ) ) {
            $file = 'product-detail.php';
        } elseif ( is_search() && Stripe_Shop_Lite_Shortcodes::is_product_search() ) {
            // A theme override is handled by template_include(). If none exists,
            // keep the plugin template fallback so product search still works.
            $theme_override = locate_template(
                array(
                    'storefront-for-stripe/product-search.php',
                    'stripe-shop-lite/product-search.php',
                    'product-search.php',
                )
            );
            if ( $theme_override ) return;
            $file = 'product-search.php';
        } elseif ( is_tax( 'product_category' ) ) {
            $file = 'product-category.php';
        } elseif ( is_tax( 'product_keyword' ) ) {
            $file = 'product-keyword.php';
        } elseif ( is_post_type_archive( 'product' ) || is_tax( 'product_tag' ) ) {
            $file = 'product-catalog.php';
        }
        if ( ! $file ) return;
        self::render_page( function () use ( $file ) {
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the template escapes its own output
            echo Stripe_Shop_Lite::load_template( $file );
        } );
        exit;
    }

    /**
     * Wrap content in the theme's header and footer.
     *
     * On a classic theme that is get_header()/get_footer(). On a block theme
     * get_header() outputs NOTHING (there is no header.php), so wp_head() and
     * wp_footer() never run and the page has no stylesheet, no store.js and no
     * site header - which is exactly "styling and functionality missing". Block
     * themes get a document shell with their own header/footer template parts.
     */
    public static function render_page( callable $content ) {
        if ( function_exists( 'wp_is_block_theme' ) && wp_is_block_theme() ) {
            ?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head><meta charset="<?php bloginfo( 'charset' ); ?>"><meta name="viewport" content="width=device-width, initial-scale=1"><?php wp_head(); ?></head>
<body <?php body_class(); ?>>
<?php wp_body_open(); ?>
<div class="wp-site-blocks">
<?php block_header_area(); ?>
<main class="wp-block-group stripe-shop-lite-main"><?php $content(); ?></main>
<?php block_footer_area(); ?>
</div>
<?php wp_footer(); ?>
</body>
</html><?php
            return;
        }
        get_header();
        $content();
        get_footer();
    }

    /* ---------- [store_products] ---------- */

    public static function products( $atts ) {
        $a = shortcode_atts( [ 'limit' => 0, 'columns' => 3, 'category' => '', 'style' => '', 'pagination' => '' ], $atts );
        $limit = (int) $a['limit'] ?: max( 1, (int) stripe_shop_lite_opt( 'product_limit', 24 ) );
        $args = [ 'post_type' => 'product', 'post_status' => 'publish', 'posts_per_page' => $limit,
            'meta_query' => [ [ 'key' => '_from_woocommerce', 'compare' => 'NOT EXISTS' ] ] ];
        if ( $a['category'] ) $args['tax_query'] = [ [ 'taxonomy' => 'product_category', 'field' => 'slug', 'terms' => $a['category'] ] ];

        $mode = $a['pagination'] ?: stripe_shop_lite_opt( 'pagination', 'numbered' );
        if ( 'none' === $mode ) {
            $args['no_found_rows'] = true;
        } elseif ( 'numbered' === $mode ) {
            $args['paged'] = max( 1, (int) get_query_var( 'paged' ), absint( wp_unslash( $_GET['pg'] ?? 0 ) ) );
        }
        $q = new WP_Query( $args );
        $style = preg_match( '/^demo-[1-9]$/', (string) $a['style'] ) ? $a['style'] : '';
        $cols = max( 1, min( 4, (int) $a['columns'] ) );
        ob_start();
        echo '<div class="row row-cols-1 row-cols-sm-2 row-cols-lg-' . esc_attr( $cols ) . ' g-4 stripe-shop-lite-products" data-pagination="' . esc_attr( $mode ) . '">';
        while ( $q->have_posts() ) { $q->the_post(); echo stripe_shop_lite_product_card( get_the_ID(), $style ); } // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside stripe_shop_lite_product_card()
        echo '</div>';
        if ( 'numbered' === $mode ) {
            echo '<nav class="stripe-shop-lite-pagination">' . wp_kses_post( paginate_links( [
                'total'     => $q->max_num_pages,
                'current'   => $args['paged'],
                'prev_text' => 'Prev', 'next_text' => 'Next',
            ] ) ) . '</nav>';
        }
        wp_reset_postdata();
        return ob_get_clean();
    }

    /* ---------- [store_filters] + archive query filters ---------- */

    public static function filters() {
        $cats = get_terms( [ 'taxonomy' => 'product_category', 'hide_empty' => true ] );
        $cur  = function ( $k, $d = '' ) { return isset( $_GET[ $k ] ) ? sanitize_text_field( wp_unslash( $_GET[ $k ] ) ) : $d; };
        ob_start(); ?>
        <form class="stripe-shop-lite-filters" method="get" action="<?php echo esc_url( get_post_type_archive_link( 'product' ) ?: home_url( '/product/' ) ); ?>">
            <div class="stripe-shop-lite-filter-row">
                <label class="stripe-shop-lite-filter">
                    <span>Search</span>
                    <input type="search" name="s" value="<?php echo esc_attr( $cur( 's' ) ); ?>" placeholder="Search products..." />
                    <input type="hidden" name="post_type" value="product" />
                </label>
                <label class="stripe-shop-lite-filter">
                    <span>Category</span>
                    <select name="pcat">
                        <option value="">All categories</option>
                        <?php foreach ( $cats as $c ) : ?>
                            <option value="<?php echo esc_attr( $c->slug ); ?>" <?php selected( $cur( 'pcat' ), $c->slug ); ?>><?php echo esc_html( $c->name ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="stripe-shop-lite-filter">
                    <span>Price</span>
                    <span class="stripe-shop-lite-price-range">
                        <input type="number" name="min" min="0" step="0.01" placeholder="min" value="<?php echo esc_attr( $cur( 'min' ) ); ?>" />
                        <span>-</span>
                        <input type="number" name="max" min="0" step="0.01" placeholder="max" value="<?php echo esc_attr( $cur( 'max' ) ); ?>" />
                    </span>
                </label>
                <label class="stripe-shop-lite-filter">
                    <span>Sort</span>
                    <select name="sort">
                        <option value="">Default</option>
                        <option value="price" <?php selected( $cur( 'sort' ), 'price' ); ?>>Price: low -> high</option>
                        <option value="price-desc" <?php selected( $cur( 'sort' ), 'price-desc' ); ?>>Price: high -> low</option>
                        <option value="newest" <?php selected( $cur( 'sort' ), 'newest' ); ?>>Newest</option>
                        <option value="title" <?php selected( $cur( 'sort' ), 'title' ); ?>>Title A-Z</option>
                    </select>
                </label>
                <?php
                /*
                 * Active color/size chip selections are carried through the
                 * form's GET submit as hidden inputs: a GET form rebuilds the
                 * query string from its own fields only. Rendered only when
                 * a chip is active so the URL stays clean.
                 */
                foreach ( [ 'color', 'size' ] as $chip_arg ) {
                    if ( $cur( $chip_arg ) ) : ?>
                        <input type="hidden" name="<?php echo esc_attr( $chip_arg ); ?>" value="<?php echo esc_attr( $cur( $chip_arg ) ); ?>" />
                    <?php endif;
                }
                ?>
                <button type="submit" class="stripe-shop-lite-filter-apply">Apply</button>
            </div>
        </form>
        <?php return ob_get_clean();
    }

    public static function is_product_search() {
        $pt = get_query_var( 'post_type' );

        // Product searches are explicitly marked by the hidden post_type=product
        // field in the product search/filter form. This keeps the product search
        // isolated from the normal site/header search.
        if ( isset( $_GET['post_type'] ) ) {
            $raw_pt = wp_unslash( $_GET['post_type'] );
            $pt = is_array( $raw_pt ) ? array_map( 'sanitize_key', $raw_pt ) : sanitize_key( $raw_pt );
        }

        return 'product' === $pt || ( is_array( $pt ) && in_array( 'product', $pt, true ) );
    }

    public static function product_search_slug( $search, $query ) {
        // Only touch product searches. A normal site/header search must keep
        // WordPress's native search SQL completely unchanged.
        if ( is_admin() || ! $query->is_main_query() || ! self::is_product_search() ) return $search;

        $term = trim( (string) $query->get( 's' ) );
        if ( '' === $term ) return $search;

        global $wpdb;
        $slug = sanitize_title_with_dashes( $term );
        if ( '' === $slug ) return $search;

        // WordPress already searches post_title, post_excerpt and
        // post_content. Add post_name so a product slug such as
        // "full-access" is searchable too.
        $slug_sql = $wpdb->prepare(
            $wpdb->posts . '.post_name LIKE %s',
            '%' . $wpdb->esc_like( $slug ) . '%'
        );

        if ( '' === trim( (string) $search ) ) {
            return ' AND (' . $slug_sql . ') ';
        }

        // Put the slug condition INSIDE WordPress's existing search group.
        // This avoids changing SQL precedence and avoids turning the slug
        // condition into a separate top-level OR expression.
        $trimmed = rtrim( (string) $search );
        $last_close = strrpos( $trimmed, ')' );
        if ( false !== $last_close ) {
            return substr( $trimmed, 0, $last_close )
                . ' OR (' . $slug_sql . ')'
                . substr( $trimmed, $last_close )
                . ' ';
        }

        return $trimmed . ' AND (' . $slug_sql . ') ';
    }

    public static function apply_filters( $query ) {
        if ( is_admin() || ! $query->is_main_query() ) return;

        $on_archive = is_post_type_archive( 'product' ) || is_tax( [ 'product_category', 'product_keyword', 'product_tag' ] );

        // Product searches are identified by the same helper used everywhere
        // else in the plugin. The helper checks the explicit post_type=product
        // request value and falls back to WordPress's parsed query var.
        $on_search = $query->is_search() && self::is_product_search();

        if ( ! $on_archive && ! $on_search ) return;

        // Product filters must never alter a normal header/site search.
        if ( $query->is_search() && ! $on_search ) return;

        // Restrict product archives and explicit product searches to products.
        if ( $on_archive || $on_search ) {
            $query->set( 'post_type', 'product' );
        }

        $meta = $query->get( 'meta_query' ) ?: [];
        $min = isset( $_GET['min'] ) && is_numeric( $_GET['min'] ) ? (float) $_GET['min'] : null;
        $max = isset( $_GET['max'] ) && is_numeric( $_GET['max'] ) ? (float) $_GET['max'] : null;
        if ( $min !== null || $max !== null ) {
            $range = [ 'key' => '_display_price', 'type' => 'DECIMAL(10,2)', 'compare' => 'BETWEEN' ];
            if ( $min !== null && $max !== null ) { $range['value'] = [ $min, $max ]; }
            elseif ( $min !== null ) { $range['compare'] = '>='; $range['value'] = $min; }
            else { $range['compare'] = '<='; $range['value'] = $max; }
            $meta[] = $range;
        }

        /*
         * Color / size chips (functional filters: ?color=green&size=XXL).
         * Everything lives in the plugin - no theme functions.php snippet.
         * The imported variant table (_stripe_shop_lite_variants) is stored
         * as a serialized PHP array; every attribute pair appears as
         *   s:LEN:"key";s:LEN:"value"
         * adjacent in the serialization, so a substring match on that exact
         * pair hits exactly the products whose variants include it and can
         * never cross-match another attribute.
         *
         * The value must not contain % wildcards: WordPress escapes
         * meta_query LIKE values with $wpdb->esc_like() and wraps them in
         * %...% itself, so a literal % would match only an escaped percent
         * sign. The serialized form is fully predictable, so the pattern is
         * built with exact byte lengths; the meta_query supplies the
         * surrounding %...% substring match.
         */
        foreach ( [ 'color', 'size' ] as $attr ) {
            if ( ! isset( $_GET[ $attr ] ) ) continue;
            $val = sanitize_text_field( wp_unslash( $_GET[ $attr ] ) );
            $val = str_replace( [ '%', '_' ], '', $val ); // keep LIKE wildcards out of the input
            if ( '' === $val ) continue;
            $meta[] = [
                'key'     => '_stripe_shop_lite_variants',
                'value'   => sprintf( 's:%d:"%s";s:%d:"%s"', strlen( $attr ), $attr, strlen( $val ), $val ),
                'compare' => 'LIKE',
            ];
        }

        if ( $meta ) $query->set( 'meta_query', $meta );

        // Archives and product searches use the configured product limit.
        if ( $on_archive || $on_search ) {
            $query->set( 'posts_per_page', max( 1, (int) stripe_shop_lite_opt( 'product_limit', 24 ) ) );
        }

        // `cat` is WordPress's own category query var; `pcat` is ours.
        if ( ! empty( $_GET['pcat'] ) ) {
            $query->set( 'tax_query', [ [ 'taxonomy' => 'product_category', 'field' => 'slug', 'terms' => sanitize_title( wp_unslash( $_GET['pcat'] ) ) ] ] );
        }

        if ( ! empty( $_GET['sort'] ) ) {
            switch ( $_GET['sort'] ) {
                case 'price':
                    $query->set( 'meta_key', '_display_price' ); $query->set( 'orderby', 'meta_value_num' ); $query->set( 'order', 'ASC' ); break;
                case 'price-desc':
                    $query->set( 'meta_key', '_display_price' ); $query->set( 'orderby', 'meta_value_num' ); $query->set( 'order', 'DESC' ); break;
                case 'newest':
                    $query->set( 'orderby', 'date' ); $query->set( 'order', 'DESC' ); break;
                case 'title':
                    $query->set( 'orderby', 'title' ); $query->set( 'order', 'ASC' ); break;
            }
        }
    }

    /**
     * Re-asserts the product restriction on store searches. apply_filters
     * runs at the default priority, so a theme or plugin filter registered
     * later could still flip the search to posts; this hook runs at priority
     * 100000, after every normal pre_get_posts callback.
     */
    public static function enforce_product_search_type( $query ) {
        if ( is_admin() || ! $query->is_main_query() ) return;
        if ( ! $query->is_search() || ! self::is_product_search() ) return;
        $query->set( 'post_type', 'product' );
        $query->set( 'post_status', 'publish' );
    }

    /* ---------- [store_orders] / [store_subscriptions] ---------- */

    public static function orders() {
        if ( ! is_user_logged_in() || ! stripe_shop_lite_stripe() ) return '';
        $cid = stripe_shop_lite_customer_id( get_current_user_id() );
        if ( ! $cid ) return '<p>No purchases yet.</p>';
        $out = '<table class="stripe-shop-lite-orders"><tr><th>Date</th><th>Amount</th><th>Type</th><th>Receipt</th></tr>';
        try {
            $charges = \Stripe\Charge::all( [ 'customer' => $cid, 'limit' => 20 ] );
            if ( ! count( $charges->data ) ) return '<p>No purchases yet.</p>';
            foreach ( $charges->data as $ch ) {
                $out .= sprintf( '<tr><td>%s</td><td>%s</td><td>%s</td><td><a href="%s" target="_blank">View in Stripe</a></td></tr>',
                    esc_html( wp_date( get_option( 'date_format' ), $ch->created ) ),
                    esc_html( number_format( $ch->amount / 100, 2 ) ),
                    $ch->refunded ? 'refunded' : 'payment',
                    esc_url( $ch->receipt_url ?? '#' ) );
            }
        } catch ( \Exception $e ) { return '<p>Orders are unavailable right now.</p>'; }
        return $out . '</table>';
    }

    public static function subscriptions() {
        if ( ! is_user_logged_in() || ! stripe_shop_lite_stripe() ) return '';
        $cid = stripe_shop_lite_customer_id( get_current_user_id() );
        if ( ! $cid ) return '<p>No subscriptions.</p>';
        try {
            $subs = \Stripe\Subscription::all( [ 'customer' => $cid, 'limit' => 20 ] );
            $active = array_filter( $subs->data, function ( $s ) { return in_array( $s->status, [ 'active', 'past_due' ], true ); } );
            if ( ! $active ) return '<p>No active subscriptions.</p>';
            $out = '<ul>';
            foreach ( $active as $s ) {
                // Older API versions put current_period_end on the subscription; 2025-03-31+ puts it on each item.
                $end = $s->current_period_end ?? ( $s->items->data[0]->current_period_end ?? null );
                $out .= '<li>' . esc_html( $end ? wp_date( get_option( 'date_format' ), (int) $end ) : '-' ) . ' - renews (' . esc_html( $s->status ) . ')</li>';
            }
            return $out . '</ul>';
        } catch ( \Exception $e ) { return '<p>Subscriptions are unavailable right now.</p>'; }
    }

    /* ---------- [store_portal] - Stripe Customer Portal ---------- */

    public static function portal( $atts ) {
        $a = shortcode_atts( [ 'text' => 'Manage my billing' ], $atts );
        if ( ! is_user_logged_in() || ! stripe_shop_lite_stripe() ) return '';
        $cid = stripe_shop_lite_customer_id( get_current_user_id(), false );
        if ( ! $cid ) return '';
        try {
            $session = \Stripe\BillingPortal\Session::create( [ 'customer' => $cid, 'return_url' => home_url( stripe_shop_lite_opt( 'account_page', '/' ) ) ] );
            return sprintf( '<a href="%s">%s</a>', esc_url( $session->url ), esc_html( $a['text'] ) );
        } catch ( \Exception $e ) { return ''; }
    }

    /* ---------- [store_restrict] ---------- */

    public static function restrict( $atts, $content = '' ) {
        $a = shortcode_atts( [ 'roles' => 'plan_subscriber,plan_member', 'unregistered' => 'Sign in to access this content.', 'unauthorized' => 'This content is for subscribers.' ], $atts );
        if ( ! is_user_logged_in() ) return '<div class="stripe-shop-lite-restricted">' . wp_kses_post( $a['unregistered'] ) . '</div>';
        $u = wp_get_current_user();
        $allowed = array_map( 'trim', explode( ',', $a['roles'] ) );
        $match = array_intersect( $allowed, (array) $u->roles );
        if ( $match ) return do_shortcode( $content );
        return '<div class="stripe-shop-lite-restricted">' . wp_kses_post( $a['unauthorized'] ) . '</div>';
    }

    /* ---------- [store_waitlist] ---------- */

    public static function waitlist() {
        if ( ! is_user_logged_in() ) return '<p>Sign in to see your watchlist.</p>';
        $email = wp_get_current_user()->user_email;
        $items = [];
        foreach ( get_posts( [ 'post_type' => 'product', 'posts_per_page' => -1, 'post_status' => 'publish', 'meta_key' => '_stripe_shop_lite_waitlist', 'fields' => 'ids' ] ) as $pid ) {
            $wl = get_post_meta( $pid, '_stripe_shop_lite_waitlist', true );
            if ( is_array( $wl ) && in_array( $email, $wl, true ) ) {
                $items[] = '<li><a href="' . esc_url( get_permalink( $pid ) ) . '">' . esc_html( get_the_title( $pid ) ) . '</a></li>';
            }
        }
        if ( ! $items ) return '<p>You are not watching any products.</p>';
        return '<ul>' . implode( '', $items ) . '</ul>';
    }

    /* ---------- [store_my_account] - loads templates/my-account.php or dashboard.php ---------- */

    public static function my_account() {
        if ( ! is_user_logged_in() ) {
            return '<p>Please <a href="' . esc_url( wp_login_url( get_permalink() ) ) . '">sign in</a> to see your account.</p>';
        }
        $u = wp_get_current_user();
        $is_staff = user_can( $u, 'manage_options' ) || in_array( 'stripe_shop_manager', (array) $u->roles, true );
        return Stripe_Shop_Lite::load_template( $is_staff ? 'dashboard.php' : 'my-account.php', [ 'user' => $u, 'is_staff' => $is_staff ] );
    }

    /* ---------- [store_profile] (Auth0-synced via the Auth0 class) ---------- */

    public static function profile() {
        if ( ! is_user_logged_in() ) {
            return '<p>Please <a href="' . esc_url( wp_login_url( get_permalink() ) ) . '">sign in</a> to edit your profile.</p>';
        }
        $u = wp_get_current_user();
        $first = get_user_meta( $u->ID, 'first_name', true );
        $last  = get_user_meta( $u->ID, 'last_name', true );
        $oidc_active = (bool) stripe_shop_lite_opt( 'auth0_domain' );
        ob_start(); ?>
        <form class="stripe-shop-lite-profile" method="post">
            <?php wp_nonce_field( 'stripe_shop_lite_profile', 'stripe_shop_lite_profile_nonce' ); ?>
            <div class="stripe-shop-lite-profile-field">
                <label>First name<br /><input type="text" name="stripe_shop_lite_first_name" value="<?php echo esc_attr( $first ); ?>" required /></label>
            </div>
            <div class="stripe-shop-lite-profile-field">
                <label>Last name<br /><input type="text" name="stripe_shop_lite_last_name" value="<?php echo esc_attr( $last ); ?>" required /></label>
            </div>
            <div class="stripe-shop-lite-profile-field">
                <label>Email<br /><input type="email" name="stripe_shop_lite_email" value="<?php echo esc_attr( $u->user_email ); ?>" required /></label>
            </div>
            <?php if ( $oidc_active ) : ?>
                <div class="stripe-shop-lite-profile-field">
                    <label>New password <em>(leave blank to keep your current one)</em><br /><input type="password" name="stripe_shop_lite_new_pass" autocomplete="new-password" /></label>
                </div>
                <div class="stripe-shop-lite-profile-field">
                    <label>Confirm new password<br /><input type="password" name="stripe_shop_lite_new_pass2" autocomplete="new-password" /></label>
                </div>
                <p class="stripe-shop-lite-profile-note">Your name, email and password belong to your sign-in account (Auth0) - saving here updates that account; WordPress follows on your next sign-in. A new password takes effect immediately. If you change your email, Auth0 sends a verification link to the new address.</p>
            <?php else : ?>
                <p class="stripe-shop-lite-profile-note">Changing your email updates your account on this site. Your password is managed on the <a href="<?php echo esc_url( wp_lostpassword_url() ); ?>">password reset page</a>.</p>
            <?php endif; ?>
            <button type="submit" name="stripe_shop_lite_profile_save" value="1">Save profile</button>
        </form>
        <?php return ob_get_clean();
    }

    /** Handle the save: Post-Redirect-Get with a transient flash. */
    public static function profile_save() {
        if ( empty( $_POST['stripe_shop_lite_profile_save'] ) ) return;
        if ( ! is_user_logged_in() ) return;
        check_admin_referer( 'stripe_shop_lite_profile', 'stripe_shop_lite_profile_nonce' );

        $user_id = get_current_user_id();
        $u = wp_get_current_user();
        $oidc_active = (bool) stripe_shop_lite_opt( 'auth0_domain' );

        $first = sanitize_text_field( wp_unslash( $_POST['stripe_shop_lite_first_name'] ?? '' ) );
        $last  = sanitize_text_field( wp_unslash( $_POST['stripe_shop_lite_last_name'] ?? '' ) );
        $email = sanitize_email( wp_unslash( $_POST['stripe_shop_lite_email'] ?? '' ) );
        $pass  = (string) wp_unslash( $_POST['stripe_shop_lite_new_pass'] ?? '' );
        $pass2 = (string) wp_unslash( $_POST['stripe_shop_lite_new_pass2'] ?? '' );

        $errors = [];
        if ( ! $first || ! $last ) $errors[] = 'First and last name are required.';
        if ( ! $email || ! is_email( $email ) ) $errors[] = 'A valid email is required.';
        if ( $email && $email !== $u->user_email && email_exists( $email ) ) $errors[] = 'That email is already used by another account.';
        if ( $pass !== '' ) {
            if ( strlen( $pass ) < 8 ) $errors[] = 'The new password must be at least 8 characters.';
            if ( $pass !== $pass2 ) $errors[] = 'The two password fields do not match.';
            if ( ! $oidc_active ) $errors[] = 'Password changes are not available on this site.';
        }

        $flash = function ( $ok, $msg ) use ( $user_id ) {
            set_transient( 'stripe_shop_lite_profile_notice_' . $user_id, [ 'ok' => $ok, 'msg' => $msg ], 60 );
            wp_safe_redirect( add_query_arg( 'stripe_shop_lite_profile', 'saved', wp_get_referer() ?: home_url( '/' ) ) );
            exit;
        };

        if ( ! $errors && $oidc_active ) {
            // 1. Update Auth0 FIRST - it is the source of truth for identity.
            $fields = [
                'email'        => $email,
                'given_name'  => $first,
                'family_name' => $last,
                'name'        => trim( $first . ' ' . $last ),
            ];
            $result = Stripe_Shop_Lite_Auth0::patch_user( $u->user_email, $fields, $pass );
            if ( is_wp_error( $result ) ) {
                $flash( false, 'Could not update your sign-in account (' . $result->get_error_message() . '). Nothing was changed - please try again or contact us.' );
            }
            // 2. Auth0 accepted - mirror into WordPress so this site matches immediately.
            update_user_meta( $user_id, 'first_name', $first );
            update_user_meta( $user_id, 'last_name', $last );
            wp_update_user( [ 'ID' => $user_id, 'user_email' => $email, 'display_name' => trim( $first . ' ' . $last ) ] );
            $flash( true, $pass !== ''
                ? 'Profile updated. Your new password is in effect now.'
                : 'Profile updated.' );
        }

        if ( ! $errors && ! $oidc_active ) {
            update_user_meta( $user_id, 'first_name', $first );
            update_user_meta( $user_id, 'last_name', $last );
            wp_update_user( [ 'ID' => $user_id, 'user_email' => $email, 'display_name' => trim( $first . ' ' . $last ) ] );
            $flash( true, 'Profile updated.' );
        }
        $flash( false, implode( ' ', $errors ) );
    }

    public static function profile_notice() {
        if ( ! is_user_logged_in() ) return '';
        $n = get_transient( 'stripe_shop_lite_profile_notice_' . get_current_user_id() );
        if ( ! $n ) return '';
        delete_transient( 'stripe_shop_lite_profile_notice_' . get_current_user_id() );
        return '<div class="stripe-shop-lite-notice ' . ( $n['ok'] ? 'stripe-shop-lite-notice-ok' : 'stripe-shop-lite-notice-err' ) . '">' . esc_html( $n['msg'] ) . '</div>';
    }

    /* ---------- Customers land on the account page, not wp-admin ---------- */

    public static function customers_to_account() {
        if ( defined( 'DOING_AJAX' ) || wp_doing_cron() ) return;
        if ( stripe_shop_lite_is_exempt( get_current_user_id() ) ) return;
        $account = stripe_shop_lite_opt( 'account_page' );
        if ( $account ) { wp_safe_redirect( home_url( $account ) ); exit; }
    }

    /* ---------- Full-page templates ---------- */

    // These render the templates/ files (with theme override support) so the
    // /cart/, /checkout/ and /settings/ pages have a real layout. Before, the
    // template files existed but nothing loaded them.
    public static function cart_page() {
        return Stripe_Shop_Lite::load_template( 'cart.php' );
    }

    public static function checkout_page() {
        return Stripe_Shop_Lite::load_template( 'checkout.php' );
    }

    public static function settings_page() {
        return Stripe_Shop_Lite::load_template( 'settings.php' );
    }
}

/*
 * Product card rendering - the nine shopping demos (demo-1 through demo-9).
 * Used by [store_products], the /product/ archive and product-catalog.php.
 */

/**
 * Gallery image URLs for a product.
 *
 * The featured image is always first (this is the single Stripe/primary image).
 * Additional images are read from the Product gallery metabox stored in
 * _product_image_gallery as a comma-separated list of attachment IDs.
 * Older products without that meta retain the attachment fallback.
 */
function stripe_shop_lite_gallery_imgs( $id ) {
    $imgs = [];

    if ( has_post_thumbnail( $id ) ) {
        $featured = get_the_post_thumbnail_url( $id, 'large' );
        if ( $featured ) $imgs[] = $featured;
    }

    $gallery = get_post_meta( $id, '_product_image_gallery', true );
    $gallery_ids = [];
    if ( is_string( $gallery ) && '' !== trim( $gallery ) ) {
        $gallery_ids = array_values( array_filter( array_map( 'absint', explode( ',', $gallery ) ) ) );
    }

    foreach ( $gallery_ids as $aid ) {
        $u = wp_get_attachment_image_url( $aid, 'large' );
        if ( $u && ! in_array( $u, $imgs, true ) ) $imgs[] = $u;
    }

    // Backward compatibility for older products that used parent attachments.
    if ( ! $gallery_ids ) {
        $att = get_posts( [
            'post_type'      => 'attachment',
            'post_mime_type' => 'image',
            'post_parent'    => $id,
            'posts_per_page' => -1,
            'orderby'        => 'menu_order date',
            'order'          => 'ASC',
            'fields'         => 'ids',
        ] );
        foreach ( $att as $aid ) {
            $u = wp_get_attachment_image_url( $aid, 'large' );
            if ( $u && ! in_array( $u, $imgs, true ) ) $imgs[] = $u;
        }
    }

    return $imgs;
}

/** Optional star rating 0-5, stored as _stripe_shop_lite_rating. */
function stripe_shop_lite_breadcrumbs( $current = '' ) {
    if ( is_front_page() || is_home() ) return '';
    $items = [ [ 'label' => 'Home', 'url' => home_url( '/' ) ] ];
    $archive = get_post_type_archive_link( 'product' );
    if ( is_singular( 'product' ) ) {
        $items[] = [ 'label' => 'Products', 'url' => $archive ];
        $items[] = [ 'label' => $current ?: get_the_title(), 'url' => '' ];
    } elseif ( is_post_type_archive( 'product' ) ) {
        $items[] = [ 'label' => 'Products', 'url' => '' ];
    } elseif ( is_tax( [ 'product_category', 'product_keyword', 'product_tag' ] ) ) {
        $items[] = [ 'label' => 'Products', 'url' => $archive ];
        $items[] = [ 'label' => single_term_title( '', false ), 'url' => '' ];
    } elseif ( is_search() && Stripe_Shop_Lite_Shortcodes::is_product_search() ) {
        $items[] = [ 'label' => 'Products', 'url' => $archive ];
        $items[] = [ 'label' => 'Search', 'url' => '' ];
    } else {
        return '';
    }
    $out = '<nav aria-label="breadcrumb" class="stripe-shop-lite-breadcrumbs"><ol class="breadcrumb mb-4">';
    $last = count( $items ) - 1;
    foreach ( $items as $i => $item ) {
        $out .= $i === $last ? '<li class="breadcrumb-item active" aria-current="page">' . esc_html( $item['label'] ) . '</li>' : '<li class="breadcrumb-item"><a href="' . esc_url( $item['url'] ) . '">' . esc_html( $item['label'] ) . '</a></li>';
    }
    return $out . '</ol></nav>';
}

function stripe_shop_lite_rating( $id ) {
    $r = get_post_meta( $id, '_stripe_shop_lite_rating', true );
    if ( $r === '' || $r === null ) return null;
    return max( 0, min( 5, (int) $r ) );
}

function stripe_shop_lite_product_card( $id, $style_override = '' ) {
    $id = absint( $id );
    $style = preg_match( '/^demo-[1-9]$/', (string) $style_override ) ? (string) $style_override : (string) stripe_shop_lite_opt( 'card_style' );
    if ( ! preg_match( '/^demo-[1-9]$/', $style ) ) $style = 'demo-2';
    $n = (int) substr( $style, 5 );

    $link    = get_permalink( $id );
    $imgs    = stripe_shop_lite_gallery_imgs( $id );
    $pic1    = $imgs[0] ?? '';
    $pic2    = $imgs[1] ?? $pic1;
    /*
     * Attribute-value images: when the catalog is filtered by a color and
     * the product has an image mapped for that value, the card shows the
     * mapped image instead of the generic gallery shot. Products without a
     * mapped image are untouched.
     */
    if ( isset( $_GET['color'] ) && function_exists( 'stripe_shop_lite_attribute_images' ) ) {
        $cur_color = sanitize_text_field( wp_unslash( $_GET['color'] ) );
        $color_imgs = stripe_shop_lite_attribute_images( $id, 'color' );
        if ( $cur_color && ! empty( $color_imgs[ $cur_color ] ) ) {
            $pic1 = $color_imgs[ $cur_color ];
            $pic2 = $imgs[0] ?? $pic1;
        }
    }
    $stock   = get_post_meta( $id, '_stock_level', true );
    $price_id = get_post_meta( $id, '_stripe_price_id', true );
    $buyable = $price_id && ( $stock === '' || (int) $stock > 0 );
    $soldout = $stock !== '' && (int) $stock === 0;
    $dp  = get_post_meta( $id, '_display_price', true );
    $cur = get_post_meta( $id, '_display_currency', true );
    $rec = get_post_meta( $id, '_display_recurring', true );
    if ( ! $dp ) { $pi = stripe_shop_lite_price_info( $price_id ); if ( $pi ) { $dp = number_format( $pi['unit'] / 100, 2, '.', '' ); $cur = strtoupper( $pi['currency'] ); $rec = $pi['recurring'] ? '1' : $rec; } }
    $is_new = ( time() - (int) get_post_time( 'U', true, $id ) ) < 30 * DAY_IN_SECONDS;

    // Products without a picture get the bundled placeholder (assets/placeholder.svg).
    $placeholder = plugins_url( 'assets/placeholder.svg', STRIPE_SHOP_LITE_FILE );
    $img_html = $pic1
        ? '<img class="stripe-shop-lite-pic-1" src="' . esc_url( $pic1 ) . '" alt="' . esc_attr( get_the_title( $id ) ) . '">'
          . ( $pic2 && $pic2 !== $pic1 ? '<img class="stripe-shop-lite-pic-2" src="' . esc_url( $pic2 ) . '" alt="">' : '' )
        : '<img class="stripe-shop-lite-pic-1 stripe-shop-lite-card-noimg" src="' . esc_url( $placeholder ) . '" alt="' . esc_attr( get_the_title( $id ) ) . '">';

    // Hover icon row: Quick view + cart / waitlist + heart/like.
    // Simple Likes supplies the real like button when available.
    $liked_by_user = false;
    $created_by_user = false;
    $liked_meta = get_post_meta( $id, '_user_liked', true );
    $current_user_id = get_current_user_id();
    if ( is_array( $liked_meta ) && $current_user_id ) {
        $liked_ids = array_map( 'intval', $liked_meta );
        $liked_by_user = in_array( (int) $current_user_id, $liked_ids, true );
    } elseif ( $current_user_id && is_numeric( $liked_meta ) ) {
        $liked_by_user = (int) $liked_meta === (int) $current_user_id;
    } elseif ( is_bool( $liked_meta ) ) {
        $liked_by_user = $liked_meta;
    } elseif ( '1' === (string) $liked_meta || 'true' === strtolower( (string) $liked_meta ) ) {
        $liked_by_user = true;
    }
    $created_by_user = $current_user_id && (int) get_post_field( 'post_author', $id ) === (int) $current_user_id;

    $icons = '<li><a href="' . esc_url( $link ) . '" class="stripe-shop-lite-quickview" data-tip="Quick View"><i class="fa fa-eye"></i></a></li>';
    if ( $buyable ) $icons .= '<li><a href="' . esc_url( $link ) . '" class="stripe-shop-lite-add" data-id="' . $id . '" data-tip="Add to Cart"><i class="fa fa-shopping-cart"></i></a></li>';
    elseif ( $soldout ) $icons .= '<li><a href="' . esc_url( $link ) . '" class="stripe-shop-lite-waitlist-open" data-id="' . $id . '" data-tip="Notify me"><i class="fa fa-envelope-o"></i></a></li>';

    if ( function_exists( 'get_simple_likes_button' ) ) {
        $like_button = get_simple_likes_button( $id, 0 );
    } else {
        $like_button = '<a href="' . esc_url( $link ) . '" class="stripe-shop-lite-like-fallback" data-tip="Like" aria-label="Like"><i class="fa fa-heart"></i></a>';
    }
    $icons .= '<li class="stripe-shop-lite-like-item" data-is-liked="' . ( $liked_by_user ? 'true' : 'false' ) . '" data-is-created="' . ( $created_by_user ? 'true' : 'false' ) . '">' . $like_button . '</li>';

    if ( $buyable ) {
        $action = '<a class="stripe-shop-lite-add stripe-shop-lite-cart-btn btn" href="' . esc_url( $link ) . '" data-id="' . $id . '">Add to cart</a>';
    } elseif ( $soldout ) {
        $action = '<a class="stripe-shop-lite-waitlist-open btn" href="' . esc_url( $link ) . '" data-id="' . $id . '">Sold out - notify me</a>';
    } else {
        $action = '<span class="stripe-shop-lite-unavailable">Currently unavailable</span>';
    }

    $price_html = $dp ? '<div class="stripe-shop-lite-pprice">' . esc_html( stripe_shop_lite_money_label( $dp, $cur ) ) . ( $rec ? '<span class="stripe-shop-lite-recurring">/mo</span>' : '' ) . '</div>' : '';

    $rating_html = '';
    $r = stripe_shop_lite_rating( $id );
    if ( $r !== null ) {
        $rating_html = '<ul class="stripe-shop-lite-prating">';
        for ( $i = 1; $i <= 5; $i++ ) $rating_html .= '<li class="fa fa-star' . ( $i > $r ? ' disable' : '' ) . '"></li>';
        $rating_html .= '</ul>';
    }

    $new_label = $is_new ? '<span class="stripe-shop-lite-new-label">New</span>' : '';
    // Demo 2 puts its Add-to-cart bar inside the image area.
    $img_action = ( 2 === $n && $buyable ) ? '<a class="stripe-shop-lite-cart-btn stripe-shop-lite-add" href="' . esc_url( $link ) . '" data-id="' . $id . '">Add to cart</a>' : '';

    ob_start(); ?>
    <div class="col">
        <div class="stripe-shop-lite-pgrid<?php echo esc_attr( $n ); ?>" data-is-liked="<?php echo $liked_by_user ? 'true' : 'false'; ?>" data-is-created="<?php echo $created_by_user ? 'true' : 'false'; ?>">
            <div class="stripe-shop-lite-pimg<?php echo esc_attr( $n ); ?>">
                <a href="<?php echo esc_url( $link ); ?>"><?php echo wp_kses_post( $img_html ); ?></a>
                <ul class="stripe-shop-lite-psocial"><?php echo $icons; // phpcs:ignore WordPress.Security.EscapeOutput -- the composite is built with escaping above and may embed third-party like-button HTML ?></ul>
                <?php echo wp_kses_post( $new_label . $img_action ); ?>
            </div>
            <div class="stripe-shop-lite-pcontent">
                <?php echo wp_kses_post( $rating_html ); ?>
                <h3 class="stripe-shop-lite-ptitle"><a href="<?php echo esc_url( $link ); ?>"><?php echo esc_html( get_the_title( $id ) ); ?></a></h3>
                <?php echo wp_kses_post( $price_html ); ?>
                <?php echo ( 2 === $n ) ? '' : wp_kses_post( $action ); ?>
            </div>
        </div>
    </div>
    <?php return ob_get_clean();

}

/*
 * Variant reader helpers used by the product detail template, the catalog
 * filter layer and the Stripe metadata metabox. All are guarded with
 * function_exists() so they can never cause a redeclare fatal, even if a
 * copy already exists in another loaded file.
 */

if ( ! function_exists( 'stripe_shop_lite_product_attributes' ) ) {
    /**
     * Attribute value lists in the shape the templates expect:
     *   ['color' => ['red', 'blue', 'green'], 'size' => ['S', 'M', 'L']]
     * Normalizes the importer's stored definition
     * (_stripe_shop_lite_attributes: key => ['label' => ..., 'values' => [...]]).
     */
    function stripe_shop_lite_product_attributes( $post_id ) {
        $stored = get_post_meta( $post_id, '_stripe_shop_lite_attributes', true );
        if ( ! is_array( $stored ) ) return [];
        $out = [];
        foreach ( $stored as $key => $def ) {
            $values = is_array( $def ) ? ( $def['values'] ?? [] ) : [];
            $out[ $key ] = array_values( array_filter( array_map( 'strval', $values ) ) );
        }
        return $out;
    }
}

if ( ! function_exists( 'stripe_shop_lite_product_variants' ) ) {
    /**
     * The imported variant table (_stripe_shop_lite_variants), written by the
     * Stripe importer: one row per combination with price_id, attributes,
     * unit, currency, recurring, active. Empty array when none exist.
     */
    function stripe_shop_lite_product_variants( $post_id ) {
        $variants = get_post_meta( $post_id, '_stripe_shop_lite_variants', true );
        return is_array( $variants ) ? $variants : [];
    }
}

if ( ! function_exists( 'stripe_shop_lite_attribute_images' ) ) {
    /**
     * Attribute-value images, written by the importer from Stripe product
     * metadata:
     * Stripe product metadata:
     *   shoplite_image_color = "{red=https://..., blue=https://...}"
     * Stored as _stripe_shop_lite_attribute_images:
     *   [ 'color' => [ 'red' => 'https://...', ... ] ]
     * Called by the product card (?color= catalog filter) and the
     * product detail template (the picker's data-color-images JSON
     * for the gallery swap). With $attr: [ value => url ] for that
     * attribute; without: the whole map.
     */
    function stripe_shop_lite_attribute_images( $post_id, $attr = null ) {
        $stored = get_post_meta( $post_id, '_stripe_shop_lite_attribute_images', true );
        if ( ! is_array( $stored ) ) return null !== $attr ? [] : [];
        if ( null !== $attr ) {
            return isset( $stored[ $attr ] ) && is_array( $stored[ $attr ] ) ? $stored[ $attr ] : [];
        }
        return $stored;
    }
}

/*
 * Resolves a cart line's Stripe price ID back to its variant row
 * (attribute combination). The cart class calls this for lines like
 * "123:price_abc" so the drawer and checkout can show the combination.
 */

if ( ! function_exists( 'stripe_shop_lite_find_variant' ) ) {
    function stripe_shop_lite_find_variant( $post_id, $price_id ) {
        $price_id = trim( (string) $price_id );
        if ( '' === $price_id ) return null;
        foreach ( stripe_shop_lite_product_variants( $post_id ) as $variant ) {
            if ( is_array( $variant ) && isset( $variant['price_id'] ) && (string) $variant['price_id'] === $price_id ) {
                return $variant;
            }
        }
        return null;
    }
}

/*
 * Comment ratings, rendered with the theme's Font Awesome star styling
 * (no dashicons).
 *
 * - 1-5 star picker (plus a Clear option) on the product comment form only;
 *   pure CSS, no JavaScript. Required on product reviews (front-end only;
 *   admin replies exempt; runs after the CPT's buyers-only check).
 * - Saved as comment meta "rating" (validated to 1..5).
 * - Gold/gray stars on each rated comment (fa fa-star / .disable, the same
 *   markup language as the product cards).
 * - Averages recalculated on every comment change and written to:
 *     _stripe_shop_lite_rating        rounded int (rendered by the cards via stripe_shop_lite_rating())
 *     _stripe_shop_lite_rating_avg    precise, 1 decimal
 *     _stripe_shop_lite_rating_count  rated approved comments
 *   With zero rated comments any manual rating meta stays untouched.
 * - stripe_shop_lite_product_rating_summary() (guarded) prints the average
 *   and review count on the product page.
 */

if ( ! defined( 'STRIPE_SHOP_LITE_RATINGS_LOADED' ) ) {
define( 'STRIPE_SHOP_LITE_RATINGS_LOADED', '1.0' );

function stripe_shop_lite_ratings_is_product( $post_id = 0 ) {
    $post_id = (int) $post_id;
    if ( ! $post_id ) $post_id = (int) get_the_ID();
    return $post_id > 0 && get_post_type( $post_id ) === 'product';
}

/* Star picker in the comment form (respects reviews_on via comments_open). */
add_action( 'comment_form_logged_in_after', 'stripe_shop_lite_ratings_field' );
add_action( 'comment_form_after_fields', 'stripe_shop_lite_ratings_field' );
function stripe_shop_lite_ratings_field() {
    $post_id = (int) get_the_ID();
    if ( ! stripe_shop_lite_ratings_is_product( $post_id ) ) return;
    if ( ! comments_open( $post_id ) ) return;
    ?>
    <div class="stripe-shop-lite-rating-field">
        <label for="rating-5">Rating <span class="required">*</span></label>
        <fieldset class="comments-rating">
            <span class="rating-container">
                <?php for ( $i = 5; $i >= 1; $i-- ) : ?>
                    <input type="radio" id="rating-<?php echo esc_attr( $i ); ?>" name="rating" value="<?php echo esc_attr( $i ); ?>" />
                    <label for="rating-<?php echo esc_attr( $i ); ?>" title="<?php echo esc_attr( $i ); ?> star<?php echo $i > 1 ? 's' : ''; ?>"><i class="fa fa-star" aria-hidden="true"></i><span class="screen-reader-text"><?php echo esc_html( $i ); ?></span></label>
                <?php endfor; ?>
                <input type="radio" id="rating-0" class="star-cb-clear" name="rating" value="0" />
                <label for="rating-0" class="stripe-shop-lite-rating-clear">Clear</label>
            </span>
        </fieldset>
    </div>
    <?php
}

/* Required on product reviews (front-end only; after buyers_only, prio 20). */
add_filter( 'preprocess_comment', 'stripe_shop_lite_ratings_require', 20 );
function stripe_shop_lite_ratings_require( $commentdata ) {
    if ( is_admin() ) return $commentdata;
    $pid = (int) ( $commentdata['comment_post_ID'] ?? 0 );
    if ( get_post_type( $pid ) !== 'product' ) return $commentdata;
    $rating = isset( $_POST['rating'] ) ? (int) $_POST['rating'] : 0;
    if ( $rating < 1 || $rating > 5 ) {
        wp_die( 'Error: You did not add a rating. Use the Back button in your browser and resubmit your review with a rating.' );
    }
    return $commentdata;
}

/* Save the rating + recalculate the product's average. */
add_action( 'comment_post', 'stripe_shop_lite_ratings_save', 10, 2 );
function stripe_shop_lite_ratings_save( $comment_id, $approved = 0 ) {
    $comment = get_comment( $comment_id );
    if ( ! $comment || get_post_type( $comment->comment_post_ID ) !== 'product' ) return;
    $rating = isset( $_POST['rating'] ) ? (int) $_POST['rating'] : 0;
    if ( $rating >= 1 && $rating <= 5 ) {
        update_comment_meta( $comment_id, 'rating', $rating );
    }
    stripe_shop_lite_ratings_recalc( $comment->comment_post_ID );
}

function stripe_shop_lite_ratings_recalc( $post_id ) {
    $post_id = (int) $post_id;
    if ( get_post_type( $post_id ) !== 'product' ) return;
    $total = 0;
    $count = 0;
    foreach ( get_approved_comments( $post_id ) as $c ) {
        $r = (int) get_comment_meta( $c->comment_ID, 'rating', true );
        if ( $r >= 1 && $r <= 5 ) {
            $count++;
            $total += $r;
        }
    }
    if ( $count < 1 ) {
        delete_post_meta( $post_id, '_stripe_shop_lite_rating_avg' );
        delete_post_meta( $post_id, '_stripe_shop_lite_rating_count' );
        return; // any manual _stripe_shop_lite_rating stays untouched
    }
    $avg = $total / $count;
    update_post_meta( $post_id, '_stripe_shop_lite_rating_avg', round( $avg, 1 ) );
    update_post_meta( $post_id, '_stripe_shop_lite_rating_count', $count );
    update_post_meta( $post_id, '_stripe_shop_lite_rating', (int) round( $avg ) );
}

function stripe_shop_lite_ratings_recalc_for_comment( $comment_id ) {
    $comment = get_comment( $comment_id );
    if ( $comment ) stripe_shop_lite_ratings_recalc( $comment->comment_post_ID );
}
add_action( 'edit_comment', 'stripe_shop_lite_ratings_recalc_for_comment' );
add_action( 'wp_set_comment_status', 'stripe_shop_lite_ratings_recalc_for_comment' );
add_action( 'trashed_comment', 'stripe_shop_lite_ratings_recalc_for_comment' );
add_action( 'untrashed_comment', 'stripe_shop_lite_ratings_recalc_for_comment' );
add_action( 'deleted_comment', 'stripe_shop_lite_ratings_recalc_for_comment' );

/* Stars on each rated comment (admin list has no Font Awesome - skip). */
add_filter( 'comment_text', 'stripe_shop_lite_ratings_comment_stars', 20 );
function stripe_shop_lite_ratings_comment_stars( $comment_text ) {
    if ( is_admin() ) return $comment_text;
    $rating = (int) get_comment_meta( get_comment_ID(), 'rating', true );
    if ( $rating < 1 || $rating > 5 ) return $comment_text;
    $stars = '<ul class="stripe-shop-lite-crating">';
    for ( $i = 1; $i <= 5; $i++ ) {
        $stars .= '<li class="fa fa-star' . ( $i > $rating ? ' disable' : '' ) . '" aria-hidden="true"></li>';
    }
    $stars .= '</ul>';
    return $comment_text . $stars;
}

/* The summary product-detail.php prints above the reviews tab. */
if ( ! function_exists( 'stripe_shop_lite_product_rating_summary' ) ) {
    function stripe_shop_lite_product_rating_summary( $post_id = 0 ) {
        echo stripe_shop_lite_product_rating_summary_html( $post_id ); // phpcs:ignore WordPress.Security.EscapeOutput -- the summary HTML is escaped where it is built
    }
}
if ( ! function_exists( 'stripe_shop_lite_product_rating_summary_html' ) ) {
    function stripe_shop_lite_product_rating_summary_html( $post_id = 0 ) {
        $post_id = (int) $post_id;
        if ( ! $post_id ) $post_id = (int) get_the_ID();
        if ( ! stripe_shop_lite_ratings_is_product( $post_id ) ) return '';
        $avg   = get_post_meta( $post_id, '_stripe_shop_lite_rating_avg', true );
        $count = (int) get_post_meta( $post_id, '_stripe_shop_lite_rating_count', true );
        if ( ( $avg === '' || $avg === null ) || $count < 1 ) return '';
        $rounded = (int) round( (float) $avg );
        $out  = '<div class="stripe-shop-lite-rating-summary">';
        $out .= '<ul class="stripe-shop-lite-srating">';
        for ( $i = 1; $i <= 5; $i++ ) {
            $out .= '<li class="fa fa-star' . ( $i > $rounded ? ' disable' : '' ) . '" aria-hidden="true"></li>';
        }
        $out .= '</ul>';
        $out .= '<span class="stripe-shop-lite-rating-text">'
            . esc_html( number_format_i18n( (float) $avg, 1 ) ) . ' / 5 &middot; '
            . esc_html( sprintf(
                /* translators: %s: number of product reviews. */
                _n( '%s review', '%s reviews', $count, 'storefront-for-stripe' ),
                number_format_i18n( $count )
            ) )
            . '</span>';
        $out .= '</div>';
        return $out;
    }
}

/* Front-end styles, printed inline on store pages only: the star picker,
   comment stars, rating summary and the active state of the functional
   catalog filter chips. Nothing here touches wp-admin. */
add_action( 'wp_head', 'stripe_shop_lite_store_front_styles' );
function stripe_shop_lite_store_front_styles() {
    $store_page = is_singular( 'product' ) || is_post_type_archive( 'product' ) || is_tax( [ 'product_category', 'product_keyword', 'product_tag' ] ) || ( is_search() && Stripe_Shop_Lite_Shortcodes::is_product_search() ) || is_page( stripe_shop_lite_opt( 'cart_page', 'cart' ) );
    if ( ! $store_page ) return;
    ?>
<style id="stripe-shop-lite-ratings">
/* --- comment form star picker (pure CSS, no JS) --- */
.stripe-shop-lite-rating-field fieldset{border:0;padding:0;margin:0 0 1em;}
.stripe-shop-lite-rating-field>label{display:block;margin:0 0 .4em;font-weight:600;}
.stripe-shop-lite-rating-field .rating-container{display:inline-flex;flex-direction:row-reverse;align-items:center;gap:2px;}
.stripe-shop-lite-rating-field input[type="radio"]{position:absolute;opacity:0;width:0;height:0;}
.stripe-shop-lite-rating-field label{cursor:pointer;font-size:1rem;line-height:1;color:#000;}
html[data-bs-theme="dark"] .stripe-shop-lite-rating-field label{color:#fff;}
.stripe-shop-lite-rating-field label .fa{color:#d9d9d9;transition:color .15s ease;font-style:normal;}
.stripe-shop-lite-rating-field label:hover .fa,
.stripe-shop-lite-rating-field label:hover~label .fa,
.stripe-shop-lite-rating-field input[type="radio"]:checked~label .fa{color:#f5b301;}
.stripe-shop-lite-rating-field input[type="radio"]:focus-visible+label{outline:2px solid #635bff;outline-offset:2px;border-radius:3px;}
.stripe-shop-lite-rating-clear{order:-1;font-size:12px;color:#635bff;margin-inline-end:8px;}
.stripe-shop-lite-rating-clear:hover{color:#f5b301;}
/* --- stars on comments --- */
.stripe-shop-lite-crating{list-style:none;display:inline-flex;gap:2px;margin:.6em 0 0;padding:0;}
.stripe-shop-lite-crating li{font-size:14px;color:#f5b301;}
.stripe-shop-lite-crating li.disable{color:#d9d9d9;}
/* --- average summary --- */
.stripe-shop-lite-rating-summary{display:flex;align-items:center;gap:8px;margin:0 0 12px;}
.stripe-shop-lite-srating{list-style:none;display:inline-flex;gap:2px;margin:0;padding:0;}
.stripe-shop-lite-srating li{font-size:16px;color:#f5b301;}
.stripe-shop-lite-srating li.disable{color:#d9d9d9;}
.stripe-shop-lite-rating-text{font-size:14px;}
/* --- functional catalog filter chips: active state --- */
.lsm-chip-active,.lsm-sw-active{outline:2px solid #635bff;outline-offset:2px;}
/* --- archive spacing: the Leadstart theme zeroes the #page padding on
   body.archive.tax-product_* pages, so the store grid would touch the
   viewport edges. The archive container carries its own padding now
   (breadcrumbs and the term summary were moved inside it). --- */
.stripe-shop-lite-archive{padding:3rem;}
@media (max-width:767.98px){.stripe-shop-lite-archive{padding:1.5rem;}}
/* --- Pinterest "Pin it" button: the theme injects it into archive images,
   which broke the product card grid on category / taxonomy pages. --- */
.pin-it-btn-wrapper{position:relative;display:block !important;}
/* --- Storefront theme-matching and polish styles --- */
/* --- Simple Likes button on store cards (matches the theme button look) --- */
.sl-wrapper{color:#ddd !important;border:1px solid #ddd;background:transparent;border-radius:5px;padding:8px 10px 9px 10px;text-align:center;float:none;font-size:14px;white-space:nowrap;}
.sb-wrapper{color:#ddd !important;border:1px solid #ddd;background:transparent;border-radius:3px;padding:8px 18px 9px 10px;text-align:center;float:none;font-size:14px;white-space:nowrap;}
html[data-bs-theme="dark"] .sl-wrapper{color:#aaa !important;border-color:#555;}
html[data-bs-theme="dark"] .sb-wrapper{color:#aaa !important;border-color:#555;}
/* --- product card hover: faint translucency, never a solid white wash.
   When the product has a gallery the second image (pic-2) swaps in. --- */
.stripe-shop-lite-pimg1,.stripe-shop-lite-pimg2{position:relative;}
.stripe-shop-lite-pimg1::after,.stripe-shop-lite-pimg2::after{content:"";position:absolute;inset:0;background:rgba(255,255,255,0);pointer-events:none;transition:background .2s ease;}
.stripe-shop-lite-pgrid:hover .stripe-shop-lite-pimg1::after,.stripe-shop-lite-pgrid:hover .stripe-shop-lite-pimg2::after{background:rgba(255,255,255,.12);}
.stripe-shop-lite-pgrid:hover .stripe-shop-lite-pimg1,.stripe-shop-lite-pgrid:hover .stripe-shop-lite-pimg2,
.stripe-shop-lite-pgrid:hover .stripe-shop-lite-pic-1,.stripe-shop-lite-pgrid:hover .stripe-shop-lite-pic-2{background:transparent !important;}
/* --- store typography matches the theme --- */
body.lsm-store-page h1{font-family:'League Spartan','Poppins',sans-serif;text-transform:lowercase;color:#000;}
body.lsm-store-page h1:not([class*="h2"]):not([class*="h3"]):not([class*="h4"]):not([class*="h5"]):not([class*="h6"]){font-size:6rem;line-height:1;}
body.lsm-store-page p{font-family:-apple-system,system-ui,BlinkMacSystemFont,Segoe UI,Roboto,Helvetica Neue,Arial,sans-serif;}
body.lsm-store-page h2.stripe-shop-lite-ptitle,body.lsm-store-page h3.stripe-shop-lite-ptitle,body.lsm-store-page h4.stripe-shop-lite-ptitle{color:#000;text-decoration:none;}
html[data-bs-theme="dark"] body.lsm-store-page h1,
html[data-bs-theme="dark"] body.lsm-store-page h2.stripe-shop-lite-ptitle,
html[data-bs-theme="dark"] body.lsm-store-page h3.stripe-shop-lite-ptitle,
html[data-bs-theme="dark"] body.lsm-store-page h4.stripe-shop-lite-ptitle{color:#fff;}
/* --- button radii + link reset (theme button language) --- */
button.stripe-shop-lite-add,button.stripe-shop-lite-waitlist-join,.stripe-shop-lite-cart-page-checkout{border-radius:3px;}
.stripe-shop-lite-no-bs .btn{border-radius:3px;}
.stripe-shop-lite-no-bs .btn-danger,.stripe-shop-lite-no-bs .stripe-shop-lite-cart-remove .btn{text-decoration:none;}
.stripe-shop-lite-drawer .stripe-shop-lite-checkout-btn{border-radius:3px;}
/* --- cart line title: the theme forces its own weight with !important --- */
.stripe-shop-lite-cart-title{font-weight:600 !important;}
/* --- store page background: slightly whiter in light mode --- */
html[data-bs-theme="light"] body.lsm-store-page{background:#f8f9fa;}
/* --- gallery thumbnails: highlight the clicked one (focus), Bootstrap
   ignores .carousel-thumbnails for active-state management --- */
.stripe-shop-lite-single-gallery .carousel-thumbnails button:focus,
.stripe-shop-lite-single-gallery .carousel-thumbnails button:focus-visible{border-color:var(--lsm-purple) !important;outline:none;}
/* --- dark mode: comments on the product page --- */
html[data-bs-theme="dark"] body.single-product.stripe-shop-lite-no-bs #comments .comment-list .card{border:none;background:none;}
html[data-bs-theme="dark"] body.single-product.stripe-shop-lite-no-bs #comments .comments-title,
html[data-bs-theme="dark"] body.single-product.stripe-shop-lite-no-bs #comments .comments-title span,
html[data-bs-theme="dark"] body.single-product.stripe-shop-lite-no-bs #comments .card-header h5,
html[data-bs-theme="dark"] body.single-product.stripe-shop-lite-no-bs #comments h3#reply-title.comment-reply-title{color:#fff;}
.stripe-shop-lite-no-bs .btn-outline-secondary{color:var(--lsm-text) !important;border-color:var(--lsm-line) !important;}
html[data-bs-theme="dark"] body.single-product.stripe-shop-lite-no-bs #comments .comment-content a,
html[data-bs-theme="dark"] body.single-product.stripe-shop-lite-no-bs #comments p a{color:var(--lsm-purple);text-decoration:none;}
html[data-bs-theme="dark"] body.single-product.stripe-shop-lite-no-bs #comments .comment-content a:hover,
html[data-bs-theme="dark"] body.single-product.stripe-shop-lite-no-bs #comments p a:hover{text-decoration:underline;}
/* --- comment form extras --- */
p.mc4wp-checkbox.mc4wp-checkbox-wp-comment-form span{padding-left:20px;font-size:.9rem;}
p.akismet_comment_form_privacy_notice{font-size:.9rem;margin-top:10px;}
/* --- filter bar: desktop price widths, mobile full width --- */
.stripe-shop-lite-filter input,.stripe-shop-lite-filter select{min-width:auto;}
@media (min-width:768px){.stripe-shop-lite-price-range input{width:200px;}}
@media (max-width:767.98px){.stripe-shop-lite-price-range input{width:100%;}}
</style>
    <?php
}

} // end STRIPE_SHOP_LITE_RATINGS_LOADED
