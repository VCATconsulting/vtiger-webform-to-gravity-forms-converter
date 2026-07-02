<?php
/**
 * Helpers for validating Vtiger webform URLs.
 *
 * @package VWTGF_CONVERTER\Helpers
 */

namespace VWTGF_CONVERTER\Helpers;

/**
 * Class VtigerUrl
 */
class VtigerUrl {
	/**
	 * Validate a Vtiger webform endpoint URL.
	 *
	 * @param string $url URL to validate.
	 *
	 * @return bool
	 */
	public static function is_allowed_vtiger_url( $url ) {
		$url = esc_url_raw( $url );

		if ( empty( $url ) ) {
			return false;
		}

		$scheme = wp_parse_url( $url, PHP_URL_SCHEME );
		$host   = wp_parse_url( $url, PHP_URL_HOST );
		$path   = wp_parse_url( $url, PHP_URL_PATH );

		if ( empty( $scheme ) || empty( $host ) ) {
			return false;
		}

		$scheme = strtolower( $scheme );
		$host   = strtolower( $host );

		/**
		 * Filter allowed Vtiger URL schemes.
		 *
		 * @param array $allowed_schemes Allowed schemes.
		 */
		$allowed_schemes = (array) apply_filters( 'vwtgf_converter_allowed_vtiger_schemes', [ 'https' ] );
		$allowed_schemes = array_map( 'strtolower', $allowed_schemes );

		if ( ! in_array( $scheme, $allowed_schemes, true ) ) {
			return false;
		}

		if ( '/modules/Webforms/capture.php' !== $path ) {
			return false;
		}

		/**
		 * Filter allowed Vtiger hosts.
		 *
		 * Empty array means every host is allowed.
		 *
		 * @param array $allowed_hosts Allowed host names.
		 */
		$allowed_hosts = (array) apply_filters( 'vwtgf_converter_allowed_vtiger_hosts', [] );

		if ( empty( $allowed_hosts ) ) {
			return true;
		}

		$allowed_hosts = array_map( 'strtolower', $allowed_hosts );

		return in_array( $host, $allowed_hosts, true );
	}
}
