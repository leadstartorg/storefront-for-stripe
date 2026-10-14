<?php
/**
 * 3e. Product Import Controller - admin.php?page=storefront-for-stripe-import
 *
 * Imports Stripe products into WordPress as 'product' posts, including
 * variant support. Products match on the Stripe product ID, so the import
 * is safe to run repeatedly: a second run updates and never duplicates. Your
 * slug, page content, stock, categories and images are never changed.
 *
 * Import behavior notes:
 *   - shoplite_classification / shoplite_gift are read through the
 *     key-normalized metadata array, so "Shoplite_Classification" or
 *     "ShopLite_Classification" import too (Stripe metadata keys are
 *     case-sensitive at the API, so direct property access would
 *     silently fail on any other spelling).
 *   - The "no kind set" flag fires for existing products too, not only
 *     for newly created ones, so a missing classification stays visible.
 *   - Products are paginated (has_more / starting_after) exactly like
 *     prices, so catalogs with more than 100 products are not truncated
 *     after the first page.
 *   - An attribute listed in shoplite_attributes but with no values key
 *     on the product metadata is flagged instead of silently producing
 *     an empty attribute.
 *   - Stripe's StripeObject is not Traversable (it only implements
 *     ArrayAccess/Countable/JsonSerializable and keeps values in a
 *     protected $_values array), so `foreach` over it yields nothing.
 *     Metadata is therefore converted via toArray() before iterating.
 *   - Prices can carry partial combinations (wildcards): color = green
 *     covers every size, size = XXL covers every color, and a price
 *     with no attribute metadata is a catch-all. A shoplite_priority value
 *     (integer, optional) on a price breaks specificity ties.
 *     The full combination table is expanded at import time, so Stripe
 *     needs one price per price point, not one per combination.
 *   - PRICE metadata values are parsed with the same list parser as
 *     product metadata, so "{red, blue}", "(green)" and plain "green"
 *     all import. A price listing several values for an attribute
 *     (color = "{red, blue}") matches any of them, and a price listing
 *     all declared values of an attribute is treated as a wildcard for
 *     that attribute rather than a constraint.
 *   - Prices configured as customer-defined amounts (custom_unit_amount)
 *     are flagged at import: Stripe Checkout refuses to sell such a
 *     price alongside any other line item or with a quantity above 1,
 *     so it cannot work in a multi-item cart - the price needs a fixed
 *     unit amount in Stripe.
 *
 * Stripe metadata convention (this importer never guesses):
 *   Product metadata:
 *     shoplite_attributes = "{Color, Size}"   // attribute names
 *     color               = "{red, blue}"     // values per attribute (key = sanitize_key(name))
 *     size                = "{S, M, L}"
 *     shoplite_classification = physical | digital | donation
 *     shoplite_gift       = 1
 *   Price metadata - list only the attributes that CONSTRAIN the price;
 *   unlisted attributes are wildcards:
 *     color = "red", size = "M"   // one exact combination
 *     color = "green"             // green in EVERY size
 *     size = "XXL"                // EVERY color in XXL
 *     (no attribute metadata)     // catch-all price for all combinations
 *     shoplite_priority = 10      // optional: wins ties against other
 *                                 // equally specific prices
 *   The most specific match always wins; ties are broken by
 *   shoplite_priority, then by the first price in Stripe's list (flagged).
 *
 * NOTE: shoplite_classification / shoplite_attributes / the value keys must
 * live on the PRODUCT metadata in Stripe; the per-combination keys live on
 * each PRICE. Setting classification only on the price will not import.
 * Metadata KEYS are case-insensitive (normalized on read), but VALUES are
 * case-sensitive and must match exactly on both sides (product and price).
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class Stripe_Shop_Lite_Importer {

    public static function init() {
        // The menu item is registered by the Admin class; this owns the page render.
    }

    /**
     * Converts Stripe metadata into a simple PHP array with lowercased keys.
     * StripeObject is not Traversable (its values live in a protected
     * $_values array), so the object is converted via toArray() before
     * iterating.
     */
    private static function metadata_array( $metadata ) {
        $out = [];
        if ( ! $metadata ) return $out;
        if ( is_object( $metadata ) && method_exists( $metadata, 'toArray' ) ) {
            $metadata = $metadata->toArray();
        } elseif ( is_object( $metadata ) ) {
            $metadata = get_object_vars( $metadata );
        }
        if ( ! is_array( $metadata ) ) return $out;
        foreach ( $metadata as $key => $value ) {
            $key = sanitize_key( (string) $key );
            // storefront_* is accepted as an alias for shoplite_* (normalized once, here).
            $key = preg_replace( '/^storefront_/', 'shoplite_', $key );
            if ( '' === $key ) continue;
            $out[ $key ] = is_scalar( $value ) ? trim( (string) $value ) : '';
        }
        return $out;
    }

    /** Parse Stripe metadata such as "{red, blue, green}", "(green)" or "red,blue,green". */
    private static function metadata_values( $value ) {
        $value = trim( (string) $value );
        $value = trim( $value, "{}[]() \t\n\r\0\x0B" );
        if ( '' === $value ) return [];
        $values = array_map( 'trim', explode( ',', $value ) );
        $values = array_values( array_filter( array_map( 'sanitize_text_field', $values ), 'strlen' ) );
        return array_values( array_unique( $values ) );
    }

    /**
     * Read the attribute definitions from Product metadata.
     * Returns [ 'color' => [ 'label' => 'Color', 'values' => ['red','blue'] ], ... ].
     * Keys are lowercased (sanitize_key) so they match metadata_array() output;
     * the original label is preserved for display.
     */
    private static function attribute_map( $metadata ) {
        $pm = self::metadata_array( $metadata );
        $map = [];
        $names_raw = $pm['shoplite_attributes'] ?? '';
        foreach ( self::metadata_values( $names_raw ) as $label ) {
            $key = sanitize_key( $label );
            if ( '' === $key || isset( $map[ $key ] ) ) continue;
            $map[ $key ] = [
                'label' => $label,
                'values'=> self::metadata_values( $pm[ $key ] ?? '' ),
            ];
        }
        return $map;
    }

    /**
     * Attribute-value images (color images for the cards, the catalog
     * color filter view and the detail-page gallery swap). Declared in
     * Stripe as product metadata, one key per attribute, mirroring the
     * value-list scheme (value lists live on the product, not the price):
     *   shoplite_image_color = "{red=https://cdn.example.com/tote-red.jpg, blue=https://...}"
     * Stripe products have images, but nothing binds image to attribute, so
     * the binding lives in metadata exactly like the attribute values do.
     * Only declared attributes are read; only absolute http(s) URLs are
     * stored. Stored as _stripe_shop_lite_attribute_images:
     *   [ 'color' => [ 'red' => 'https://...', ... ] ]
     */
    private static function attribute_images( $metadata, array $attribute_map ) {
        $pm = self::metadata_array( $metadata );
        $out = [];
        foreach ( array_keys( $attribute_map ) as $key ) {
            $raw = $pm[ 'shoplite_image_' . $key ] ?? '';
            if ( '' === $raw ) continue;
            foreach ( self::metadata_values( $raw ) as $pair ) {
                $pos = strpos( $pair, '=' );
                if ( false === $pos ) continue;
                $value = trim( substr( $pair, 0, $pos ) );
                $url   = trim( substr( $pair, $pos + 1 ) );
                if ( '' === $value || '' === $url ) continue;
                if ( ! preg_match( '#^https?://#i', $url ) ) continue; // absolute URLs only
                $out[ $key ][ $value ] = $url;
            }
            if ( empty( $out[ $key ] ) ) unset( $out[ $key ] );
        }
        return $out;
    }

    /**
     * Variant mapping with partial combinations (wildcards).
     * A price only needs to list the attributes that constrain it:
     *   color = "green"            -> green in EVERY declared size
     *   size = "XXL"               -> EVERY declared color in XXL
     *   no attribute metadata      -> catch-all price for every combination
     *   color = "red", size = "M"  -> that exact combination (most specific)
     * At import time every declared combination is resolved to its best
     * matching price, so the stored variant table is complete and
     * frontend-ready while Stripe only needs one price per price point -
     * never one price per combination. Most specific match wins; ties are
     * broken by optional shoplite_priority metadata, then by Stripe list
     * order (flagged so overlaps can never misprice silently).
     *
     * Returns [ 'variants' => [...], 'notes' => [...] ].
     */
    private static function build_variants( $prices, array $attribute_map ) {
        $keys  = array_keys( $attribute_map );
        $notes = [];

        // 1. One matching rule per price. Unlisted attributes are wildcards.
        //    Each value goes through metadata_values(), so "{red, blue}",
        //    "(green)" and plain "green" are all valid spellings. A multi-value
        //    entry matches any of the listed values; an entry that lists all
        //    declared values of an attribute is a wildcard for it (spec ignores
        //    it, so a full list can never outrank a genuinely partial match).
        $rules = [];
        $has_specific = false;
        foreach ( $prices as $pr ) {
            $pm    = self::metadata_array( $pr->metadata ?? null );
            $match = [];
            $bad   = [];
            foreach ( $keys as $key ) {
                if ( isset( $pm[ $key ] ) && '' !== $pm[ $key ] ) {
                    $vals    = self::metadata_values( $pm[ $key ] );
                    $allowed = $attribute_map[ $key ]['values'] ?? [];
                    if ( ! $vals ) continue;
                    if ( $allowed && $vals == $allowed ) continue; // full list = wildcard, not a constraint
                    $unknown = $allowed ? array_diff( $vals, $allowed ) : [];
                    if ( $unknown ) {
                        $bad[] = $key . '=' . implode( ',', $unknown );
                        continue;
                    }
                    $match[ $key ] = $vals;
                }
            }
            if ( $bad ) {
                $notes[] = 'price ' . $pr->id . ' lists ' . implode( ', ', $bad ) . ' which is not a declared value of that attribute. Fix it in Stripe';
            }
            $prio = ( isset( $pm['shoplite_priority'] ) && is_numeric( $pm['shoplite_priority'] ) ) ? (int) $pm['shoplite_priority'] : 0;
            if ( $match ) $has_specific = true;
            $rules[] = [ 'price' => $pr, 'match' => $match, 'spec' => count( $match ), 'prio' => $prio ];
        }

        // 2. Enumerate every declared combination (cartesian product of values).
        $combos = [ [] ];
        foreach ( $keys as $key ) {
            $values = $attribute_map[ $key ]['values'] ?? [];
            $next = [];
            foreach ( $combos as $base ) {
                foreach ( $values as $val ) $next[] = $base + [ $key => $val ];
            }
            $combos = $next;
        }
        if ( ! $combos ) return [ 'variants' => [], 'notes' => $notes ]; // an attribute with no values is flagged in the main loop
        if ( count( $combos ) > 500 ) {
            $notes[] = 'attribute values expand to ' . count( $combos ) . ' combinations (limit 500). Variants not imported; reduce the declared values';
            return [ 'variants' => [], 'notes' => $notes ];
        }

        // 3. Resolve each combination to its best matching price.
        $variants  = [];
        $ambiguous = [];
        foreach ( $combos as $combo ) {
            $best = null;
            $tied = [];
            foreach ( $rules as $rule ) {
                $ok = true;
                foreach ( $rule['match'] as $k => $vals ) {
                    if ( ! in_array( $combo[ $k ] ?? null, $vals, true ) ) { $ok = false; break; }
                }
                if ( ! $ok ) continue;
                if ( null === $best
                    || $rule['spec'] > $best['spec']
                    || ( $rule['spec'] === $best['spec'] && $rule['prio'] > $best['prio'] ) ) {
                    $best = $rule;
                    $tied = [ $rule['price']->id ];
                } elseif ( $rule['spec'] === $best['spec'] && $rule['prio'] === $best['prio'] && $rule['price']->id !== $best['price']->id ) {
                    $tied[] = $rule['price']->id;
                }
            }
            if ( ! $best ) continue; // no price covers this combination
            if ( count( $tied ) > 1 ) {
                $combo_txt = [];
                foreach ( $keys as $k ) $combo_txt[] = $k . '=' . $combo[ $k ];
                $ambiguous[] = implode( '/', $combo_txt ) . ' matched equally by ' . implode( ', ', $tied );
            }
            $pr = $best['price'];
            $variants[] = [
                'price_id'  => (string) $pr->id,
                'attributes'=> $combo,
                'unit'      => ( isset( $pr->unit_amount ) && null !== $pr->unit_amount ) ? (int) $pr->unit_amount : 0,
                'currency'  => strtoupper( (string) ( $pr->currency ?? '' ) ),
                'recurring' => ! empty( $pr->recurring ),
                'active'    => ! empty( $pr->active ),
            ];
        }

        if ( $variants && ! $has_specific ) {
            $notes[] = 'no price carries attribute metadata. All ' . count( $combos ) . ' combinations use the first active price as a catch-all';
        }
        if ( $ambiguous ) {
            $notes[] = 'ambiguous: ' . implode( '; ', array_slice( $ambiguous, 0, 3 ) ) . '. First price was used; give the price that must win the full combination, or set shoplite_priority';
        }
        return [ 'variants' => $variants, 'notes' => $notes ];
    }

    /**
     * Load every active Price for a product, following pagination so
     * catalogs with more than 100 prices are not silently truncated.
     * Never throws: a failure is reported as an empty list + flag.
     */
    private static function load_prices( $product_id ) {
        $list = [];
        $cursor = null;
        do {
            $params = [ 'product' => $product_id, 'limit' => 100, 'active' => true ];
            if ( $cursor ) $params['starting_after'] = $cursor;
            $prices = \Stripe\Price::all( $params );
            if ( ! is_object( $prices ) || ! isset( $prices->data ) || ! is_array( $prices->data ) ) break;
            foreach ( $prices->data as $pr ) $list[] = $pr;
            $last = end( $prices->data );
            $cursor = ( ! empty( $prices->has_more ) && $last && ! empty( $last->id ) ) ? $last->id : null;
        } while ( $cursor );
        return $list;
    }

    /**
     * Loads every active product with pagination, mirroring load_prices().
     */
    private static function load_products() {
        $list = [];
        $cursor = null;
        do {
            $params = [ 'active' => true, 'limit' => 100, 'expand' => [ 'data.default_price' ] ];
            if ( $cursor ) $params['starting_after'] = $cursor;
            $products = \Stripe\Product::all( $params );
            if ( ! is_object( $products ) || ! isset( $products->data ) || ! is_array( $products->data ) ) break;
            foreach ( $products->data as $p ) $list[] = $p;
            $last = end( $products->data );
            $cursor = ( ! empty( $products->has_more ) && $last && ! empty( $last->id ) ) ? $last->id : null;
        } while ( $cursor );
        return $list;
    }

    public static function page() {
        if ( ! current_user_can( 'manage_options' ) ) return;
        $run = isset( $_POST['stripe_shop_lite_import_run'] ) && check_admin_referer( 'stripe_shop_lite_import', 'stripe_shop_lite_import' );
        if ( ! stripe_shop_lite_stripe() ) { echo '<div class="wrap"><h1>Import from Stripe</h1><p>Save a Stripe key first.</p></div>'; return; }

        $results = []; $created = 0; $updated = 0; $flagged = 0;
        try {
            foreach ( self::load_products() as $p ) {
                $price_id = '';
                $auto_picked = false;
                $flags = []; // collected, never overwritten

                // Always load all active Prices: variants must be mapped for
                // every product, even those that already have a default price.
                // A per-product failure only flags that product; the import run continues.
                $price_list = [];
                try {
                    $price_list = self::load_prices( $p->id );
                } catch ( \Throwable $e ) {
                    $flags[] = 'could not load prices from Stripe: ' . $e->getMessage();
                }

                // A customer-defined (custom) amount price cannot be sold
                // alongside other line items or with a quantity above 1 in
                // Stripe Checkout; flag it so the cause is visible at import,
                // not only at checkout.
                foreach ( $price_list as $custom_check ) {
                    if ( ! empty( $custom_check->custom_unit_amount ) ) {
                        $flags[] = 'price ' . $custom_check->id . ' uses a customer-defined (custom) amount. Stripe Checkout can only sell it alone with quantity 1. Set a fixed unit amount on the price in Stripe';
                    }
                }

                if ( isset( $p->default_price->id ) ) $price_id = $p->default_price->id;
                elseif ( $p->default_price ) $price_id = $p->default_price;
                elseif ( count( $price_list ) ) {
                    // Pick the first recurring price (subscriptions) so recurring
                    // products are sellable immediately; otherwise the first price.
                    $pick = null;
                    foreach ( $price_list as $pr ) { if ( ! empty( $pr->recurring ) ) { $pick = $pr; break; } }
                    if ( ! $pick ) $pick = $price_list[0];
                    $price_id = $pick->id;
                    $auto_picked = true;
                }

                $existing = get_posts( [ 'post_type' => 'product', 'meta_key' => '_stripe_product_id', 'meta_value' => $p->id, 'numberposts' => 1, 'post_status' => 'any', 'fields' => 'ids' ] );

                if ( ! $price_id ) $flags[] = 'flagged: no active price on this Stripe product. Add one in Stripe and re-run';
                elseif ( $auto_picked ) $flags[] = 'no default price set. Using ' . $price_id . ' (first active). Set a default price in Stripe to control which one the shop uses';

                // Classification and gift are read through the normalized
                // array so the metadata key is case-insensitive (the value
                // is still lowercased).
                $pm_all = self::metadata_array( $p->metadata ?? null );
                $cls_raw = strtolower( (string) ( $pm_all['shoplite_classification'] ?? '' ) );
                $cls_norm = in_array( $cls_raw, [ 'saas', 'downloadable', 'virtual', 'software' ], true ) ? 'digital' : $cls_raw;

                // Variants: attribute names from Product metadata, combinations from Price metadata.
                $attribute_map = self::attribute_map( $p->metadata ?? null );
                $images_map    = self::attribute_images( $p->metadata ?? null, $attribute_map );
                $variant_out = $attribute_map ? self::build_variants( $price_list, $attribute_map ) : [ 'variants' => [], 'notes' => [] ];
                $variants = $variant_out['variants'];
                foreach ( $variant_out['notes'] as $variant_note ) $flags[] = $variant_note;
                if ( $attribute_map && ! $variants && ! $variant_out['notes'] ) $flags[] = 'attributes defined but no active price matched a combination. Add attribute metadata to a price in Stripe';
                // An attribute with no values key on the product is a
                // configuration mistake; flag it instead of importing it
                // silently empty.
                foreach ( $attribute_map as $a_key => $a_def ) {
                    if ( empty( $a_def['values'] ) ) {
                        $flags[] = 'attribute "' . $a_def['label'] . '" has no values on the product metadata. Add ' . $a_key . ' = "{value1, value2}" in Stripe';
                    }
                }

                $pid = 0;
                if ( $run ) {
                    if ( $existing ) {
                        $pid = $existing[0];
                        // Never blank a price that is already set: a Stripe product with no
                        // active price would otherwise make an existing product unbuyable.
                        if ( $price_id ) update_post_meta( $pid, '_stripe_price_id', $price_id ); // never touches slug/content/stock/images
                        $updated++;
                    } else {
                        $pid = wp_insert_post( [ 'post_type' => 'product', 'post_status' => 'draft', 'post_title' => $p->name, 'post_content' => $p->description ?? '' ] );
                        if ( $pid ) { update_post_meta( $pid, '_stripe_product_id', $p->id ); update_post_meta( $pid, '_stripe_price_id', $price_id ); $created++; }
                    }
                    // Human-readable price (e.g. "25.00") stored alongside the ID.
                    if ( $pid && $price_id ) {
                        $pr = stripe_shop_lite_price_info( $price_id );
                        if ( $pr ) {
                            update_post_meta( $pid, '_display_price', number_format( $pr['unit'] / 100, 2, '.', '' ) );
                            update_post_meta( $pid, '_display_currency', strtoupper( $pr['currency'] ) );
                            if ( $pr['recurring'] ) update_post_meta( $pid, '_display_recurring', '1' );
                        }
                    }
                    // Kind: only from Stripe metadata, never guessed. Billing type is
                    // the price's job (recurring vs one-off), not a classification.
                    if ( $pid && in_array( $cls_norm, [ 'physical', 'digital', 'donation' ], true ) ) {
                        update_post_meta( $pid, '_tier_classification', $cls_norm );
                    } elseif ( $pid && ! $cls_norm ) {
                        // Products that already exist are flagged as well, so a missing
                        // kind stays visible on re-import.
                        $flags[] = 'flagged: no kind set. Add shoplite_classification metadata in Stripe (physical | digital | donation)';
                    }
                    // Gift flag from Stripe metadata, one-off prices only. Guarded on
                    // $price_id: retrieving a price with an empty ID would throw.
                    if ( $pid && $price_id && ! empty( $pm_all['shoplite_gift'] ) ) {
                        $pr = stripe_shop_lite_price_info( $price_id );
                        update_post_meta( $pid, '_stripe_shop_lite_gift', ( $pr && $pr['recurring'] ) ? '' : '1' );
                    }
                    // Persist imported Stripe metadata using the keys consumed by the
                    // product-detail/frontend variant system. Empty values are cleared
                    // on re-import so removed Stripe metadata cannot leave stale variants.
                    if ( $pid ) {
                        if ( $attribute_map ) update_post_meta( $pid, '_stripe_shop_lite_attributes', $attribute_map );
                        else delete_post_meta( $pid, '_stripe_shop_lite_attributes' );

                        if ( $variants ) update_post_meta( $pid, '_stripe_shop_lite_variants', $variants );
                        else delete_post_meta( $pid, '_stripe_shop_lite_variants' );

                        // Attribute-value images (see attribute_images()).
                        if ( $images_map ) update_post_meta( $pid, '_stripe_shop_lite_attribute_images', $images_map );
                        else delete_post_meta( $pid, '_stripe_shop_lite_attribute_images' );

                        // Read-only WordPress snapshot of the Stripe metadata used by the shop.
                        // Stripe remains the source of truth; this is only for visibility/debugging.
                        update_post_meta( $pid, '_stripe_shop_lite_stripe_product_metadata', self::metadata_array( $p->metadata ?? null ) );

                        $price_metadata = [];
                        foreach ( $price_list as $variant_price ) {
                            $price_metadata[ (string) $variant_price->id ] = self::metadata_array( $variant_price->metadata ?? null );
                        }
                        update_post_meta( $pid, '_stripe_shop_lite_stripe_price_metadata', $price_metadata );
                    }
                }

                if ( $flags ) $flagged++; // counted once per product, not once per note
                $results[] = [
                    'name' => $p->name,
                    'action' => $existing ? 'update' : 'create',
                    'flag' => $flags ? implode( '; ', $flags ) : '',
                    'done' => $pid,
                    'price' => $price_id,
                    'cls' => $cls_norm ?: '(unset)',
                    'variants' => count( $variants ),
                ];
            }
        } catch ( \Throwable $e ) {
            echo '<div class="wrap"><h1>Import from Stripe</h1><div class="notice notice-error"><p>' . esc_html( $e->getMessage() ) . '</p></div></div>';
            return;
        }
        ?>
        <div class="wrap"><h1>Import from Stripe</h1>
        <?php if ( $run ) : ?>
            <div class="notice notice-success is-dismissible"><p><strong>Import complete.</strong>
            <?php echo esc_html( sprintf( '%d created, %d updated, %d flagged.', $created, $updated, $flagged ) ); ?>
            Imported products arrive as drafts. Review and publish them in <a href="<?php echo esc_url( admin_url( 'edit.php?post_type=product' ) ); ?>">Products</a>.</p></div>
        <?php endif; ?>
        <p>Products match on the Stripe product ID, so the import is safe to run repeatedly; a second run updates and never duplicates. Your slug, page content, stock, categories and images are never changed.</p>
        <p>Variants come from Stripe metadata: set <code>shoplite_attributes = "{Color, Size}"</code> on the product and list each attribute's values on the product. On each price, list only the constraining attributes - <code>color = "green"</code> means green in every size, <code>size = "XXL"</code> means every color in XXL, and a price with no attribute metadata is a catch-all. The full combination table is expanded here, so you never need one price per combination. A <code>shoplite_priority</code> value (integer, optional) on a price wins ties. Classification (<code>shoplite_classification</code>) and attribute value lists live on the PRODUCT, not the price.</p>
        <table class="widefat"><thead><tr><th>Product</th><th>Action</th><th>Price ID</th><th>Kind</th><th>Variants</th><th>Notes</th></tr></thead><tbody>
        <?php foreach ( $results as $r ) : ?>
            <tr<?php echo $r['done'] ? ' style="font-weight:600"' : ''; ?>>
                <td><?php echo $r['done'] ? '<a href="' . esc_url( get_edit_post_link( $r['done'] ) ) . '">' . esc_html( $r['name'] ) . '</a>' : esc_html( $r['name'] ); ?></td>
                <td><?php echo esc_html( $r['done'] ? ( 'create' === $r['action'] ? 'created ' : 'updated ' ) : $r['action'] ); ?></td>
                <td><code><?php echo esc_html( $r['price'] ?: '-' ); ?></code></td>
                <td><?php echo esc_html( $r['cls'] ?: '(unset)' ); ?></td>
                <td><?php echo esc_html( (string) $r['variants'] ); ?></td>
                <td><?php echo esc_html( $r['flag'] ); ?></td></tr>
        <?php endforeach; ?>
        </tbody></table>
        <form method="post"><?php wp_nonce_field( 'stripe_shop_lite_import', 'stripe_shop_lite_import' ); ?>
            <p><button class="button" name="stripe_shop_lite_import_preview" value="1">Refresh preview</button>
               <button class="button button-primary" name="stripe_shop_lite_import_run" value="1">Run import</button></p>
        </form></div>
        <?php
    }
}

function stripe_shop_lite_import_page() {
    Stripe_Shop_Lite_Importer::page();
}
