<?php
$wp_load = locate_wp_load_for_discount_script(__DIR__);

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

$rules = (array) readSettings($action = "remove"); 

remove_product_discount($rules);

function locate_wp_load_for_discount_script(string $start_dir): string
{
    $dir = $start_dir;

    while ($dir && $dir !== dirname($dir)) {
        $candidate = $dir . "/wp-load.php";

        if (file_exists($candidate)) {
            return $candidate;
        }

        $dir = dirname($dir);
    }

    return "";
}
