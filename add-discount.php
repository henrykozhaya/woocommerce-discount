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

$rules = (array) readSettings($action = "add"); 

apply_sale_price_discount($rules);