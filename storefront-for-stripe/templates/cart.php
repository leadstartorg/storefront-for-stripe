<?php
/**
 * Cart template - Storefront for Stripe (/cart/ page).
 * Ships inside the plugin (templates/cart.php) and works without any theme
 * files; to customize, copy it to yourtheme/storefront-for-stripe/cart.php
 * The lines are rendered client-side by assets/store.js from the client
 * cart (cache-proof); prices come from the server (Stripe), never the browser.
 * Cross-sell row appears only when suggestions exist.
 *
 * Design layer (presentation only; no functional changes):
 * - sets data-bs-theme (dark/light) and adds the lsm-store-page body class;
 * - adds the theme toggle button (bottom-right).
 * Cart markup and logic come from the plugin; presentation is handled by
 * store.css (cart table, quantity steppers, checkout button, cross-sell,
 * drawer).
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Design layer - dark/light color mode (presentational only).
 */
echo '<script>(function(){var t;try{t=localStorage.getItem("lsm-theme")}catch(e){}var m=(t==="dark")?"dark":"light";document.documentElement.setAttribute("data-bs-theme",m);try{document.body.classList.add("lsm-store-page")}catch(e){}})();</script>';
?>
<main class="stripe-shop-lite-cart-page container py-4">
    <div class="d-flex align-items-center justify-content-between gap-3 mb-4">
        <h1 class="h2 mb-0">Cart</h1>
        <?php echo do_shortcode( '[store_cart_button]' ); ?>
    </div>
    <?php echo do_shortcode( '[store_cart]' ); ?>
</main>
<?php
/**
 * Design layer - theme toggle button (presentational only).
 */
echo '<button id="lsm-theme-toggle" type="button" aria-label="Toggle dark / light theme" title="Toggle dark / light theme"></button>';
echo '<script>(function(){var b=document.getElementById("lsm-theme-toggle");if(!b)return;var d=document.documentElement;var paint=function(){b.textContent=(d.getAttribute("data-bs-theme")==="light")?"\u2600":"\u25D0"};paint();b.addEventListener("click",function(){var n=(d.getAttribute("data-bs-theme")==="dark")?"light":"dark";d.setAttribute("data-bs-theme",n);try{localStorage.setItem("lsm-theme",n)}catch(e){}paint();})})();</script>';
