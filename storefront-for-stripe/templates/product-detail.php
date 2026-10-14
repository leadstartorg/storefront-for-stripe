<?php
/**
 * Product Detail template - Storefront for Stripe.
 * Ships inside the plugin (templates/product-detail.php) and works without
 * any theme files; to customize, copy it to
 * yourtheme/storefront-for-stripe/product-detail.php
 * Reviews use WordPress comments (tab), sold-out shows the waitlist form.
 *
 * Design layer (presentation only; no functional changes):
 * - sets data-bs-theme (dark/light) and adds the lsm-store-page body class;
 * - adds the theme toggle button (bottom-right);
 * - adds three presentational blocks in the buy column:
 *   .lsm-rating (gradient stars), .lsm-attr (color/size pickers),
 *   .lsm-trust (shipping / returns / secure checkout).
 * All plugin logic (price, stock, waitlist, gift, gallery, tabs) is untouched.
 */

if ( ! defined( 'ABSPATH' ) ) exit;
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- template variables are local to Stripe_Shop_Lite::load_template()

/**
 * Design layer - dark/light color mode (presentational only).
 * Reads the saved preference, sets data-bs-theme on <html> and tags the
 * body so store.css can scope its backgrounds to store pages.
 */
echo '<script>(function(){var t;try{t=localStorage.getItem("lsm-theme")}catch(e){}var m=(t==="dark")?"dark":"light";document.documentElement.setAttribute("data-bs-theme",m);try{document.body.classList.add("lsm-store-page")}catch(e){}})();</script>';

global $wp_query;
if ( ! have_posts() ) {
    echo '<div class="container py-5"><div class="alert alert-warning">Product details could not be loaded.</div></div>';
    return;
}

while ( have_posts() ) { the_post();
    $id = get_the_ID();
    $price_id = get_post_meta( $id, '_stripe_price_id', true );
    $price = stripe_shop_lite_price_info( $price_id );
    $stock = get_post_meta( $id, '_stock_level', true );
    $variant_attributes = stripe_shop_lite_product_attributes( $id );
    $variants = stripe_shop_lite_product_variants( $id );
    /*
     * Attribute-value images (Stripe: shoplite_image_color), used by the
     * color-swatch gallery swap. The call is guarded so a missing helper never causes a fatal error.
     */
    $color_images = function_exists( 'stripe_shop_lite_attribute_images' ) ? stripe_shop_lite_attribute_images( $id, 'color' ) : [];
    $selected_variant = $variants ? reset( $variants ) : null;
    if ( is_array( $selected_variant ) && ! empty( $selected_variant['price_id'] ) ) {
        $price_id = (string) $selected_variant['price_id'];
        $price = stripe_shop_lite_price_info( $price_id ) ?: $price;
        if ( isset( $selected_variant['stock'] ) && '' !== (string) $selected_variant['stock'] ) $stock = (string) $selected_variant['stock'];
    }
    $buyable = $price_id && ( $stock === '' || (int) $stock > 0 );
    $variant_data = wp_json_encode( array_values( $variants ) );
    $imgs = stripe_shop_lite_gallery_imgs( $id );
    $kind = stripe_shop_lite_product_kind( $id );
    $gift = stripe_shop_lite_product_is_gift( $id ) && ! ( $price && $price['recurring'] );
    $sizing = get_post_meta( $id, '_sizing_chart', true );
    $reviews = stripe_shop_lite_opt( 'reviews_on' );
    $cats = get_the_term_list( $id, 'product_category', '', ', ' );
    $tags = get_the_term_list( $id, 'product_tag', '', ', ' );
    ?>
    <article class="stripe-shop-lite-product-page">
        <?php echo wp_kses_post( stripe_shop_lite_breadcrumbs( get_the_title() ) ); ?>
        <div class="row g-4 stripe-shop-lite-single-top">
            <!-- Gallery -->
            <div class="col-12 col-md-6 stripe-shop-lite-single-gallery">
                <?php if ( $imgs ) : ?>
                    <?php if ( count( $imgs ) > 1 ) : ?>
                        <?php $carousel_id = 'stripe-shop-lite-product-gallery-' . $id; ?>
                        <div id="<?php echo esc_attr( $carousel_id ); ?>" class="carousel slide spotlight-group" data-bs-interval="false">
                            <div class="carousel-container d-flex">
                                <div class="carousel-thumbnails d-flex flex-column gap-3 me-3">
                                    <?php foreach ( $imgs as $i => $u ) : ?>
                                        <button type="button" data-bs-target="#<?php echo esc_attr( $carousel_id ); ?>" data-bs-slide-to="<?php echo esc_attr( $i ); ?>" aria-label="<?php echo esc_attr( sprintf( 'Slide %d', $i + 1 ) ); ?>">
                                            <img src="<?php echo esc_url( $u ); ?>" alt="<?php echo esc_attr( get_the_title() ); ?>">
                                        </button>
                                    <?php endforeach; ?>
                                </div>
                                <div class="carousel-main flex-grow-1 position-relative">
                                    <div class="carousel-inner rounded-3">
                                        <?php foreach ( $imgs as $i => $u ) : ?>
                                            <div class="carousel-item <?php echo 0 === $i ? 'active' : ''; ?> zoom-container">
                                                <div class="spotlight h-100" data-src="<?php echo esc_url( $u ); ?>" data-title="<?php echo esc_attr( get_the_title() ); ?>" data-fit="contain" data-control="page,zoom,autofit,fullscreen,close">
                                                    <img src="<?php echo esc_url( $u ); ?>" class="d-block w-100 h-100 zoom-img" alt="<?php echo esc_attr( get_the_title() ); ?>">
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                    <button class="carousel-control-prev" type="button" data-bs-target="#<?php echo esc_attr( $carousel_id ); ?>" data-bs-slide="prev">
                                        <span class="carousel-control-prev-icon" aria-hidden="true"></span>
                                        <span class="visually-hidden">Previous</span>
                                    </button>
                                    <button class="carousel-control-next" type="button" data-bs-target="#<?php echo esc_attr( $carousel_id ); ?>" data-bs-slide="next">
                                        <span class="carousel-control-next-icon" aria-hidden="true"></span>
                                        <span class="visually-hidden">Next</span>
                                    </button>
                                </div>
                            </div>
                        </div>
                    <?php else : ?>
                        <div class="spotlight-group">
                            <div class="spotlight zoom-container" data-src="<?php echo esc_url( $imgs[0] ); ?>" data-title="<?php echo esc_attr( get_the_title() ); ?>" data-fit="contain" data-control="zoom,autofit,fullscreen,close">
                                <img class="stripe-shop-lite-single-main-img zoom-img" src="<?php echo esc_url( $imgs[0] ); ?>" alt="<?php echo esc_attr( get_the_title() ); ?>">
                            </div>
                        </div>
                    <?php endif; ?>
                <?php else : ?>
                    <img class="stripe-shop-lite-single-main-img stripe-shop-lite-card-noimg" src="<?php echo esc_url( plugins_url( 'assets/placeholder.svg', STRIPE_SHOP_LITE_FILE ) ); ?>" alt="<?php echo esc_attr( get_the_title() ); ?>">
                <?php endif; ?>
            </div>

            <div class="col-12 col-md-6 stripe-shop-lite-single-main" data-variant-picker data-product-id="<?php echo esc_attr( $id ); ?>" data-variants="<?php echo esc_attr( $variant_data ); ?>" data-color-images="<?php echo esc_attr( wp_json_encode( $color_images ) ); ?>">
                <h1 class="stripe-shop-lite-product-title"><?php the_title(); ?></h1>
                <?php if ( $price ) : ?>
                    <p id="stripe-shop-lite-product-price-<?php echo esc_attr( $id ); ?>" class="stripe-shop-lite-product-price" data-price-id="<?php echo esc_attr( $price_id ); ?>">
                        <?php echo esc_html( stripe_shop_lite_format_money( $price['unit'], $price['currency'] ) ); ?>
                        <?php echo $price['recurring'] ? '<span class="stripe-shop-lite-recurring">/mo</span>' : ''; ?>
                    </p>
                <?php elseif ( $price_id ) : ?>
                    <p class="stripe-shop-lite-product-price">Price shown at checkout</p>
                <?php endif; ?>
                <?php if ( $kind ) : ?>
                    <p class="stripe-shop-lite-single-kind">
                        <span class="badge stripe-shop-lite-purple-badge"><?php echo esc_html( $kind ); ?></span>
                        <?php if ( get_post_meta( $id, '_display_recurring', true ) ) : ?><span class="badge stripe-shop-lite-purple-badge">subscription</span><?php endif; ?>
                        <?php if ( $gift ) : ?><span class="badge stripe-shop-lite-purple-badge">gift</span><?php endif; ?>
                    </p>
                <?php endif; ?>

                <?php stripe_shop_lite_product_rating_summary( $id ); ?>

                <div class="stripe-shop-lite-product-content stripe-shop-lite-single-desc"><?php the_content(); ?></div>
                <?php if ( $cats ) : ?><p class="stripe-shop-lite-single-meta"><strong>Categories:</strong> <?php echo wp_kses_post( $cats ); ?></p><?php endif; ?>
                <?php if ( $tags ) : ?><p class="stripe-shop-lite-single-meta"><strong>Tags:</strong> <?php echo wp_kses_post( $tags ); ?></p><?php endif; ?>

                <?php if ( $variant_attributes && $variants ) : ?>
                    <div class="lsm-variant-picker" data-variant-picker data-product-id="<?php echo esc_attr( $id ); ?>" data-variants="<?php echo esc_attr( $variant_data ); ?>" data-color-images="<?php echo esc_attr( wp_json_encode( $color_images ) ); ?>">
                        <?php if ( ! empty( $variant_attributes['color'] ) ) : ?>
                            <div class="lsm-attr">
                                <label>Color: <span class="lsm-selected-value" data-attribute-label="color"><?php echo esc_html( $selected_variant['attributes']['color'] ?? $variant_attributes['color'][0] ); ?></span></label>
                                <div class="lsm-swatches" role="radiogroup" aria-label="Color">
                                    <?php foreach ( $variant_attributes['color'] as $value ) :
                                        $selected = isset( $selected_variant['attributes']['color'] ) && (string) $selected_variant['attributes']['color'] === (string) $value;
                                        $key = strtolower( preg_replace( '/[^a-z0-9]+/', '-', $value ) );
                                        $swatch_colors = [
                                            'black' => '#101010', 'white' => '#ffffff', 'off-white' => '#d8d8d8',
                                            'red' => '#c94b4b', 'blue' => '#4b67c9', 'green' => '#4b9a6b',
                                            'purple' => '#635bff', 'violet' => '#4b47c9', 'violet-haze' => '#4b47c9',
                                            'lilac' => '#d08be7', 'pink' => '#e58bb0', 'yellow' => '#e2c34f',
                                            'orange' => '#e38b42', 'brown' => '#795548', 'grey' => '#9b9b9b', 'gray' => '#9b9b9b',
                                        ];
                                        $bg = $swatch_colors[ $key ] ?? '#d8d8d8';
                                    ?>
                                        <button type="button" class="sw<?php echo $selected ? ' sel' : ''; ?> lsm-variant-option" data-attribute="color" data-value="<?php echo esc_attr( $value ); ?>" aria-label="<?php echo esc_attr( $value ); ?>" aria-pressed="<?php echo $selected ? 'true' : 'false'; ?>" style="width:32px;height:32px;border-radius:50%;display:inline-block;background:<?php echo esc_attr( $bg ); ?>"></button>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endif; ?>

                        <?php if ( ! empty( $variant_attributes['size'] ) ) : ?>
                            <div class="lsm-attr">
                                <label>Size <?php if ( $sizing ) : ?><span><a href="#stripe-shop-lite-tab-sizing">Size guide</a></span><?php endif; ?>: <span class="lsm-selected-value" data-attribute-label="size"><?php echo esc_html( $selected_variant['attributes']['size'] ?? $variant_attributes['size'][0] ); ?></span></label>
                                <div class="sizes" role="radiogroup" aria-label="Size">
                                    <?php foreach ( $variant_attributes['size'] as $value ) :
                                        $selected = isset( $selected_variant['attributes']['size'] ) && (string) $selected_variant['attributes']['size'] === (string) $value;
                                    ?>
                                        <button type="button" class="sz<?php echo $selected ? ' sel' : ''; ?> lsm-variant-option" data-attribute="size" data-value="<?php echo esc_attr( $value ); ?>" aria-label="<?php echo esc_attr( $value ); ?>" aria-pressed="<?php echo $selected ? 'true' : 'false'; ?>"><?php echo esc_html( $value ); ?></button>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <div class="stripe-shop-lite-product-actions">
                    <?php if ( $buyable ) : ?>
                        <div class="stripe-shop-lite-detail-qty d-flex align-items-center" aria-label="Quantity">
                            <button type="button" class="stripe-shop-lite-detail-qty-btn stripe-shop-lite-detail-qty-minus" aria-label="Decrease quantity">&minus;</button>
                            <input type="number" class="stripe-shop-lite-product-qty" value="1" min="1" step="1" inputmode="numeric" aria-label="Quantity">
                            <button type="button" class="stripe-shop-lite-detail-qty-btn stripe-shop-lite-detail-qty-plus" aria-label="Increase quantity">+</button>
                        </div>
                        <button class="btn btn-lg stripe-shop-lite-add" data-id="<?php echo esc_attr( $id ); ?>" data-price-id="<?php echo esc_attr( $price_id ); ?>">Add to cart</button>
                        <?php if ( $stock !== '' ) : ?><p id="stripe-shop-lite-product-stock-<?php echo esc_attr( $id ); ?>" class="stripe-shop-lite-stock"><?php echo (int) $stock; ?> in stock</p><?php endif; ?>
                    <?php elseif ( $stock !== '' && (int) $stock === 0 ) : ?>
                        <form class="stripe-shop-lite-waitlist-form" data-id="<?php echo esc_attr( $id ); ?>">
                            <input type="email" class="stripe-shop-lite-waitlist-email" placeholder="you@email.com" required />
                            <button type="button" class="stripe-shop-lite-waitlist-join">Notify me when back in stock</button>
                        </form>
                    <?php else : ?>
                        <p class="stripe-shop-lite-unavailable">Currently unavailable</p>
                    <?php endif; ?>
                </div>

                <?php /* Trust badges - presentational only. */ ?>
                <div class="lsm-trust">
                    <div><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 13h13V6H3zM16 9h3l2 4v4h-5"/><circle cx="7" cy="17" r="2"/><circle cx="18" cy="17" r="2"/></svg><span><b>Fast Shipping</b><small>in the U.S. and Canada</small></span></div>
                    <div><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20"/></svg><span><b>Cards Accepted</b><small>Visa, Mastercard, Amex, Discover</small></span></div>
                    <div><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 3 4 6v6c0 5 3.4 8 8 9 4.6-1 8-4 8-9V6l-8-3Z"/><path d="m9 12 2 2 4-4"/></svg><span><b>Secure Checkout</b><small>SSL encrypted payment</small></span></div>
                </div>
            </div>
        </div>

        <!-- Tabs: additional information / sizing chart / reviews -->
        <div class="stripe-shop-lite-single-tabs">
            <ul class="nav nav-tabs" role="tablist">
                <li class="nav-item" role="presentation"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#stripe-shop-lite-tab-info" type="button" role="tab">Additional Information</button></li>
                <?php if ( $sizing ) : ?><li class="nav-item" role="presentation"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#stripe-shop-lite-tab-sizing" type="button" role="tab">Sizing Chart</button></li><?php endif; ?>
                <?php if ( $reviews ) : ?><li class="nav-item" role="presentation"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#stripe-shop-lite-tab-reviews" type="button" role="tab">Reviews</button></li><?php endif; ?>
            </ul>
            <div class="tab-content">
                <div class="tab-pane fade show active" id="stripe-shop-lite-tab-info" role="tabpanel">
                    <table class="table stripe-shop-lite-info-table">
                        <tbody>
                            <tr><th scope="row">Kind</th><td><?php echo esc_html( $kind ?: '-' ); ?></td></tr>
                            <tr><th scope="row">Billing</th><td><?php echo $price ? ( $price['recurring'] ? 'subscription (recurring)' : 'one-off' ) : '-'; ?></td></tr>
                            <tr><th scope="row">Availability</th><td><?php echo esc_html( $stock === '' ? 'In stock' : ( ( (int) $stock > 0 ) ? ( (int) $stock . ' in stock' ) : 'Sold out' ) ); ?></td></tr>
                            <?php if ( $gift ) : ?><tr><th scope="row">Gift</th><td>Recipient details collected at checkout</td></tr><?php endif; ?>
                            <?php if ( $cats ) : ?><tr><th scope="row">Categories</th><td><?php echo wp_kses_post( $cats ); ?></td></tr><?php endif; ?>
                            <?php if ( $tags ) : ?><tr><th scope="row">Tags</th><td><?php echo wp_kses_post( $tags ); ?></td></tr><?php endif; ?>
                            <?php
                            /*
                             * Extra Stripe product metadata (weight,
                             * dimensions, material, ...) renders as rows.
                             * The importer snapshots all product metadata to
                             * _stripe_shop_lite_stripe_product_metadata;
                             * shoplite_* keys are store configuration and the
                             * declared attribute keys (color/size value lists)
                             * are variant configuration - both stay hidden.
                             */
                            $extra_meta = get_post_meta( $id, '_stripe_shop_lite_stripe_product_metadata', true );
                            if ( is_array( $extra_meta ) ) {
                                $attr_keys = array_map( 'sanitize_key', array_keys( $variant_attributes ?: [] ) );
                                foreach ( $extra_meta as $mk => $mv ) {
                                    $mk = sanitize_key( (string) $mk );
                                    $mv = trim( (string) $mv );
                                    if ( '' === $mv ) continue;
                                    if ( 0 === strpos( $mk, 'shoplite_' ) ) continue;
                                    if ( in_array( $mk, $attr_keys, true ) ) continue;
                                    echo '<tr><th scope="row">' . esc_html( ucwords( str_replace( '_', ' ', $mk ) ) ) . '</th><td>' . esc_html( $mv ) . '</td></tr>';
                                }
                            }
                            ?>
                        </tbody>
                    </table>
                </div>
                <?php if ( $sizing ) : ?>
                <div class="tab-pane fade" id="stripe-shop-lite-tab-sizing" role="tabpanel"><?php echo do_shortcode( wp_kses_post( $sizing ) ); ?></div>
                <?php endif; ?>
                <?php if ( $reviews ) : ?>
                <div class="tab-pane fade" id="stripe-shop-lite-tab-reviews" role="tabpanel">
                    <?php comments_template(); ?>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <?php
        $crowd = get_post_meta( $id, '_shoplite_crowdsignal', true );
        if ( $crowd ) { echo '<div class="stripe-shop-lite-crowdsignal">' . do_shortcode( $crowd ) . '</div>'; }
        ?>
    </article>
    <?php
}

/**
 * Design layer - theme toggle button (presentational only).
 * Purple round button, bottom-right; persists the choice.
 */
echo '<button id="lsm-theme-toggle" type="button" aria-label="Toggle dark / light theme" title="Toggle dark / light theme"></button>';
echo '<script>(function(){var b=document.getElementById("lsm-theme-toggle");if(!b)return;var d=document.documentElement;var paint=function(){b.textContent=(d.getAttribute("data-bs-theme")==="light")?"\u2600":"\u25D0"};paint();b.addEventListener("click",function(){var n=(d.getAttribute("data-bs-theme")==="dark")?"light":"dark";d.setAttribute("data-bs-theme",n);try{localStorage.setItem("lsm-theme",n)}catch(e){}paint();})})();</script>';

/**
 * Spotlight.js provides the gallery lightbox/zoom the template's markup
 * already uses (.spotlight-group / .spotlight with data-src, data-fit,
 * data-control). The local vendor bundle is loaded from
 * assets/vendor/spotlight.bundle.js - no CDN, same convention as the
 * Bootstrap/Font Awesome vendor files. The bundle attaches itself to
 * .spotlight elements automatically; no init code is needed. If the file
 * is absent the images simply render without the lightbox and the page
 * keeps working.
 */
wp_enqueue_script( 'stripe-shop-lite-spotlight', plugins_url( 'assets/vendor/spotlight.bundle.js', STRIPE_SHOP_LITE_FILE ), [], '1.0', false );
wp_print_scripts( 'stripe-shop-lite-spotlight' );
