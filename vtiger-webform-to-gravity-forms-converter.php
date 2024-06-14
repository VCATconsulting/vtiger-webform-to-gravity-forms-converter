<?php
/**
 * Vtiger Webform to Gravity Forms Converter
 *
 * @package vwtgfc
 * @author  VCAT Consulting GmbH - Team WordPress
 * @license GPLv3
 *
 * @wordpress-plugin
 * Plugin Name: Vtiger Webform to Gravity Forms Converter
 * Plugin URI: https://github.com/VCATconsulting/vtiger-webform-to-gravity-forms-converter
 * Description: Converts Vtiger Webforms to Gravity Forms
 * Version: 1.0.1
 * Author: VCAT Consulting GmbH - Team WordPress
 * Author URI: https://www.vcat.de
 * Text Domain: vtiger-webform-to-gravity-forms-converter
 * License: GPLv3
 * License URI: http://www.gnu.org/licenses/gpl-3.0.txt
 */

define( 'VWTGFC_VERSION', '1.0.1' );
define( 'VWTGFC_FILE', __FILE__ );
define( 'VWTGFC_PATH', plugin_dir_path( VWTGFC_FILE ) );
define( 'VWTGFC_URL', plugin_dir_url( VWTGFC_FILE ) );

// The pre_init functions check the compatibility of the plugin and calls the init function, if check were successful.
vwtgf_converter_pre_init();

/**
 * Pre init function to check the plugins compatibility.
 */
function vwtgf_converter_pre_init() {
	// Load the translation, as they might be needed in pre_init.
	add_action( 'plugins_loaded', 'vwtgf_converter_load_textdomain' );

	/*
	 * Check, if the min. required PHP version is available and if not, show an admin notice.
	 */
	if ( version_compare( PHP_VERSION, '7.2', '<' ) ) {
		add_action( 'admin_notices', 'vwtgf_converter_min_php_version_error' );

		/*
		 * Stop the further processing of the plugin.
		 */
		return;
	}

	if ( file_exists( VWTGFC_PATH . 'composer.json' ) && ! file_exists( VWTGFC_PATH . 'vendor/autoload.php' ) ) {
		add_action( 'admin_notices', 'vwtgf_converter_autoloader_missing' );

		/*
		* Stop the further processing of the plugin.
		*/
		return;
	} else {
		$autoloader = VWTGFC_PATH . 'vendor/autoload.php';

		if ( is_readable( $autoloader ) ) {
			include $autoloader;
		}
	}

	/*
	* If all checks were succcessful, load the plugin.
	*/
	require_once VWTGFC_PATH . 'lib/load.php';
}

/**
 * Load plugin textdomain.
 *
 * @since 1.0.0
 */
function vwtgf_converter_load_textdomain() {
	load_plugin_textdomain( 'vtiger-webform-to-gravity-forms-converter', false, basename( dirname( __FILE__ ) ) . '/languages' );
}

/**
 * Show a admin notice error message, if the PHP version is too low
 */
function vwtgf_converter_min_php_version_error() {
	echo '<div class="error"><p>';
	esc_html_e( 'Euler WP requires PHP version 7.4 or higher to function properly. Please upgrade PHP or deactivate Euler WP.', 'vtiger-webform-to-gravity-forms-converter' );
	echo '</p></div>';
}

/**
 * Show a admin notice error message, if the PHP version is too low
 */
function vwtgf_converter_autoloader_missing() {
	echo '<div class="error"><p>';
	esc_html_e( 'Euler WP is missing the Composer autoloader file. Please run `composer install --no-dev -o` in the root folder of the plugin or use a release version including the `vendor` folder.', 'vtiger-webform-to-gravity-forms-converter' );
	echo '</p></div>';
}
