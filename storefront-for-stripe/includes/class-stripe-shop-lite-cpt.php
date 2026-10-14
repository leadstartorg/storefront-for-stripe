<?php
/**
 * 2. CPT, Taxonomies, Meta Boxes, and the WooCommerce leftover quarantine.
 *
 * Custom fields per product: _stripe_price_id, _stripe_product_id,
 * _stock_level (inventory: empty = not tracked, 0 = sold out -> waitlist),
 * _tier_classification (physical | digital | donation),
 * _stripe_shop_lite_gift (gift flag: digital one-off gift product),
 * _stripe_shop_lite_rating (0-5 stars, optional),
 * _sizing_chart, _shoplite_crowdsignal.
 *
 * Data ownership: Stripe is the single source of truth for _stripe_price_id,
 * _stripe_product_id and the gift flag. They are read-only in the product
 * editor and are never accepted from $_POST, so they cannot be changed or
 * wiped from WordPress; only the Stripe importer writes them. WordPress
 * owns the editable fields: stock level (Stripe has no stock concept in
 * this shop), the product gallery, the sizing chart and the Crowdsignal
 * embed.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class Stripe_Shop_Lite_CPT {

    public static function init() {
        add_action( 'init', [ __CLASS__, 'register' ] );
        add_action( 'pre_get_posts', [ __CLASS__, 'keep_off_blog' ] );
        add_action( 'pre_get_posts', [ __CLASS__, 'hide_woo_leftovers' ] );
        add_action( 'add_meta_boxes', [ __CLASS__, 'metabox' ] );
        add_action( 'admin_enqueue_scripts', [ __CLASS__, 'admin_assets' ] );
        add_filter( 'request', [ __CLASS__, 'normalize_product_archive_request' ] );
        add_action( 'save_post_product', [ __CLASS__, 'save' ] );
        add_action( 'comments_open', [ __CLASS__, 'reviews_open' ], 10, 2 );
        add_filter( 'preprocess_comment', [ __CLASS__, 'buyers_only' ] );
        add_action( 'stripe_shop_lite_quarantine_scan', [ __CLASS__, 'quarantine_scan' ] );
        add_action( 'admin_notices', [ __CLASS__, 'quarantine_notice' ] );
        add_action( 'admin_post_stripe_shop_lite_woo_dismiss', [ __CLASS__, 'woo_dismiss' ] );
        add_action( 'admin_post_stripe_shop_lite_woo_draft', [ __CLASS__, 'woo_draft' ] );
    }

    public static function register() {
        register_post_type( 'product', [
            'labels' => [ 'name' => 'Products', 'singular_name' => 'Product',
                'add_new_item' => 'Add New Product', 'edit_item' => 'Edit Product' ],
            'public' => true, 'show_in_rest' => true, 'has_archive' => 'products',
            'menu_icon' => 'dashicons-cart',
            // with_front => false: WordPress prefixes permalinks with the
            // site's front base (often /blog/) unless this is disabled. Product
            // URLs must be /product/<slug>/, not /blog/product/<slug>/.
            'rewrite' => [ 'slug' => 'product', 'with_front' => false ],
            'supports' => [ 'title', 'editor', 'thumbnail', 'comments', 'revisions', 'custom-fields' ],
            'capability_type' => [ 'product', 'products' ], 'map_meta_cap' => true,
        ] );

        // Backward compatibility: /product/ used to be the archive. Keep the
        // exact legacy URL mapped to the product archive while /product/<slug>/
        // remains the single-product URL.
        add_rewrite_rule( '^product/?$', 'index.php?post_type=product', 'top' );
        add_rewrite_rule( '^product/page/([0-9]+)/?$', 'index.php?post_type=product&paged=$matches[1]', 'top' );

        register_taxonomy( 'product_category', 'product', [
            'labels' => [ 'name' => 'Product Categories', 'singular_name' => 'Product Category' ],
            'hierarchical' => true, 'show_in_rest' => true, 'show_admin_column' => true,
            'rewrite' => [ 'slug' => 'product-category', 'with_front' => false ],
        ] );
        register_taxonomy( 'product_tag', 'product', [
            'labels' => [ 'name' => 'Product Tags', 'singular_name' => 'Product Tag' ],
            'hierarchical' => false, 'show_in_rest' => true,
            'rewrite' => [ 'slug' => 'product-tag', 'with_front' => false ],
        ] );
        register_taxonomy( 'product_keyword', 'product', [
            'labels' => [ 'name' => 'Product Keywords', 'singular_name' => 'Product Keyword' ],
            'hierarchical' => false, 'show_in_rest' => true, 'public' => false,
        ] );
    }

    /**
     * Keep products OFF the blog: home/blog index and plain feeds list normal
     * posts only. The product archive (/product/) and product searches untouched.
     */
    public static function keep_off_blog( $query ) {
        if ( is_admin() || ! $query->is_main_query() ) return;
        if ( $query->is_home() || $query->is_search() && ! isset( $_GET['post_type'] ) && ! $query->get( 'post_type' ) ) {
            $query->set( 'post_type', $query->is_home() ? 'post' : [ 'post', 'page' ] );
        }
    }

    public static function hide_woo_leftovers( $query ) {
        if ( is_admin() ) return;
        $pt = $query->get( 'post_type' );
        $is_product = 'product' === $pt || ( is_array( $pt ) && in_array( 'product', $pt, true ) )
            || $query->is_tax( [ 'product_category', 'product_tag' ] );
        if ( ! $is_product ) return;
        $meta = $query->get( 'meta_query' ) ?: [];
        $meta[] = [ 'key' => '_from_woocommerce', 'compare' => 'NOT EXISTS' ];
        $query->set( 'meta_query', $meta );
    }

    public static function metabox() {
        add_meta_box( 'stripe_shop_lite_product', 'Product', 'stripe_shop_lite_product_metabox', 'product', 'normal', 'high' );
        add_meta_box( 'stripe_shop_lite_stripe_metadata', 'Stripe Metadata (read-only)', 'stripe_shop_lite_stripe_metadata_metabox', 'product', 'normal', 'default' );
        add_meta_box( 'stripe_shop_lite_product_gallery', 'Product gallery', 'stripe_shop_lite_product_gallery_metabox', 'product', 'side', 'default' );
    }

    public static function admin_assets( $hook ) {
        if ( ! in_array( $hook, [ 'post.php', 'post-new.php' ], true ) ) return;
        $screen = get_current_screen();
        if ( ! $screen || 'product' !== $screen->post_type ) return;
        wp_enqueue_media();
    }

    public static function normalize_product_archive_request( $vars ) {
        if ( is_admin() || empty( $_SERVER['REQUEST_URI'] ) ) return $vars;
        $path = wp_parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ), PHP_URL_PATH );
        $home_path = wp_parse_url( home_url( '/' ), PHP_URL_PATH );
        $path = '/' . ltrim( (string) $path, '/' );
        $home_path = '/' . trim( (string) $home_path, '/' );
        if ( '/' !== $home_path ) $path = preg_replace( '#^' . preg_quote( $home_path, '#' ) . '#', '', $path );
        $path = '/' . trim( (string) $path, '/' ) . '/';
        if ( in_array( $path, [ '/product/', '/products/' ], true ) && isset( $vars['post_type'] ) && 'product' === $vars['post_type'] && isset( $vars['s'] ) && '' === trim( (string) $vars['s'] ) ) {
            unset( $vars['s'] );
        }
        return $vars;
    }

    /**
     * Persists WordPress-side fields only. The Stripe price and product
     * IDs, kind, attributes, variants and the gift flag are owned by the
     * Stripe importer and are never accepted from $_POST, so they cannot
     * be edited or wiped from WordPress.
     */
    public static function save( $post_id ) {
        $nonce = isset( $_POST['stripe_shop_lite_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['stripe_shop_lite_nonce'] ) ) : '';
        if ( ! $nonce || ! wp_verify_nonce( $nonce, 'stripe_shop_lite_save_product' ) ) return;
        if ( ! current_user_can( 'edit_post', $post_id ) ) return;
        $old_stock = get_post_meta( $post_id, '_stock_level', true );
        // Stock is WordPress-side inventory (Stripe has no stock concept in
        // this shop): empty = not tracked, 0 = sold out.
        if ( isset( $_POST['_stock_level'] ) ) {
            update_post_meta( $post_id, '_stock_level', sanitize_text_field( trim( wp_unslash( $_POST['_stock_level'] ) ) ) );
        }
        // These two hold markup (a table, an embed), so they keep post-safe HTML.
        foreach ( [ '_shoplite_crowdsignal', '_sizing_chart' ] as $key ) {
            if ( isset( $_POST[ $key ] ) ) update_post_meta( $post_id, $key, wp_kses_post( trim( wp_unslash( $_POST[ $key ] ) ) ) );
        }
        // Restocked from zero: tell the waiting list.
        $new_stock = get_post_meta( $post_id, '_stock_level', true );
        if ( '' !== $new_stock && is_numeric( $new_stock ) && (int) $new_stock > 0 && ( '' === $old_stock || (int) $old_stock <= 0 ) ) {
            Stripe_Shop_Lite_Stripe::notify_waitlist( $post_id );
        }
        // WooCommerce-compatible product gallery: attachment IDs stored as a comma-separated string.
        if ( isset( $_POST['_product_image_gallery'] ) ) {
            $raw_gallery = sanitize_text_field( wp_unslash( $_POST['_product_image_gallery'] ) );
            $ids = array_values( array_filter( array_map( 'absint', explode( ',', $raw_gallery ) ) ) );
            update_post_meta( $post_id, '_product_image_gallery', implode( ',', $ids ) );
        }
        // The gift flag is Stripe-owned (shoplite_gift, written by the
        // importer) and is not saved here, so a manual save can never
        // overwrite it.

        if ( get_post_meta( $post_id, '_stripe_price_id', true ) ) {
            delete_post_meta( $post_id, '_from_woocommerce' ); // adopting a leftover
        }
    }

    /* ---------- Reviews: WordPress comments, off by default ---------- */

    public static function reviews_open( $open, $post_id ) {
        return get_post_type( $post_id ) === 'product' ? (bool) stripe_shop_lite_opt( 'reviews_on' ) : $open;
    }

    public static function buyers_only( $data ) {
        if ( ! stripe_shop_lite_opt( 'buyers_only_reviews' ) ) return $data;
        $pid = (int) ( $data['comment_post_ID'] ?? 0 );
        if ( get_post_type( $pid ) !== 'product' ) return $data;
        $u = wp_get_current_user();
        if ( $u && ( in_array( 'stripe_shop_manager', (array) $u->roles, true ) || user_can( $u, 'manage_options' ) ) ) return $data;
        $owned = get_user_meta( $u->ID, '_stripe_shop_lite_owned_products', true );
        if ( ! is_array( $owned ) || ! in_array( $pid, array_map( 'intval', $owned ), true ) ) {
            wp_die( 'Only customers who have bought this product can review it.' );
        }
        return $data;
    }

    /* ---------- WooCommerce leftover quarantine ---------- */

    public static function quarantine_scan() {
        $batch = (int) get_option( 'stripe_shop_lite_quarantine_batch', 0 );
        $q = new WP_Query( [ 'post_status' => 'any', 'posts_per_page' => 100, 'offset' => $batch, 'fields' => 'ids', 'post_type' => 'product' ] );
        $found = 0;
        foreach ( $q->posts as $pid ) {
            $is_woo = get_post_meta( $pid, '_regular_price', true ) || get_post_meta( $pid, '_price', true )
                || get_post_meta( $pid, '_manage_stock', true ) || get_post_meta( $pid, '_virtual', true )
                || get_post_meta( $pid, '_downloadable', true );
            $has_price = get_post_meta( $pid, '_stripe_price_id', true );
            if ( $is_woo && ! $has_price ) { update_post_meta( $pid, '_from_woocommerce', '1' ); $found++; }
            elseif ( $has_price ) { delete_post_meta( $pid, '_from_woocommerce' ); }
        }
        if ( count( $q->posts ) === 100 ) {
            update_option( 'stripe_shop_lite_quarantine_batch', $batch + 100 );
            wp_schedule_single_event( time() + 60, 'stripe_shop_lite_quarantine_scan' );
        } else {
            update_option( 'stripe_shop_lite_quarantine_found', (int) get_option( 'stripe_shop_lite_quarantine_found', 0 ) + $found );
            delete_option( 'stripe_shop_lite_quarantine_batch' );
        }
    }

    public static function quarantine_notice() {
        if ( ! current_user_can( 'manage_options' ) ) return;
        $found = (int) get_option( 'stripe_shop_lite_quarantine_found', 0 );
        if ( ! $found || get_option( 'stripe_shop_lite_quarantine_dismissed' ) ) return;
        echo '<div class="notice notice-info"><p><strong>' . esc_html( $found ) . ' old WooCommerce product(s) found.</strong> Hidden from the shop, URLs return "not found". Nothing deleted. ';
        echo '<a href="' . esc_url( admin_url( 'edit.php?post_type=product&meta_key=_from_woocommerce' ) ) . '">Review them</a> &mdash; ';
        echo '<a href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=stripe_shop_lite_woo_draft' ), 'stripe_shop_lite_woo_draft' ) ) . '">Move all to Draft</a> &mdash; ';
        echo '<a href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=stripe_shop_lite_woo_dismiss' ), 'stripe_shop_lite_woo_dismiss' ) ) . '">Leave hidden, stop reminding me</a>';
        echo '</p><p>To bring a product into the new catalog, set it up in Stripe and run the Stripe importer. Stripe is the source of truth for products and prices.</p></div>';
    }

    public static function woo_dismiss() {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Not allowed.' );
        check_admin_referer( 'stripe_shop_lite_woo_dismiss' );
        update_option( 'stripe_shop_lite_quarantine_dismissed', 1 );
        wp_safe_redirect( admin_url() ); exit;
    }

    public static function woo_draft() {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Not allowed.' );
        check_admin_referer( 'stripe_shop_lite_woo_draft' );
        $q = new WP_Query( [ 'post_type' => 'product', 'post_status' => 'any', 'posts_per_page' => -1, 'meta_key' => '_from_woocommerce', 'fields' => 'ids' ] );
        foreach ( $q->posts as $pid ) wp_update_post( [ 'ID' => $pid, 'post_status' => 'draft' ] );
        update_option( 'stripe_shop_lite_quarantine_dismissed', 1 );
        wp_safe_redirect( admin_url( 'edit.php?post_type=product' ) ); exit;
    }
}

/**
 * Normalized product kind. Stripe already knows recurring vs one-off
 * (that's a property of the price), so this field only answers two
 * questions: does it ship, and is it a donation. Everything else -
 * software, downloads, memberships, virtual, digital - is "digital".
 */
function stripe_shop_lite_product_kind( $post_id ) {
    $k = strtolower( (string) get_post_meta( $post_id, '_tier_classification', true ) );
    if ( in_array( $k, [ 'saas', 'digital', 'downloadable', 'virtual', 'software' ], true ) ) return 'digital';
    if ( 'donation' === $k ) return 'donation';
    if ( 'physical' === $k ) return 'physical';
    return ''; // unset
}

/** Is this product a gift product? Only meaningful for digital one-off prices. */
function stripe_shop_lite_product_is_gift( $post_id ) {
    return (bool) get_post_meta( $post_id, '_stripe_shop_lite_gift', true );
}


function stripe_shop_lite_product_gallery_metabox( $post ) {
    $raw = get_post_meta( $post->ID, '_product_image_gallery', true );
    $ids = array_values( array_filter( array_map( 'absint', explode( ',', (string) $raw ) ) ) );
    ?>
    <div class="stripe-shop-lite-gallery-admin">
        <input type="hidden" id="_product_image_gallery" name="_product_image_gallery" value="<?php echo esc_attr( implode( ',', $ids ) ); ?>" />
        <div id="stripe-shop-lite-gallery-preview" style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:10px;">
            <?php foreach ( $ids as $attachment_id ) :
                $thumb = wp_get_attachment_image_url( $attachment_id, 'thumbnail' );
                if ( ! $thumb ) continue;
                ?>
                <div class="stripe-shop-lite-gallery-item" data-id="<?php echo esc_attr( $attachment_id ); ?>" style="position:relative;width:72px;height:72px;border:1px solid #ccd0d4;background:#fff;overflow:hidden;">
                    <img src="<?php echo esc_url( $thumb ); ?>" alt="" style="width:100%;height:100%;object-fit:cover;display:block;" />
                    <button type="button" class="button-link stripe-shop-lite-gallery-remove" aria-label="Remove image" style="position:absolute;right:2px;top:2px;background:#fff;border-radius:50%;width:22px;height:22px;line-height:18px;text-align:center;text-decoration:none;color:#b32d2e;font-weight:700;">&times;</button>
                </div>
            <?php endforeach; ?>
        </div>
        <p>
            <button type="button" class="button button-primary" id="stripe-shop-lite-gallery-add">Add gallery images</button>
            <button type="button" class="button" id="stripe-shop-lite-gallery-clear" <?php disabled( empty( $ids ) ); ?>>Clear</button>
        </p>
        <p class="description">Select additional product images from the Media Library. The featured image remains the first image on the product page.</p>
    </div>
    <script>
    jQuery(function($){
        var $field = $('#_product_image_gallery');
        var $preview = $('#stripe-shop-lite-gallery-preview');
        var frame;

        function ids(){
            var out = [];
            $preview.find('.stripe-shop-lite-gallery-item').each(function(){
                var id = parseInt($(this).data('id'),10);
                if (id && out.indexOf(id) === -1) out.push(id);
            });
            return out;
        }
        function sync(){
            var list = ids();
            $field.val(list.join(','));
            $('#stripe-shop-lite-gallery-clear').prop('disabled', !list.length);
        }
        function addItem(id, url){
            if (!id || $preview.find('.stripe-shop-lite-gallery-item[data-id="'+id+'"]').length) return;
            $preview.append(
                $('<div/>',{class:'stripe-shop-lite-gallery-item', 'data-id':id}).css({position:'relative',width:'72px',height:'72px',border:'1px solid #ccd0d4',background:'#fff',overflow:'hidden'}).append(
                    $('<img/>',{src:url,alt:''}).css({width:'100%',height:'100%',objectFit:'cover',display:'block'}),
                    $('<button/>',{type:'button',class:'button-link stripe-shop-lite-gallery-remove','aria-label':'Remove image',html:'&times;'}).css({position:'absolute',right:'2px',top:'2px',background:'#fff',borderRadius:'50%',width:'22px',height:'22px',lineHeight:'18px',textAlign:'center',textDecoration:'none',color:'#b32d2e',fontWeight:'700'})
                )
            );
        }

        $('#stripe-shop-lite-gallery-add').on('click', function(e){
            e.preventDefault();
            if (frame) { frame.open(); return; }
            frame = wp.media({
                title: 'Select product gallery images',
                button: { text: 'Use selected images' },
                library: { type: 'image' },
                multiple: true
            });
            frame.on('select', function(){
                frame.state().get('selection').each(function(att){
                    var data = att.toJSON();
                    var url = (data.sizes && data.sizes.thumbnail) ? data.sizes.thumbnail.url : data.url;
                    addItem(data.id, url);
                });
                sync();
            });
            frame.open();
        });

        $preview.on('click', '.stripe-shop-lite-gallery-remove', function(e){
            e.preventDefault();
            $(this).closest('.stripe-shop-lite-gallery-item').remove();
            sync();
        });

        $('#stripe-shop-lite-gallery-clear').on('click', function(e){
            e.preventDefault();
            $preview.empty();
            sync();
        });
    });
    </script>
    <?php
}

function stripe_shop_lite_stripe_metadata_metabox( $post ) {
    $product_meta = get_post_meta( $post->ID, '_stripe_shop_lite_stripe_product_metadata', true );
    $price_meta   = get_post_meta( $post->ID, '_stripe_shop_lite_stripe_price_metadata', true );
    $attributes   = stripe_shop_lite_product_attributes( $post->ID );
    $variants     = stripe_shop_lite_product_variants( $post->ID );

    if ( ! is_array( $product_meta ) ) $product_meta = [];
    if ( ! is_array( $price_meta ) ) $price_meta = [];
    if ( ! is_array( $attributes ) ) $attributes = [];
    if ( ! is_array( $variants ) ) $variants = [];

    echo '<p class="description"><strong>Read-only.</strong> Stripe is the source of truth. Change metadata in Stripe, then run the Stripe importer again.</p>';

    echo '<table class="widefat striped" style="max-width:900px;margin:12px 0;">';
    echo '<thead><tr><th colspan="2">Stripe Product metadata</th></tr></thead><tbody>';
    if ( $product_meta ) {
        foreach ( $product_meta as $key => $value ) {
            echo '<tr><td style="width:30%"><code>' . esc_html( $key ) . '</code></td><td><code>' . esc_html( (string) $value ) . '</code></td></tr>';
        }
    } else {
        echo '<tr><td colspan="2"><em>No Stripe Product metadata has been imported yet.</em></td></tr>';
    }
    echo '</tbody></table>';

    echo '<h4>Imported attributes</h4>';
    if ( $attributes ) {
        echo '<table class="widefat striped" style="max-width:900px;margin:12px 0;"><thead><tr><th>Attribute</th><th>Values</th></tr></thead><tbody>';
        foreach ( $attributes as $key => $definition ) {
            $label  = is_array( $definition ) ? ( $definition['label'] ?? $key ) : $key;
            $values = is_array( $definition ) ? ( $definition['values'] ?? [] ) : [];
            echo '<tr><td><code>' . esc_html( $label ) . '</code></td><td>' . esc_html( implode( ', ', array_map( 'strval', is_array( $values ) ? $values : [] ) ) ) . '</td></tr>';
        }
        echo '</tbody></table>';
    } else {
        echo '<p><em>No imported attributes.</em></p>';
    }

    echo '<h4>Imported variants</h4>';
    if ( $variants ) {
        echo '<table class="widefat striped" style="max-width:900px;margin:12px 0;"><thead><tr><th>Stripe Price</th><th>Attributes</th><th>Amount</th><th>Currency</th><th>Recurring</th></tr></thead><tbody>';
        foreach ( $variants as $variant ) {
            $attrs = is_array( $variant['attributes'] ?? null ) ? $variant['attributes'] : [];
            $parts = [];
            foreach ( $attrs as $ak => $av ) $parts[] = $ak . '=' . $av;
            $unit = isset( $variant['unit'] ) ? (int) $variant['unit'] : 0;
            $currency = strtoupper( (string) ( $variant['currency'] ?? '' ) );
            $amount = $unit / 100;
            echo '<tr><td><code>' . esc_html( (string) ( $variant['price_id'] ?? '' ) ) . '</code></td>';
            echo '<td>' . esc_html( implode( ', ', $parts ) ) . '</td>';
            echo '<td>' . esc_html( number_format( $amount, 2, '.', '' ) ) . '</td>';
            echo '<td>' . esc_html( $currency ) . '</td>';
            echo '<td>' . ( ! empty( $variant['recurring'] ) ? 'Yes' : 'No' ) . '</td></tr>';
        }
        echo '</tbody></table>';
    } else {
        echo '<p><em>No variants were imported. If attributes are defined, make sure at least one active Stripe Price has matching (or no) attribute metadata, then re-run the importer.</em></p>';
    }

    if ( $price_meta ) {
        echo '<details style="max-width:900px;margin-top:14px;"><summary><strong>Raw Stripe Price metadata used by the importer</strong></summary>';
        echo '<table class="widefat striped" style="margin-top:10px;"><thead><tr><th>Stripe Price</th><th>Metadata</th></tr></thead><tbody>';
        foreach ( $price_meta as $price_id => $meta ) {
            $pairs = [];
            if ( is_array( $meta ) ) foreach ( $meta as $key => $value ) $pairs[] = $key . '=' . $value;
            echo '<tr><td><code>' . esc_html( $price_id ) . '</code></td><td><code>' . esc_html( implode( ', ', $pairs ) ) . '</code></td></tr>';
        }
        echo '</tbody></table></details>';
    }
}

function stripe_shop_lite_product_metabox( $post ) {
    wp_nonce_field( 'stripe_shop_lite_save_product', 'stripe_shop_lite_nonce' );
    $price_id = get_post_meta( $post->ID, '_stripe_price_id', true );
    $price    = stripe_shop_lite_price_info( $price_id );
    echo '<table class="form-table">';
    if ( $price_id ) {
        $display = $price
            ? stripe_shop_lite_format_money( $price['unit'], $price['currency'] ) . ( $price['recurring'] ? '/mo' : '' )
            : '(unknown. Check the price ID.)';
        printf( '<tr><th>Price</th><td><code style="font-size:15px">%s</code><p class="description">Read from Stripe. Edit the price in Stripe, not here.</p></td></tr>', esc_html( $display ) );
        printf( '<tr><th>Billing</th><td><code>%s</code><p class="description">Derived from the Stripe price, not a field. A recurring price creates a subscription; a one-off price is a single charge.</p></td></tr>',
            esc_html( $price ? ( $price['recurring'] ? 'Recurring (subscription)' : 'One-off' ) : 'unknown' ) );
    }
    // Stripe price and product IDs are read-only; only the importer writes them.
    printf( '<tr><th>Stripe price ID</th><td><code>%s</code><p class="description">Read-only. Set by the Stripe importer from your Stripe product. Edit the price in Stripe and re-run the importer.</p></td></tr>',
        esc_html( $price_id ?: '(not imported yet)' ) );
    printf( '<tr><th>Stripe product ID</th><td><code>%s</code><p class="description">Read-only. The importer matches on this ID, which is what makes re-imports update instead of duplicate.</p></td></tr>',
        esc_html( (string) get_post_meta( $post->ID, '_stripe_product_id', true ) ?: '(not imported yet)' ) );
    $kind = stripe_shop_lite_product_kind( $post->ID );
    echo '<tr><th>Kind</th><td><code>' . esc_html( $kind ?: '- none -' ) . '</code><p class="description">Imported from Stripe metadata (<code>shoplite_classification</code>). Edit it in Stripe and re-run the importer. Billing type is automatic from the Stripe price.</p></td></tr>';
    // Stock remains editable; it is WordPress-side inventory.
    printf( '<tr><th><label for="_stock_level">%1$s</label></th><td><input type="text" class="regular-text" id="_stock_level" name="_stock_level" value="%2$s" /><p class="description">WordPress-side inventory (Stripe has no stock for this shop): empty = not tracked, 0 = sold out.</p></td></tr>',
        esc_html( 'Stock (empty = not tracked, 0 = sold out)' ), esc_attr( get_post_meta( $post->ID, '_stock_level', true ) ) );
    foreach ( [ '_shoplite_crowdsignal' => 'Crowdsignal embed or shortcode (optional)', '_sizing_chart' => 'Sizing chart: HTML table or shortcode (optional)' ] as $key => $label ) {
        printf( '<tr><th><label for="%1$s">%2$s</label></th><td><textarea class="large-text code" rows="4" id="%1$s" name="%1$s">%3$s</textarea></td></tr>',
            esc_attr( $key ), esc_html( $label ), esc_textarea( get_post_meta( $post->ID, $key, true ) ) );
    }
    // Gift is display-only, imported from Stripe metadata (shoplite_gift).
    echo '<tr><th>Gift</th><td><code>' . ( stripe_shop_lite_product_is_gift( $post->ID ) ? 'Yes' : 'No' ) . '</code>' .
        '<p class="description">Read-only. Imported from Stripe product metadata (<code>shoplite_gift</code>). Edit it in Stripe and re-run the importer. Gift products must use a one-off price; subscriptions cannot be gifted directly (sell a gift certificate product instead).</p></td></tr>';
    echo '</table>';
}
