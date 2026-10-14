<?php
/**
 * Product category archive template - Storefront for Stripe.
 * Ships inside the plugin (templates/product-category.php) and works without
 * any theme files; to customize, copy it to
 * yourtheme/storefront-for-stripe/product-category.php
 *
 * Design layer (presentation only; no functional changes):
 * - sets data-bs-theme (dark/light) and adds the lsm-store-page body class;
 * - adds the theme toggle button (bottom-right);
 * - breadcrumbs and the term summary sit inside .stripe-shop-lite-archive
 *   (the theme zeroes the #page padding on tax-product_* archives).
 * All plugin logic (term query, product cards) is untouched.
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
$count = (int) $q->found_posts;
?>
<?php
/**
 * Breadcrumbs and the term summary render inside .stripe-shop-lite-archive
 * as rows; the theme zeroes the #page padding on tax-product_* archive
 * pages, so outside the archive container they would sit flush against the
 * viewport edge. The archive container provides its own padding via the
 * plugin CSS (3rem on desktop).
 */
?>
<div class="stripe-shop-lite-archive">
    <div class="row stripe-shop-lite-tax-summary">
        <div class="col-12">
            <?php echo wp_kses_post( stripe_shop_lite_breadcrumbs() ); ?>
        </div>
    </div>
    <div class="row align-items-center py-3 stripe-shop-lite-tax-summary-head">
        <div class="col-12 col-md-8"><h1 class="h4 mb-0"><?php single_term_title(); ?></h1></div>
        <div class="col-12 col-md-4 text-md-end mt-2 mt-md-0 d-flex align-items-center justify-content-md-end gap-3 flex-wrap">
            <span class="text-muted"><?php echo esc_html( number_format_i18n( $count ) ); ?> <?php echo 1 === $count ? 'product' : 'products'; ?></span>
        </div>
    </div>
    <div class="row row-cols-1 row-cols-sm-2 row-cols-lg-3 g-4 stripe-shop-lite-products" data-pagination="<?php echo esc_attr( $mode ); ?>">
        <?php if ( ! $q->have_posts() ) : ?><div class="col-12"><p class="stripe-shop-lite-no-products">No products found.</p></div><?php endif; ?>
        <?php while ( $q->have_posts() ) : $q->the_post(); echo stripe_shop_lite_product_card( get_the_ID() ); endwhile; wp_reset_postdata(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside stripe_shop_lite_product_card() ?>
    </div>
</div>
<?php
/**
 * Design layer - theme toggle button (presentational only).
 */
echo '<button id="lsm-theme-toggle" type="button" aria-label="Toggle dark / light theme" title="Toggle dark / light theme"></button>';
echo '<script>(function(){var b=document.getElementById("lsm-theme-toggle");if(!b)return;var d=document.documentElement;var paint=function(){b.textContent=(d.getAttribute("data-bs-theme")==="light")?"\u2600":"\u25D0"};paint();b.addEventListener("click",function(){var n=(d.getAttribute("data-bs-theme")==="dark")?"light":"dark";d.setAttribute("data-bs-theme",n);try{localStorage.setItem("lsm-theme",n)}catch(e){}paint();})})();</script>';
