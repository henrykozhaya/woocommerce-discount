<?php

$wp_load = dirname(__FILE__, 2) . "/wp-load.php";

if ( ! file_exists( $wp_load ) ) {
    fwrite( STDERR, "❌ wp-load.php not found.\n" );
    exit(1);
}

require_once $wp_load;
require_once "functions.php";

if ( ! class_exists( "WooCommerce" ) ) {
    fwrite( STDERR, "❌ WooCommerce is not active.\n" );
    exit(1);
}

/**
 * Discount Rules Documentation
 *
 * @var array{
 *   discount_type: "percentage|fixed"
 *   discount_value: int
 *   apply_to_all: bool", // If true, the script will skip "apply_to" configuration. It will only consider the exclusion
 *   exclude: array{
 *     on_sale: bool // To exclude on sale produdcts
 *     product_ids: array<int>, // Product IDs
 *     categories:  array<string>, // Category slugs
 *     tags:        array<string>, // Tag slugs
 *     brands:      array<string>  // Brand slugs
 *   }
 *   apply_to: array{   // Apply to rparams will be skipped if apply to all is set to true
 *     product_ids: array<int>, // Product IDs
 *     categories:  array<string>, // Category slugs
 *     tags:        array<string>, // Tag slugs
 *     brands:      array<string>  // Brand slugs
 *   },
 *   add_tag: string   // Add a tag on applicable products
 * }
 * 
 */

$discount_rules = [
    "discount_type"   => "percentage",
    "discount_value"  => 10,
    "apply_to_all"    => false,
    "exclude_on_sale" => false,
    "exclude"         => [
        "product_ids" => [],
        "categories"  => [],
        "tags"        => [],
        "brands"      => [],
    ],
    "include"        => [
        "product_ids" => [],
        "categories"  => [],
        "tags"        => [],
        "brands"      => [],
    ],
    "add_tag"         => ""
];

apply_sale_price_discount( $discount_rules );