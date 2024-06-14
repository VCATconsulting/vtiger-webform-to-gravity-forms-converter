<?php
/**
 * Main plugin file to load other classes
 *
 * @package VWTGFC
 */

namespace VWTGFC;

use VWTGFC\Actions\ConvertWebform;
use VWTGFC\Actions\PostToVtiger;
use VWTGFC\Helpers\SettingsPage;

/**
 * Init function of the plugin
 */
function init() {
	/*
	 * Only initialize the plugin when GravityForms is active.
	 */
	if ( ! class_exists( 'GFCommon' ) ) {
		return;
	}

	/*
	 * Construct all modules to initialize.
	 */
	$modules = [
		'vwtgf_converter_settings_page'   => new SettingsPage(),
		'vwtgf_converter_convert_webform' => new ConvertWebform(),
		'vwtgf_converter_post_to_vtiger'  => new PostToVtiger(),
	];

	/*
	 * Initialize all modules.
	 */
	foreach ( $modules as $module ) {
		if ( is_callable( [ $module, 'init' ] ) ) {
			call_user_func( [ $module, 'init' ] );
		}
	}
}

add_action( 'plugins_loaded', 'VWTGFC\init' );
