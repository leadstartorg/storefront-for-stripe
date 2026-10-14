<?php
/**
 * Product catalog template - Storefront for Stripe (main shop page).
 * Ships inside the plugin (templates/product-catalog.php) and works without
 * any theme files; to customize, copy it to
 * yourtheme/storefront-for-stripe/product-catalog.php
 *
 * Design layer (presentation only; no functional changes):
 * - sets data-bs-theme (dark/light) and adds the lsm-store-page body class;
 * - adds the theme toggle button (bottom-right);
 * - adds a results summary bar.
 *
 * The second filter row (size chips and color swatches) is functional.
 * Chips are links that set the ?color= / ?size= query arguments; clicking
 * the active value again clears it. The archive query itself is filtered
 * by the plugin's shortcodes class (the pre_get_posts handler matches the
 * imported variant metadata, _stripe_shop_lite_variants), so no theme
 * functions.php snippet is needed. The active-chip CSS
 * (.lsm-chip-active, .lsm-sw-active) is printed by the plugin on store
 * pages as well.
 */

if ( ! defined( 'ABSPATH' ) ) exit;
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- template variables are local to Stripe_Shop_Lite::load_template()

/**
 * Design layer - dark/light color mode (presentational only).
 */
echo '<script>(function(){var t;try{t=localStorage.getItem("lsm-theme")}catch(e){}var m=(t==="dark")?"dark":"light";document.documentElement.setAttribute("data-bs-theme",m);try{document.body.classList.add("lsm-store-page")}catch(e){}})();</script>';

global $wp_query;
$q = $wp_query;
$mode = stripe_shop_lite_opt( 'pagination', 'numbered' );
$found = (int) $q->found_posts;
echo wp_kses_post( stripe_shop_lite_breadcrumbs() );
echo do_shortcode( '[store_filters]' );

/**
 * Functional second filter row - size chips and color swatches.
 * Each chip is a link carrying its query argument; the currently active
 * value links back to the page without that argument (click again = clear).
 *
 * The values are not hardcoded: they are collected from the imported
 * attribute metadata (_stripe_shop_lite_attributes) across the published
 * products - the same Stripe data the product detail page uses. The row
 * therefore always matches the real catalog (new sizes like XXXL, new
 * colors) after every import and never shows placeholder swatches that do
 * not exist in the shop. No settings maintenance is needed: change the
 * attributes in Stripe, re-run the importer, done.
 */
$cur_color = isset( $_GET['color'] ) ? sanitize_text_field( wp_unslash( $_GET['color'] ) ) : '';
$cur_size  = isset( $_GET['size'] )  ? sanitize_text_field( wp_unslash( $_GET['size'] ) )  : '';

$chip_sizes  = [];
$chip_colors = [];
$chip_q = new WP_Query( [
    'post_type'      => 'product',
    'post_status'    => 'publish',
    'posts_per_page' => -1,
    'fields'         => 'ids',
    'meta_query'     => [ [ 'key' => '_from_woocommerce', 'compare' => 'NOT EXISTS' ] ],
] );
foreach ( $chip_q->posts as $chip_id ) {
    $stored = get_post_meta( $chip_id, '_stripe_shop_lite_attributes', true );
    if ( ! is_array( $stored ) ) continue;
    foreach ( $stored as $attr_key => $def ) {
        $vals = is_array( $def ) ? ( $def['values'] ?? [] ) : [];
        foreach ( $vals as $v ) {
            $v = trim( (string) $v );
            if ( '' === $v ) continue;
            if ( 'size' === $attr_key )  $chip_sizes[]  = $v;
            if ( 'color' === $attr_key ) $chip_colors[] = $v;
        }
    }
}
$chip_sizes  = array_values( array_unique( $chip_sizes ) );
$chip_colors = array_values( array_unique( $chip_colors ) );

/* Apparel size order (XS...XXXL); anything unknown sorts after, alphabetically. */
$size_order = [ 'XS', 'S', 'M', 'L', 'XL', 'XXL', 'XXXL', 'XXXXL' ];
usort( $chip_sizes, function ( $a, $b ) use ( $size_order ) {
    $ia = array_search( strtoupper( $a ), $size_order, true );
    $ib = array_search( strtoupper( $b ), $size_order, true );
    $ia = ( false === $ia ) ? 999 : $ia;
    $ib = ( false === $ib ) ? 999 : $ib;
    return ( $ia === $ib ) ? strcmp( $a, $b ) : ( ( $ia < $ib ) ? -1 : 1 );
} );

/* Color name -> swatch hex (same map as the product detail page). */
$swatch_colors = [
    'black' => '#101010', 'white' => '#ffffff', 'off-white' => '#d8d8d8',
    'red' => '#c94b4b', 'blue' => '#4b67c9', 'green' => '#4b9a6b',
    'purple' => '#635bff', 'violet' => '#4b47c9', 'violet-haze' => '#4b47c9',
    'lilac' => '#d08be7', 'pink' => '#e58bb0', 'yellow' => '#e2c34f',
    'orange' => '#e38b42', 'brown' => '#795548', 'grey' => '#9b9b9b', 'gray' => '#9b9b9b',
];
$swatch_fallback = '#d8d8d8';

echo '<div class="lsm-filter-row2">';
if ( $chip_sizes ) {
    echo '<span class="lsm-flabel">Size</span>';
    foreach ( $chip_sizes as $s ) {
        $active = ( 0 === strcasecmp( $s, $cur_size ) );
        $url = $active ? remove_query_arg( 'size' ) : add_query_arg( 'size', $s );
        echo '<a class="lsm-chip' . ( $active ? ' lsm-chip-active' : '' ) . '" href="' . esc_url( $url ) . '">' . esc_html( $s ) . '</a>';
    }
}
if ( $chip_colors ) {
    echo '<span class="lsm-flabel"' . ( $chip_sizes ? ' style="margin-left:14px"' : '' ) . '>Color</span>';
    echo '<div class="lsm-swatches">';
    foreach ( $chip_colors as $c ) {
        $key = strtolower( preg_replace( '/[^a-z0-9]+/', '-', $c ) );
        /* Hex values in Stripe metadata ("#635bff", "635BFF", "#fff") are used as-is. */
        $bg = preg_match( '/^#?([0-9a-f]{3}|[0-9a-f]{6})$/i', trim( $c ), $hm ) ? '#' . strtolower( $hm[1] ) : ( $swatch_colors[ $key ] ?? $swatch_fallback );
        $active = ( 0 === strcasecmp( $c, $cur_color ) );
        $url = $active ? remove_query_arg( 'color' ) : add_query_arg( 'color', $c );
        echo '<a class="lsm-sw' . ( $active ? ' lsm-sw-active' : '' ) . '" style="background:' . esc_attr( $bg ) . '" title="' . esc_attr( $c ) . '" href="' . esc_url( $url ) . '" aria-label="' . esc_attr( $c ) . '"></a>';
    }
    echo '</div>';
}
echo '</div>';

/* Design layer - results summary bar (presentational only).
   The "Special for members only 10% OFF" tag was removed on request. */
echo '<div class="lsm-summary"><span class="count">Showing <b>' . esc_html( number_format_i18n( $found ) ) . '</b> ' . ( 1 === $found ? 'product' : 'products' ) . ( ( $cur_color || $cur_size ) ? ' &middot; <a href="' . esc_url( remove_query_arg( [ 'color', 'size' ] ) ) . '">clear filters</a>' : '' ) . '</span></div>';

echo '<div class="stripe-shop-lite-archive">';
echo '<div class="row row-cols-1 row-cols-sm-2 row-cols-lg-3 g-4 stripe-shop-lite-products" data-pagination="' . esc_attr( $mode ) . '">';
if ( ! $q->have_posts() ) echo '<div class="col-12"><p class="stripe-shop-lite-no-products">No products found.</p></div>';
while ( $q->have_posts() ) { $q->the_post(); echo stripe_shop_lite_product_card( get_the_ID() ); } // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside stripe_shop_lite_product_card()
wp_reset_postdata();
echo '</div>';
if ( 'numbered' === $mode && $q->max_num_pages > 1 ) echo '<nav class="stripe-shop-lite-pagination">' . wp_kses_post( paginate_links( [ 'total' => $q->max_num_pages, 'prev_text' => 'Prev', 'next_text' => 'Next' ] ) ) . '</nav>';
echo '</div>';

/**
 * Design layer - theme toggle button (presentational only).
 */
echo '<button id="lsm-theme-toggle" type="button" aria-label="Toggle dark / light theme" title="Toggle dark / light theme"></button>';
echo '<script>(function(){var b=document.getElementById("lsm-theme-toggle");if(!b)return;var d=document.documentElement;var paint=function(){b.textContent=(d.getAttribute("data-bs-theme")==="light")?"\u2600":"\u25D0"};paint();b.addEventListener("click",function(){var n=(d.getAttribute("data-bs-theme")==="dark")?"light":"dark";d.setAttribute("data-bs-theme",n);try{localStorage.setItem("lsm-theme",n)}catch(e){}paint();})})();</script>';
