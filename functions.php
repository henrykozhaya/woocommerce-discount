<?php

function build_wc_product_query_args(array $rules, int $limit = 200, int $page = 1, $action = "add"): array
{

    $args = [
        'status' => 'publish',
        'limit'  => $limit,
        'page'   => $page,
        'type'   => ['simple', 'variable'],
        'return' => 'ids',
    ];

    if ($rules["apply_to_all"]) {

        if ($action == "add") {

            if (isset($rules["exclude_on_sale"]) && $rules["exclude_on_sale"]) {
                $args['meta_query'][] = array(
                    'relation' => 'OR',
                    array(
                        'key'     => '_sale_price',
                        'value'   => '',
                        'compare' => '='
                    ),
                    array(
                        'key'     => '_sale_price',
                        'compare' => 'NOT EXISTS'
                    ),
                );
            }

            if (count($rules["exclude"]["product_ids"]) > 0) {
                $args['exclude'] = $rules["exclude"]["product_ids"];
            }

            if (count($excluded_custom_products_ids) > 0 || count($excluded_onsale_ids) > 0) {
                $args['exclude'] = array_merge($excluded_onsale_ids, $excluded_custom_products_ids);
            }
        } else if ($action == "remove") {
            $excluded_custom_products_ids = [];

            // 1. Get all sale IDs
            $all_sale_ids = wc_get_product_ids_on_sale();

            // 2. Custom IDs to exclude
            if (count($rules["exclude"]["product_ids"]) > 0) {
                $excluded_custom_products_ids = $rules["exclude"]["product_ids"];
            }

            // 3. Filter the array: returns everything in $all_sale_ids NOT in $excluded_custom_products_ids
            $args['include'] = array_diff($all_sale_ids, $excluded_custom_products_ids);

            if (count($args["include"]) <= 0) die("No products on sale to process");
        }

        if (count($rules["exclude"]["categories"]) > 0) {
            $args['tax_query'][] = [
                'taxonomy' => 'product_cat',
                'field'    => 'slug',
                'terms'    => $rules["exclude"]["categories"],
                'operator' => 'NOT IN',
            ];
        }

        if (count($rules["exclude"]["tags"]) > 0) {
            $args['tax_query'][] = [
                'taxonomy' => 'product_tag',
                'field'    => 'slug',
                'terms'    => $rules["exclude"]["tags"],
                'operator' => 'NOT IN',
            ];
        }

        if (count($rules["exclude"]["brands"]) > 0) {
            $args['tax_query'][] = [
                'taxonomy' => 'product_brand',
                'field'    => 'slug',
                'terms'    => $rules["exclude"]["brands"],
                'operator' => 'NOT IN',
            ];
        }
    } else {

        if (isset($rules["exclude_on_sale"]) && $rules["exclude_on_sale"]) {
            $args['meta_query'][] = array(
                'relation' => 'OR',
                array(
                    'key'     => '_sale_price',
                    'value'   => '',
                    'compare' => '='
                ),
                array(
                    'key'     => '_sale_price',
                    'compare' => 'NOT EXISTS'
                ),
            );
        }

        if (count($rules["include"]["product_ids"]) > 0) {
            $args['include'] = $rules["include"]["product_ids"];
        }

        if (count($rules["include"]["categories"]) > 0) {
            $args['tax_query'][] = [
                'taxonomy' => 'product_cat',
                'field'    => 'slug',
                'terms'    => $rules["include"]["tags"],
                'operator' => 'IN',
            ];
        }

        if (count($rules["include"]["tags"]) > 0) {
            $args['tax_query'][] = [
                'taxonomy' => 'product_tag',
                'field'    => 'slug',
                'terms'    => $rules["include"]["tags"],
                'operator' => 'IN',
            ];
        }

        if (count($rules["include"]["brands"]) > 0) {
            $args['tax_query'][] = [
                'taxonomy' => 'product_brand',
                'field'    => 'slug',
                'terms'    => $rules["include"]["brands"],
                'operator' => 'IN',
            ];
        }
    }
    return $args;
}

function apply_sale_price_discount(array $rules)
{   
    if (! class_exists('WooCommerce')) {
        return;
    }

    if (!validate_add_discount_rules($rules, "add")) {
        die("❌ Error with discount rules");
        return;
    }

    $args = build_wc_product_query_args($rules, 200, 1);

    $processed_products = 0;
    $processed_variations = 0;

    echo "🔍 Processing products in batches of {$args['limit']}...\n";

    while (true) {

        $product_ids = wc_get_products($args);

        if (empty($product_ids)) {
            break;
        }

        echo "📦 Batch page {$args['page']} - loaded " . count($product_ids) . " product IDs\n";
        foreach ($product_ids as $product_id) {

            $product = wc_get_product($product_id);

            if (! $product) {
                continue;
            }

            if ($product->is_type('simple')) {

                apply_discount_to_product($product, $rules);
                $processed_products++;
            } elseif ($product->is_type('variable')) {

                // Apply discount to variations only
                foreach ($product->get_children() as $variation_id) {

                    $variation = wc_get_product($variation_id);

                    if ($variation) {
                        apply_discount_to_product($variation, $rules);
                        $processed_variations++;

                        // Free memory aggressively
                        unset($variation);
                    }
                }

                // Free memory aggressively
                unset($product);
            }

            // Clear product cache/transients and free memory
            wc_delete_product_transients($product_id);
            unset($product);
        }

        // Move to next page
        $args['page'] = (int) $args['page'] + 1;

        // Help PHP free memory between batches
        gc_collect_cycles();
    }

    echo "Discount process completed.\n";
    echo "   - Processed products: {$processed_products}\n";
    echo "   - Processed variations: {$processed_variations}\n";
}

function apply_discount_to_product(WC_Product $product, array $rules)
{

    $testing = isset($rules["test"]) && $rules["test"] === true;

    $regular_price = (float) $product->get_regular_price();

    if ($regular_price <= 0) {
        return;
    }

    if ($rules['discount_type'] === 'percentage') {
        $sale_price = $regular_price - ($regular_price * ($rules['discount_value'] / 100));
    } else {
        $sale_price = $regular_price - $rules['discount_value'];
    }

    if ($sale_price < 0) {
        $sale_price = 0;
    }

    if(!$testing){
        $product->set_sale_price(wc_format_decimal($sale_price));
        $product->set_price(wc_format_decimal($sale_price));
        $product->save();
    }
    
    // Clear cache/transients to keep memory usage low during large runs
    wc_delete_product_transients($product->get_id());

    if (! empty($rules['add_tag']) && !$testing) {
        apply_tag_to_discounted_product($product, $rules['add_tag']);
    }

    echo "✔ Discount applied to {$product->get_id()} \t {$regular_price} \t {$sale_price} \t {$product->get_name()} \n";
}

function apply_tag_to_discounted_product(WC_Product $product, string $tag_slug)
{

    $product_id = $product->is_type('variation')
        ? $product->get_parent_id()
        : $product->get_id();

    if (! $product_id) {
        return;
    }

    if (has_term($tag_slug, 'product_tag', $product_id)) {
        return;
    }

    wp_set_object_terms($product_id, $tag_slug, 'product_tag', true);
}

function remove_product_discount(array $rules)
{
    if (! class_exists('WooCommerce')) {
        return;
    }

    if (!validate_add_discount_rules($rules, "remove")) {
        die("❌ Error with discount rules");
        return;
    }

    $args = build_wc_product_query_args($rules, 200, 1, "remove");

    $processed_products = 0;
    $processed_variations = 0;

    echo "🔍 Processing products in batches of {$args['limit']}...\n";

    while (true) {
        $product_ids = wc_get_products($args);

        if (empty($product_ids)) {
            break;
        }

        echo "📦 Batch page {$args['page']} - loaded " . count($product_ids) . " product IDs\n";

        foreach ($product_ids as $product_id) {

            $product = wc_get_product($product_id);

            if (! $product) {
                continue;
            }

            if ($product->is_type('simple')) {

                restore_product_price($product);
                $processed_products++;
            } elseif ($product->is_type('variable')) {

                foreach ($product->get_children() as $variation_id) {
                    $variation = wc_get_product($variation_id);

                    if ($variation) {
                        restore_product_price($variation);
                        $processed_variations++;

                        // Free memory aggressively
                        unset($variation);
                    }
                }

                // Free memory aggressively
                unset($product);
            }

            // Clear product cache/transients and free memory
            wc_delete_product_transients($product_id);
            unset($product);
        }
        // Move to next page
        $args['page'] = (int) $args['page'] + 1;

        // Help PHP free memory between batches
        gc_collect_cycles();
    }

    echo "Discount removal process completed.\n";
    echo "   - Processed products: {$processed_products}\n";
    echo "   - Processed variations: {$processed_variations}\n";
}
function restore_product_price(WC_Product $product)
{
    $testing = isset($rules["test"]) && $rules["test"] === true;

    $regular_price = $product->get_regular_price();

    if ($regular_price === '') {
        return;
    }

    if(!$testing){

        // Remove sale price
        $product->set_sale_price('');

        // Restore price from regular price
        $product->set_price($regular_price);

        $product->save();

    }

    echo "✔ Discount removed from {$product->get_id()} \t {$regular_price} \t {$product->get_name()} \n";
}

function validate_add_discount_rules($rules, $action = "add")
{
    if ($action == "add") {
        if (!isset($rules["discount_type"]) || !in_array($rules["discount_type"], ["percentage", "fixed"])) return false;
        if (!isset($rules["discount_value"]) || !is_numeric($rules["discount_value"]) || $rules["discount_value"] <= 0) return false;
        if (!isset($rules["exclude_on_sale"]) || !is_bool($rules["exclude_on_sale"])) return false;
        if (!isset($rules["add_tag"]) || !is_string($rules["add_tag"])) return false;
    }
    if (!isset($rules["apply_to_all"]) || !is_bool($rules["apply_to_all"])) return false;
    if (
        !isset($rules["exclude"])
        ||
        !isset($rules["exclude"]["product_ids"])
        ||
        !isset($rules["exclude"]["categories"])
        ||
        !isset($rules["exclude"]["tags"])
        ||
        !isset($rules["exclude"]["brands"])
        ||
        !isset($rules["include"])
        ||
        !isset($rules["include"]["product_ids"])
        ||
        !isset($rules["include"]["categories"])
        ||
        !isset($rules["include"]["tags"])
        ||
        !isset($rules["include"]["brands"])
    ) return false;
    foreach ($rules["exclude"] as $exclude) {
        if (!is_array($exclude)) return false;
    }
    foreach ($rules["include"] as $include) {
        if (!is_array($include)) return false;
    }
    if (count($rules["exclude"]["product_ids"]) > 0) {
        foreach ($rules["exclude"]["product_ids"] as $id) {
            if (!is_numeric($id)) return false;
        }
    }
    if (count($rules["include"]["product_ids"]) > 0) {
        foreach ($rules["include"]["product_ids"] as $id) {
            if (!is_numeric($id)) return false;
        }
    }
    return true;
}

function readSettings($action = "add"){
    $filePath = $action . "-discount.json";
    $file = fopen($filePath, "r");
    $fileJSON = fread($file, filesize($filePath));
    $fileArr = json_decode($fileJSON, true);
    fclose($file);
    return $fileArr;
}