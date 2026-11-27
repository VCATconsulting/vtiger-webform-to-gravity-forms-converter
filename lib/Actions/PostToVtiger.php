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
		$boundary = wp_generate_password( 48, false, false );

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

			if ( 'date' === $field['type'] ) {
				$value = wp_unslash( rgpost( 'input_' . $field['id'] ) );
				// Gravity Forms submits three components for date fields and dropdowns, but one string for a datepicker.
				if ( is_array( $value ) ) {
					if ( 3 !== count( $value ) || ! isset( $value[0], $value[1], $value[2] )
						|| 3 !== count( array_filter( $value, 'is_string' ) )
						|| 3 !== count( array_filter( $value, 'ctype_digit' ) ) ) {
						continue;
					}
				} elseif ( ! is_string( $value ) || 'datepicker' !== ( $field['dateType'] ?? '' ) ) {
					continue;
				}

				// Interpret day and month in the field's display order before sending Vtiger a YYYY-MM-DD value.
				$format = ! empty( $field['dateFormat'] ) ? $field['dateFormat'] : 'mdy';
				$parts  = \GFCommon::parse_date( $value, $format );
				$year   = $parts['year'] ?? '';
				$month  = (int) ( $parts['month'] ?? 0 );
				$day    = (int) ( $parts['day'] ?? 0 );
				if ( 4 !== strlen( $year ) || ! checkdate( $month, $day, (int) $year ) ) {
					continue;
				}

				$date = sprintf( '%04d-%02d-%02d', $year, $month, $day );
				$payload   .= '--' . $boundary . "\r\n";
				$payload   .= 'Content-Disposition: form-data; name="' . $field_name . '"' . "\r\n\r\n";
				$payload   .= $date . "\r\n";
			} elseif ( 'time' === $field['type'] ) {
				$parts = wp_unslash( rgpost( 'input_' . $field['id'] ) );
				if ( ! is_array( $parts ) || ! is_string( $parts[0] ?? null ) || ! is_string( $parts[1] ?? null )
					|| 1 !== preg_match( '/^[0-9]{1,2}$/D', $parts[0] ) || 1 !== preg_match( '/^[0-9]{1,2}$/D', $parts[1] ) ) {
					continue;
				}
				$hour   = (int) $parts[0];
				$minute = (int) $parts[1];
				if ( 59 < $minute ) {
					continue;
				}
				if ( '24' === (string) ( $field['timeFormat'] ?? '' ) ) {
					// Gravity Forms accepts 24:00 as the end of the day, but not later minutes in hour 24.
					if ( 24 < $hour || ( 24 === $hour && 0 !== $minute ) ) {
						continue;
					}
				} else {
					$period = is_string( $parts[2] ?? null ) ? strtolower( $parts[2] ) : '';
					if ( 1 > $hour || 12 < $hour || ! in_array( $period, [ 'am', 'pm' ], true ) ) {
						continue;
					}
					// Map 12 AM to midnight and 12 PM to noon.
					$hour = ( $hour % 12 ) + ( 'pm' === $period ? 12 : 0 );
				}
				$time = sprintf( '%02d:%02d', $hour, $minute );

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

				$file_path = $this->resolve_local_upload(
					$entry[ $field['id'] ] ?? null,
					absint( $entry['id'] ?? 0 ),
					absint( $entry['form_id'] ?? 0 )
				);

				if ( null === $file_path ) {
					continue;
				}
				$file_content = file_get_contents( $file_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
				if ( false === $file_content ) {
					continue;
				}
				$file_name = sanitize_file_name( basename( $file_path ) );

				if ( empty( $file_name ) ) {
					$file_name = 'upload';
				}

				$payload .= '--' . $boundary . "\r\n";
				$payload .= 'Content-Disposition: form-data; name="' . $field_name . '"; filename="' . $file_name . '"' . "\r\n";
				$payload .= 'Content-Type: ' . $this->get_url_mimetype( $file_content ) . "\r\n\r\n";
				$payload .= $file_content . "\r\n";
			} else {
				$default = (string) ( $field['defaultValue'] ?? '' );
				// Use the configured value for fixed fields, but keep submitted values for dynamic defaults.
				$fixed   = 'hidden' === $field['type'] && ! empty( $field['vwtgfFixedDefault'] )
				           && empty( $field['allowsPrepopulate'] ) && false === strpos( $default, '{' );
				$value   = $fixed ? $default : wp_unslash( rgpost( 'input_' . $field['id'] ) );
				$payload .= '--' . $boundary . "\r\n";
				$payload .= 'Content-Disposition: form-data; name="' . $field_name . '"' . "\r\n\r\n";
				$payload .= ( 'textarea' === $field['type'] ? sanitize_textarea_field( $value ) : sanitize_text_field( $value ) ) . "\r\n";
			}
		}

		$payload .= '--' . $boundary . '--';

		$args = [
			'headers'     => $headers,
			'method'      => 'POST',
			'body'        => $payload,
			'redirection' => 0,
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
	 * Resolve a local single upload using the location saved with the entry.
	 *
	 * @param string $file_url File URL from the upload field.
	 * @param int    $entry_id Gravity Forms entry ID.
	 * @param int    $form_id  Gravity Forms form ID.
	 * @return string|null Canonical path, or null for an unsupported reference.
	 */
	private function resolve_local_upload( $file_url, $entry_id, $form_id ) {
		if ( ! is_string( $file_url ) || '' === $file_url || false !== strpos( $file_url, "\0" )
			|| ! $entry_id || ! $form_id || ! in_array( wp_parse_url( $file_url, PHP_URL_SCHEME ), [ 'http', 'https' ], true )
			|| ! wp_parse_url( $file_url, PHP_URL_HOST ) || false !== strpbrk( $file_url, '?#' ) ) {
			return null;
		}

		$meta = false;
		if ( is_callable( [ \GF_Field_FileUpload::class, 'get_file_upload_path_meta_key_hash' ] ) ) {
			$key  = \GF_Field_FileUpload::get_file_upload_path_meta_key_hash( $file_url );
			$meta = gform_get_meta( $entry_id, $key );
		}

		// Only missing metadata permits a fallback to this form's upload directory; reject inconsistent mappings.
		if ( false === $meta ) {
			$base_url = GFFormsModel::get_upload_url( $form_id );
			$base_dir = GFFormsModel::get_upload_path( $form_id );
		} elseif ( is_array( $meta ) && is_string( $meta['file_name'] ?? null )
			&& '' !== $meta['file_name'] && wp_basename( $file_url ) === $meta['file_name'] ) {
			$base_url = $meta['url'] ?? null;
			$base_dir = $meta['path'] ?? null;
		} else {
			return null;
		}

		if ( ! is_string( $base_url ) || '' === $base_url || ! is_string( $base_dir ) || '' === $base_dir
			|| false !== strpos( $base_dir, "\0" ) || wp_is_stream( $base_dir ) || ! path_is_absolute( $base_dir ) ) {
			return null;
		}

		$prefix = trailingslashit( $base_url );
		if ( 0 !== strpos( $file_url, $prefix ) ) {
			return null;
		}

		$relative = substr( $file_url, strlen( $prefix ) );
		if ( '' === $relative || false !== strpos( $relative, '\\' )
			|| array_intersect( [ '', '.', '..' ], explode( '/', $relative ) ) ) {
			return null;
		}

		if ( false === $meta ) {
			$candidate = trailingslashit( $base_dir ) . $relative;
		} else {
			// GF stores the parent directory and basename of this specific file.
			if ( $meta['file_name'] !== $relative ) {
				return null;
			}
			$candidate = GFFormsModel::get_physical_file_path( $file_url, $entry_id );
		}

		if ( ! is_string( $candidate ) || '' === $candidate || wp_is_stream( $candidate ) || ! path_is_absolute( $candidate ) ) {
			return null;
		}
		// Resolve symlinks before checking that the file is still inside the allowed directory.
		$root = realpath( $base_dir );
		$file = realpath( $candidate );
		if ( false === $root || false === $file || ! is_dir( $root ) || ! is_file( $file ) || ! is_readable( $file ) ) {
			return null;
		}

		$root_prefix = rtrim( $root, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR;
		return 0 === strpos( $file, $root_prefix ) ? $file : null;
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
