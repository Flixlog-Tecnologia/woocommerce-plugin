<?php
/**
 * Plugin Name: Flixlog Shipping Method for Woocommerce
 * Plugin URI: https://github.com/Flixlog-Tecnologia/woocommerce-plugin
 * Description: A forma mais eficiente de enviar pedidos.
 * Version: 1.0.0
 * Developer: Yuri Vecchi
 * Developer URI: http://yurivecchi.com.br/
 * Text Domain: flixlog-woocommerce-plugin
 * Domain Path: /languages
 * Requires at least: 5.5.0
 * Requires PHP: 7.1
 *
 * WC requires at least: 4.5.0
 * WC tested up to: 4.5.2
 *
 * License: GNU General Public License v3.0
 * License URI: http://www.gnu.org/licenses/gpl-3.0.html
 *
 */

defined( 'ABSPATH' ) || exit;
if ( in_array( 'woocommerce/woocommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ), true ) ) {

	function flixlog_shipping_method_init() {
		require_once "src/WC_Flixlog_Shipping_Method.php";
	}
	add_action( 'woocommerce_shipping_init', 'flixlog_shipping_method_init' );

	function add_flixlog_shipping_method( $methods ) {
		$methods['flixlog_shipping_method'] = 'WC_Flixlog_Shipping_Method';
		return $methods;
	}
	add_filter( 'woocommerce_shipping_methods', 'add_flixlog_shipping_method' );

}
