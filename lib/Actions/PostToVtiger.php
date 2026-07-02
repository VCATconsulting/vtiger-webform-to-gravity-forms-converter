<?php
/**
 * Class to post Gravity Forms form to Vtiger.
 *
 * @package VWTGF_CONVERTER\Actions
 */

namespace VWTGF_CONVERTER\Actions;

use GFFormsModel;
use VWTGF_CONVERTER\Helpers\VtigerUrl;

/**
 * Class PostToVtiger
 */
class PostToVtiger {

	/**
	 * Base path for API routes.
	 *
	 * @var string
	 */
	public static $vtiger_url;

	/**
	 * Init all filter and action hooks so that they can be used.
	 *
	 * @see https://wordpress.org/gutenberg/handbook/blocks/writing-your-first-block-type/#enqueuing-block-scripts
	 */
	public function init() {
		add_action( 'gform_after_submission', [ $this, 'vwtgf_converter_post_to_vtiger' ], 10, 2 );
		add_filter( 'http_request_host_is_external', [ $this, 'http_request_host_is_external' ], 10, 3 );
		add_filter( 'vwtgf_converter_request_args', [ $this, 'configure_ssl_verification' ], 0, 1 );
	}

	/**
	 * Convert the Gravity Forms Post to a Vtiger Post and post it.
	 *
	 * @param array  $entry the entry.
	 * @param object $form The form object.
	 */
	public function vwtgf_converter_post_to_vtiger( $entry, $form ) {
		/*
		 * Check if the entry is spam.
		 */
		if ( rgar( $entry, 'status' ) === 'spam' ) {
			return;
		}

		$is_vtiger_webform = false;

		/*
		 * Check if it is a Vtiger Form.
		 */
		foreach ( $form['fields'] as $field ) {
			if ( 'vtiger_POST_url' === $field['adminLabel'] ) {
				$vtiger_url = esc_url_raw( $field['defaultValue'] );

				if ( empty( $vtiger_url ) || ! VtigerUrl::is_allowed_vtiger_url( $vtiger_url ) ) {
					return;
				}

				$is_vtiger_webform = true;
				self::$vtiger_url  = $vtiger_url;
				break;
			}
		}

		if ( ! $is_vtiger_webform ) {
			return;
		}

		/*
		 *  Boundary-Token für multipart/form-data.
		 */
		$boundary = uniqid();

		/**
		 * Allow to modify the boundry.
		 *
		 * @param array $headers The headers to post to Vtiger.
		 * @param object $form The form object.
		 */
		$boundary = gf_apply_filters( [ 'vwtgf_converter_post_boundary', $form['id'] ], $boundary, $form );

		/*
		 * Build headers.
		 */
		$headers = [
			'Content-Type' => 'multipart/form-data; boundary=' . $boundary,
		];

		/**
		 * Allow to modify the headers before posting it to Vtiger.
		 *
		 * @param array $headers The headers to post to Vtiger.
		 * @param object $form The form object.
		 */
		$headers = gf_apply_filters( [ 'vwtgf_converter_post_headers', $form['id'] ], $headers, $form );

		/*
		 * Create payload and post to Vtiger Post.
		 */
		$payload = '';
		foreach ( $form['fields'] as $field ) {
			if ( 'vtiger_POST_url' === $field['adminLabel'] ) {
				continue;
			}

			$field_name = $this->sanitize_multipart_name( $field['adminLabel'] );

			if ( empty( $field_name ) ) {
				continue;
			}

			if ( 'date' === $field['type'] && ( ! empty( rgpost( 'input_' . $field['id'] )[0] ) ) ) {
				$date_array = wp_unslash( rgpost( 'input_' . $field['id'] ) );
				$date       = implode( '-', $date_array );
				$payload   .= '--' . $boundary . "\r\n";
				$payload   .= 'Content-Disposition: form-data; name="' . $field_name . '"' . "\r\n\r\n";
				$payload   .= $date . "\r\n";
			} elseif ( 'time' === $field['type'] ) {
				$time_array = wp_unslash( rgpost( 'input_' . $field['id'] ) );
				if ( isset( $time_array[2] ) && 'pm' === $time_array[2] ) {
					$time_array[0] += 12;
				}
				$time = sprintf( '%02d', $time_array[0] ) . ':' . sprintf( '%02d', $time_array[1] );

				$payload .= '--' . $boundary . "\r\n";
				$payload .= 'Content-Disposition: form-data; name="' . $field_name . '"' . "\r\n\r\n";
				$payload .= $time . "\r\n";
			} elseif ( 'checkbox' === $field['type'] ) {
				$payload .= '--' . $boundary . "\r\n";
				$payload .= 'Content-Disposition: form-data; name="' . $field_name . '"' . "\r\n\r\n";
				$payload .= ( ! empty( sanitize_text_field( wp_unslash( rgpost( 'input_' . $field['id'] . '_1' ) ) ) ) ? 1 : 0 ) . "\r\n";
			} elseif ( 'fileupload' === $field['type'] ) {
				if ( ! empty( $field['multipleFiles'] ) ) {
					error_log( 'VWTGF Converter: Multiple file uploads are not supported for Vtiger field ' . $field_name ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log

					continue;
				}

				$upload_path = GFFormsModel::get_upload_path( $entry['form_id'] );
				$upload_url  = GFFormsModel::get_upload_url( $entry['form_id'] );
				$filelink    = str_replace( $upload_path, $upload_url, $entry[ $field['id'] ] );

				if ( '' === $filelink ) {
					continue;
				}

				$file_path = str_replace( $upload_url, $upload_path, $filelink );

				if ( ! is_readable( $file_path ) ) {
					continue;
				}

				$file_content = file_get_contents( $file_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
				$file_name    = sanitize_file_name( basename( $file_path ) );

				if ( empty( $file_name ) ) {
					$file_name = 'upload';
				}

				$payload .= '--' . $boundary . "\r\n";
				$payload .= 'Content-Disposition: form-data; name="' . $field_name . '"; filename="' . $file_name . '"' . "\r\n";
				$payload .= 'Content-Type: ' . $this->get_url_mimetype( $file_content ) . "\r\n\r\n";
				$payload .= $file_content . "\r\n";
			} else {
				$payload .= '--' . $boundary . "\r\n";
				$payload .= 'Content-Disposition: form-data; name="' . $field_name . '"' . "\r\n\r\n";
				$payload .= sanitize_text_field( wp_unslash( rgpost( 'input_' . $field['id'] ) ) ) . "\r\n";
			}
		}

		$payload .= '--' . $boundary . '--';

		$args = [
			'headers' => $headers,
			'method'  => 'POST',
			'body'    => $payload,
		];

		/**
		 * Allow to modify the args before posting it to Vtiger.
		 *
		 * @param array $args The args to post to Vtiger.
		 * @param object $form The form object.
		 */
		$args = gf_apply_filters( [ 'vwtgf_converter_request_args', $form['id'] ], $args, $form );

		/*
		 *  Post it to Vtiger.
		 */
		$response = wp_safe_remote_request( self::$vtiger_url, $args );

		if ( is_wp_error( $response ) ) {
			error_log( 'VWTGF Converter: Vtiger request failed: ' . $response->get_error_message() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log

			return;
		}

		$status_code = wp_remote_retrieve_response_code( $response );

		if ( 200 > $status_code || 299 < $status_code ) {
			error_log( 'VWTGF Converter: Vtiger request returned HTTP ' . (int) $status_code ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}

	/**
	 * Get the mime type of a file.
	 *
	 * @param string $file_content The file content.
	 *
	 * @return false|string
	 */
	public function get_url_mimetype( $file_content ) {
		$finfo = new \finfo( FILEINFO_MIME_TYPE );
		return $finfo->buffer( $file_content );
	}

	/**
	 * Sanitize multipart field names for use in Content-Disposition headers.
	 *
	 * @param string $name Field name.
	 *
	 * @return string
	 */
	private function sanitize_multipart_name( $name ) {
		return preg_replace( '/[^A-Za-z0-9_.:\-\[\]]/', '', (string) $name );
	}

	/**
	 * Mark the api_url as an external URL.
	 *
	 * @param bool   $external Whether HTTP request is external or not.
	 * @param string $host Host name of the requested URL.
	 * @param string $url Requested URL.
	 *
	 * @return bool
	 */
	public function http_request_host_is_external( $external, $host, $url ) {
		return $external || ( $url === self::$vtiger_url && VtigerUrl::is_allowed_vtiger_url( $url ) );
	}

	/**
	 * Configure sslverify.
	 *
	 * @param array $args The args array.
	 *
	 * @return array
	 */
	public function configure_ssl_verification( $args ) {
		$args['sslverify'] = (bool) apply_filters( 'vwtgf_converter_sslverify', true );

		return $args;
	}
}
