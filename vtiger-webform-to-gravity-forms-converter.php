<?php
/**
 * Vtiger Webform to Gravity Forms Converter
 *
 * @package vwtgf_converter
 * @author  VCAT Consulting GmbH - Team WordPress
 * @license GPLv3
 *
 * @wordpress-plugin
 * Plugin Name: Vtiger Webform to Gravity Forms Converter
 * Plugin URI: https://github.com/VCATconsulting/vtiger-webform-to-gravity-forms-converter
 * Description: Converts Vtiger Webforms to Gravity Forms
 * Version: 1.2.0
 * Author: VCAT Consulting GmbH - Team WordPress
 * Author URI: https://www.vcat.de
 * Text Domain: vtiger-webform-to-gravity-forms-converter
 * License: GPLv3
 * License URI: http://www.gnu.org/licenses/gpl-3.0.txt
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'VWTGF_CONVERTER_VERSION' ) ) {
	define( 'VWTGF_CONVERTER_VERSION', '1.2.0' );
}
if ( ! defined( 'VWTGF_CONVERTER_FILE' ) ) {
	define( 'VWTGF_CONVERTER_FILE', __FILE__ );
}
if ( ! defined( 'VWTGF_CONVERTER_PATH' ) ) {
	define( 'VWTGF_CONVERTER_PATH', plugin_dir_path( VWTGF_CONVERTER_FILE ) );
}
if ( ! defined( 'VWTGF_CONVERTER_URL' ) ) {
	define( 'VWTGF_CONVERTER_URL', plugin_dir_url( VWTGF_CONVERTER_FILE ) );
}

/*
 * The pre_init functions check the compatibility of the plugin and calls the init function, if check were successful.
 */
add_action( 'plugins_loaded', 'vwtgf_converter_load_textdomain' );
vwtgf_converter_pre_init();

/**
 * Pre-init function to check compatibility and bootstrap plugin modules.
 *
 * @return string
 */
function vwtgf_converter_pre_init() {
	$state = vwtgf_converter_ensure_ready( true );

	if ( 'ok' !== $state ) {
		if ( 'php_too_old' === $state ) {
			add_action( 'admin_notices', 'vwtgf_converter_min_php_version_error' );
		} elseif ( 'autoloader_missing' === $state ) {
			add_action( 'admin_notices', 'vwtgf_converter_autoloader_missing' );
		}

		return $state;
	}

	return 'ok';
}

/**
 * Ensure requirements are met and plugin files are loaded once.
 *
 * @param bool $load Whether to load plugin files after successful checks.
 *
 * @return string
 */
function vwtgf_converter_ensure_ready( $load = true ) {
	static $bootstrapped = false;

	if ( version_compare( PHP_VERSION, '7.4', '<' ) ) {
		return 'php_too_old';
	}

	$composer_file = VWTGF_CONVERTER_PATH . 'composer.json';
	$autoloader    = VWTGF_CONVERTER_PATH . 'vendor/autoload.php';

	if ( file_exists( $composer_file ) && ! is_readable( $autoloader ) ) {
		return 'autoloader_missing';
	}

	if ( ! $load || $bootstrapped ) {
		return 'ok';
	}

	if ( is_readable( $autoloader ) ) {
		require_once $autoloader;
	}

	require_once VWTGF_CONVERTER_PATH . 'lib/load.php';

	$bootstrapped = true;

	return 'ok';
}

/**
 * Return a translated error message for a requirement state.
 *
 * @param string $state Requirement state.
 *
 * @return string
 */
function vwtgf_converter_get_error_message( $state ) {
	if ( 'php_too_old' === $state ) {
		return __( 'Vtiger Webform to Gravity Forms Converter requires PHP version 7.4 or higher to function properly. Please upgrade PHP or deactivate Vtiger Webform to Gravity Forms Converter.', 'vtiger-webform-to-gravity-forms-converter' );
	}

	if ( 'autoloader_missing' === $state ) {
		return __( 'Vtiger Webform to Gravity Forms Converter is missing the Composer autoloader file. Please run "composer install --no-dev -o" in the root folder of the plugin or use a release version including the "vendor" folder.', 'vtiger-webform-to-gravity-forms-converter' );
	}

	return __( 'Vtiger Webform to Gravity Forms Converter cannot be initialized due to an unknown requirement error.', 'vtiger-webform-to-gravity-forms-converter' );
}

/**
 * Load plugin textdomain.
 *
 * @since 1.0.0
 */
function vwtgf_converter_load_textdomain() {
	load_plugin_textdomain( 'vtiger-webform-to-gravity-forms-converter', false, basename( __DIR__ ) . '/languages' );
}

/**
 * Show an admin notice error message, if the PHP version is too low.
 */
function vwtgf_converter_min_php_version_error() {
	if ( ! is_admin() || ! current_user_can( 'activate_plugins' ) ) {
		return;
	}

	echo '<div class="notice notice-error"><p>';
	echo esc_html( vwtgf_converter_get_error_message( 'php_too_old' ) );
	echo '</p></div>';
}

/**
 * Show an admin notice error message, if the Composer autoloader is missing.
 */
function vwtgf_converter_autoloader_missing() {
	if ( ! is_admin() || ! current_user_can( 'activate_plugins' ) ) {
		return;
	}

	echo '<div class="notice notice-error"><p>';
	echo esc_html( vwtgf_converter_get_error_message( 'autoloader_missing' ) );
	echo '</p></div>';
}
