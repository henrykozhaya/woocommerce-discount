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
            $excluded_onsale_ids = [];

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

            if (!empty($excluded_onsale_ids)) {
                $args['exclude'] = isset($args['exclude'])
                    ? array_merge($args['exclude'], $excluded_onsale_ids)
                    : $excluded_onsale_ids;
            }
        } else if ($action == "remove") {
            // 1. Get all sale IDs
            $all_sale_ids = wc_get_product_ids_on_sale();

            // 2. The shared exclusions below will remove custom excluded IDs and taxonomies.
            $args['include'] = $all_sale_ids;

            if (count($args["include"]) <= 0) {
                echo "No products on sale to process\n";
                $args['include'] = [0];
            }
        }
    } 
    else {
        if (!discount_rule_group_has_selectors($rules["include"])) {
            $args['include'] = [0];
        }

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

        if (!empty($rules["include"]["product_ids"])) {
            $args['include'] = $rules["include"]["product_ids"];
        }

        add_discount_tax_query($args, 'product_cat', $rules["include"]["categories"], 'IN');
        add_discount_tax_query($args, 'product_tag', $rules["include"]["tags"], 'IN');
        add_discount_tax_query($args, 'product_brand', $rules["include"]["brands"], 'IN');
        add_discount_attribute_tax_queries($args, $rules["include"], 'IN');
    }

    add_discount_product_id_exclusions($args, $rules["exclude"]["product_ids"]);
    add_discount_tax_query($args, 'product_cat', $rules["exclude"]["categories"], 'NOT IN');
    add_discount_tax_query($args, 'product_tag', $rules["exclude"]["tags"], 'NOT IN');
    add_discount_tax_query($args, 'product_brand', $rules["exclude"]["brands"], 'NOT IN');
    add_discount_attribute_tax_queries($args, $rules["exclude"], 'NOT IN');

    return $args;
}

function add_discount_product_id_exclusions(array &$args, array $product_ids): void
{
    if (empty($product_ids)) {
        return;
    }

    if (!empty($args['include'])) {
        $args['include'] = array_values(array_diff($args['include'], $product_ids));

        if (empty($args['include'])) {
            $args['include'] = [0];
        }

        return;
    }

    $args['exclude'] = isset($args['exclude'])
        ? array_values(array_unique(array_merge($args['exclude'], $product_ids)))
        : $product_ids;
}

function discount_rule_group_has_selectors(array $rule_group): bool
{
    foreach (['product_ids', 'categories', 'tags', 'brands'] as $key) {
        if (!empty($rule_group[$key])) {
            return true;
        }
    }

    if (empty($rule_group['attributes']) || !is_array($rule_group['attributes'])) {
        return false;
    }

    foreach ($rule_group['attributes'] as $terms) {
        if (!empty($terms)) {
            return true;
        }
    }

    return false;
}

function add_discount_tax_query(array &$args, string $taxonomy, array $terms, string $operator): void
{
    if (empty($terms)) {
        return;
    }

    $args['tax_query'][] = [
        'taxonomy' => $taxonomy,
        'field'    => 'slug',
        'terms'    => $terms,
        'operator' => $operator,
    ];
}

function add_discount_attribute_tax_queries(array &$args, array $rule_group, string $operator): void
{
    if (empty($rule_group['attributes']) || !is_array($rule_group['attributes'])) {
        return;
    }

    foreach ($rule_group['attributes'] as $attribute => $terms) {
        if (empty($terms) || !is_array($terms)) {
            continue;
        }

        $taxonomy = normalize_discount_attribute_taxonomy((string) $attribute);

        if (!$taxonomy || !taxonomy_exists($taxonomy)) {
            continue;
        }

        add_discount_tax_query($args, $taxonomy, $terms, $operator);
    }
}

function normalize_discount_attribute_taxonomy(string $attribute): string
{
    $attribute = sanitize_title($attribute);

    if ($attribute === '') {
        return '';
    }

    return strpos($attribute, 'pa_') === 0 ? $attribute : 'pa_' . $attribute;
}

function apply_sale_price_discount(array $rules)
{   
    if (! class_exists('WooCommerce')) {
        return;
    }

    if (!validate_add_discount_rules($rules, "add")) {
        echo "Error with discount rules\n";
        return;
    }

    $args = build_wc_product_query_args($rules, 200, 1);

    $processed_products = 0;
    $processed_variations = 0;

    echo "🔍 Processing products in batches of {$args['limit']}...\n";
    echo "ID,RegularPrice,SalePrice,Name,Brand\n";

    while (true) {

        $product_ids = wc_get_products($args);

        if (empty($product_ids)) {
            break;
        }

        // echo "📦 Batch page {$args['page']} - loaded " . count($product_ids) . " product IDs\n";

        foreach ($product_ids as $product_id) {

            $product = wc_get_product($product_id);

            if (! $product) {
                continue;
            }

            if ($product->is_type('simple')) {

                if (apply_discount_to_product($product, $rules)) {
                    $processed_products++;
                }
            } elseif ($product->is_type('variable')) {

                // Apply discount to variations only
                foreach ($product->get_children() as $variation_id) {

                    $variation = wc_get_product($variation_id);

                    if ($variation) {
                        if (apply_discount_to_product($variation, $rules)) {
                            $processed_variations++;
                        }

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

function apply_discount_to_product(WC_Product $product, array $rules): bool
{
    $dryRun = isset($rules["dry_run"]) && $rules["dry_run"] === true;

    if (!empty($rules["exclude_on_sale"]) && discount_product_has_sale_price($product)) {
        return false;
    }

    $regular_price = (float) $product->get_regular_price();

    if ($regular_price <= 0) {
        return false;
    }

    if ($rules['discount_type'] === 'percentage') {
        $sale_price = $regular_price - ($regular_price * ($rules['discount_value'] / 100));
    } else {
        $sale_price = $regular_price - $rules['discount_value'];
    }

    if ($sale_price < 0) {
        $sale_price = 0;
    }

    if(!$dryRun){
        $product->set_sale_price(wc_format_decimal($sale_price));
        $product->set_price(wc_format_decimal($sale_price));
        $product->save();
    }
    
    // Clear cache/transients to keep memory usage low during large runs
    wc_delete_product_transients($product->get_id());

    if (! empty($rules['add_tag']) && !$dryRun) {
        apply_tag_to_discounted_product($product, $rules['add_tag']);
    }

    $brand = '';
    $brand_product_id = $product->is_type('variation') ? $product->get_parent_id() : $product->get_id();
    $terms = get_the_terms($brand_product_id, 'product_brand');
	
    if (!empty($terms) && !is_wp_error($terms)) {
	    $brand = implode(', ', wp_list_pluck($terms, 'name'));
	}     
	echo "{$product->get_id()},{$regular_price},{$sale_price},{$product->get_name()},{$brand}\n";

    return true;
}

function discount_product_has_sale_price(WC_Product $product): bool
{
    return (string) $product->get_sale_price() !== '' || $product->is_on_sale();
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
        echo "Error with discount rules\n";
        return;
    }

    $args = build_wc_product_query_args($rules, 200, 1, "remove");

    $processed_products = 0;
    $processed_variations = 0;

    echo "🔍 Processing products in batches of {$args['limit']}...\n";
    echo "ID,RegularPrice,Name,Brand\n";

    while (true) {
        $product_ids = wc_get_products($args);

        if (empty($product_ids)) {
            break;
        }

        // echo "📦 Batch page {$args['page']} - loaded " . count($product_ids) . " product IDs\n";

        foreach ($product_ids as $product_id) {

            $product = wc_get_product($product_id);

            if (! $product) {
                continue;
            }

            if ($product->is_type('simple')) {

                restore_product_price($product, $rules);
                $processed_products++;
            } elseif ($product->is_type('variable')) {

                foreach ($product->get_children() as $variation_id) {
                    $variation = wc_get_product($variation_id);

                    if ($variation) {
                        restore_product_price($variation, $rules);
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
function restore_product_price(WC_Product $product, array $rules = [])
{
    $dryRun = isset($rules["dry_run"]) && $rules["dry_run"] === true;

    $regular_price = $product->get_regular_price();

    if ($regular_price === '') {
        return;
    }

    if(!$dryRun){

        // Remove sale price
        $product->set_sale_price('');

        // Restore price from regular price
        $product->set_price($regular_price);

        $product->save();

    }

    $brand = '';
    $brand_product_id = $product->is_type('variation') ? $product->get_parent_id() : $product->get_id();
    $terms = get_the_terms($brand_product_id, 'product_brand');
	
    if (!empty($terms) && !is_wp_error($terms)) {
	    $brand = implode(', ', wp_list_pluck($terms, 'name'));
	}     
	echo "{$product->get_id()},{$regular_price},{$product->get_name()},{$brand}\n";
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
        !isset($rules["exclude"]["attributes"])
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
        ||
        !isset($rules["include"]["attributes"])
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
    $settings = [];
    $filePath = __DIR__ . "/" . $action . "-discount.json";
    $file = fopen($filePath, "r");
    $fileJSON = fread($file, filesize($filePath));
    $settings = json_decode($fileJSON, true);
    fclose($file);
    return (array) $settings;
}
