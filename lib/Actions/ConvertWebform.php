<?php
/**
 * Class to convert a Vtiger Webform into a Gravity Forms form.
 *
 * @package VWTGF_CONVERTER\Actions
 */

namespace VWTGF_CONVERTER\Actions;

use DOMDocument;
use GFAPI;
use VWTGF_CONVERTER\Helpers\VtigerUrl;
use WP_Error;

/**
 * Class ConvertWebform
 */
class ConvertWebform {
	/**
	 * Init all filter and action hooks so that they can be used.
	 *
	 * @see https://wordpress.org/gutenberg/handbook/blocks/writing-your-first-block-type/#enqueuing-block-scripts
	 */
	public function init() {
		add_action( 'admin_post_vwtgf_converter_convert_form', [ $this, 'vwtgf_converter_convert_web_form' ] );
		add_action( 'gform_field_standard_settings', [ $this, 'render_source_setting' ] );
		add_action( 'gform_editor_js', [ $this, 'load_source_editor_assets' ] );
		add_filter( 'gform_form_update_meta', [ $this, 'enforce_single_upload' ], 10, 3 );
	}

	/**
	 * Display read-only source information in the field editor.
	 *
	 * @param int $position Field settings position.
	 */
	public function render_source_setting( $position ) {
		if ( 0 !== (int) $position ) {
			return;
		}

		$source_color = '#B30000';

		/**
		 * Filter the color of the source information text in the field editor.
		 *
		 * @param string $source_color The color of the source information text.
		 *
		 * @return string The filtered color of the source information text.
		 */
		$source_color = apply_filters( 'vwtgf_converter_source_info_color', $source_color );

		?>
		<li class="vwtgf_source_setting field_setting">
			<?php
			printf(
				'<span style="font-weight: bold; color: %s;">%s</span>',
				esc_attr( $source_color ),
				esc_html__( 'This field was imported from a Vtiger webform.', 'vtiger-webform-to-gravity-forms-converter' )
			);
			?>

		</li>
		<li class="vwtgf_single_upload_setting field_setting">
			<?php
			printf(
				'<span style="font-weight: bold; color: %s;">%s</span>',
				esc_attr( $source_color ),
				esc_html__( 'Vtiger accepts only one file per upload field. Multi-file uploads are disabled for this field.', 'vtiger-webform-to-gravity-forms-converter' )
			);
			?>

		</li>
		<?php
	}

	/**
	 * Load the editor script and its translated form notice.
	 */
	public function load_source_editor_assets() {
		wp_enqueue_script(
			'vwtgf-converter-form-editor',
			VWTGF_CONVERTER_URL . 'assets/js/form-editor.js',
			[ 'gform_form_editor' ],
			VWTGF_CONVERTER_VERSION,
			true
		);
		?>
		<template id="vwtgf-form-source-template">
			<div id="vwtgf-form-source" class="notice notice-info inline">
				<p><?php esc_html_e( 'This form was imported from a Vtiger webform.', 'vtiger-webform-to-gravity-forms-converter' ); ?></p>
			</div>
		</template>
		<?php
	}

	/**
	 * Keep imported upload fields in single-file mode when saving form metadata.
	 *
	 * @param array  $meta      Form metadata.
	 * @param int    $form_id   Gravity Forms form ID.
	 * @param string $meta_name Type of metadata being saved.
	 * @return array
	 */
	public function enforce_single_upload( $meta, $form_id, $meta_name ) {
		if ( 'display_meta' !== $meta_name || empty( $meta['fields'] ) ) {
			return $meta;
		}

		foreach ( $meta['fields'] as $index => $field ) {
			if ( true === rgar( $field, 'vwtgfImported' ) && 'fileupload' === rgar( $field, 'type' ) ) {
				$meta['fields'][ $index ]['multipleFiles'] = false;
			}
		}

		return $meta;
	}

	/**
	 * Convert the Vtiger Webform to a Gravity Form and check if the form already exists.
	 *
	 * @return void
	 */
	public function vwtgf_converter_convert_web_form() {
		/*
		 * Check Permission
		 */
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to convert Vtiger webforms.', 'vtiger-webform-to-gravity-forms-converter' ), 403 );
		}

		/*
		 * Check if addmin referer.
		 */
		check_admin_referer( 'vwtgf_converter_convert_webform', 'vwtgf_converter_convert_webform' );

		/*
		 * Get the raw HTML from the request and unslash it.
		 * Remove all script tags to prevent XSS.
		 * HTML is not stored in the Database or outputted to the user.
		 * Own input santize later.
		 */
		$raw_html = isset( $_POST['webform'] ) ? wp_unslash( $_POST['webform'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$raw_html = is_string( $raw_html ) ? $raw_html : '';

		$raw_html = preg_replace( '@<(script|style|noscript)\b[^>]*>.*?</\\1>@si', '', $raw_html );

		/*
		 * Clean and santize the HTML.
		 */
		$clean_html = $this->vwtgf_converter_sanitize_webform_input( $raw_html );

		/*
		 * Check if form is set and not empty.
		 */
		if ( ! empty( $clean_html ) ) {
				/*
			 * Convert HTML to a nested array.
			 */
			$html_array = $this->vwtgf_converter_html_to_nested_array( $clean_html );

			/*
			 * Convert HTML Array to a Gravity Forms Form Object.
			 */
			$form_meta = [ 'vwtgfImported' => true ];
			$fieldid   = 1;
			$this->vwtgf_converter_create_gf_form_object( [ $html_array ], $form_meta, $fieldid );

			foreach ( $form_meta['fields'] ?? [] as $index => $field ) {
				if ( 'vtiger_POST_url' !== ( $field['adminLabel'] ?? '' ) ) {
					$form_meta['fields'][ $index ]['vwtgfImported'] = true;
					if ( 'hidden' === ( $field['type'] ?? '' ) && '' !== ( $field['defaultValue'] ?? '' ) ) {
						$form_meta['fields'][ $index ]['vwtgfFixedDefault'] = true;
					}
				}
			}

			$fields = $form_meta['fields'] ?? [];
			$values = array_column( $fields, 'defaultValue', 'adminLabel' );
			if ( empty( $form_meta['title'] ) || empty( $values['vtiger_POST_url'] ) || '' === ( $values['publicid'] ?? '' ) ) {
				wp_safe_redirect( admin_url( 'admin.php?page=vwtgf-converter&status=error' ) );
				exit;
			}

			$form_id     = 0;
			$form_exists = $this->vwtgf_converter_check_given_form_meta( $form_meta, $form_id );
			if ( is_wp_error( $form_exists ) ) {
				wp_safe_redirect( admin_url( 'admin.php?page=vwtgf-converter&status=error' ) );
				exit;
			}

			if ( $form_exists ) {
				$result = $this->vwtgf_converter_update_existing_form( $form_meta, $form_id );
				$status = true === $result ? 'updated' : 'error';
				wp_safe_redirect( admin_url( 'admin.php?page=vwtgf-converter&status=' . $status ) );
				exit;
			} else {
				/*
				 * Add the built Form Object to Gravity Forms.
				 */
				$form_meta['enableHoneypot'] = true;
				$form_meta['honeypotAction'] = 'spam';

				/**
				 * Filter the form meta before adding the form to Gravity Forms.
				 *
				 * @param array $form_meta The form meta.
				 */
				$form_meta = apply_filters( 'vwtgf_converter_form_meta', $form_meta );

				$status = GFAPI::add_form( $form_meta );
				if ( $status instanceof WP_Error ) {
					/*
					 * If an error occurred, redirect to the admin page with the status error.
					 */
					wp_safe_redirect( admin_url( 'admin.php?page=vwtgf-converter&status=error' ) );
					exit;
				}

				/*
				 * Redirect to the admin page with the status success.
				 */
				wp_safe_redirect( admin_url( 'admin.php?page=vwtgf-converter&status=success' ) );
				exit;
			}
		} else {
			/*
			 * If form is empty, redirect to the admin page with the status error.
			 */
			wp_safe_redirect( admin_url( 'admin.php?page=vwtgf-converter&status=error' ) );
			exit;
		}
	}

	/**
	 * Escape HTML content but keep the tags intact.
	 *
	 * @param string $html The HTML string to escape.
	 * @return string Escaped HTML.
	 */
	public function vwtgf_converter_sanitize_webform_input( $html ) {
		$allowed_tags = [
			'meta'     => [
				'http-equiv' => [],
				'content'    => [],
			],
			'form'     => [
				'id'             => [],
				'name'           => [],
				'action'         => [],
				'method'         => [],
				'accept-charset' => [],
				'enctype'        => [],
			],
			'input'    => [
				'type'        => [],
				'name'        => [],
				'value'       => [],
				'placeholder' => [],
				'required'    => [],
				'multiple'    => [],
				'checked'     => [],
				'selected'    => [],
				'data-label'  => [],
			],
			'table'    => [],
			'tbody'    => [],
			'tr'       => [],
			'td'       => [],
			'th'       => [],
			'label'    => [
				'for' => [],
			],
			'textarea' => [
				'name'        => [],
				'placeholder' => [],
				'required'    => [],
			],

			'div'      => [
				'id'    => [],
				'class' => [],
			],
			'select'   => [
				'name'       => [],
				'id'         => [],
				'class'      => [],
				'data-label' => [],
				'hidden'     => [],
				'required'   => [],

			],
			'option'   => [
				'value'    => [],
				'selected' => [],
			],
		];

		/**
		 * Filter the allowed tags for the webform sanitization.
		 *
		 * @param array $allowed_tags The allowed tags.
		 */
		$allowed_tags = apply_filters( 'vwtgf_converter_allowed_tags', $allowed_tags );
		$allowed_tags = apply_filters( 'vwtgf_converter_allowd_tags', $allowed_tags );

		/**
		 * Filter the allowed custom html for the webform sanitization.
		 *
		 * @param array $html The allowed tags.
		 */
		$html = apply_filters( 'vwtgf_converter_custom_webform_sanitization', $html );

		return wp_kses( $html, $allowed_tags );
	}

	/**
	 * Function to recursively build nested array
	 *
	 * @param object $element The element to build the array from.
	 *
	 * @return array
	 */
	public function vwtgf_converter_build_array_from_element( $element ) {
		$result = [];

		/*
		 * Get the tag name
		 */
		$tag_name = $element->tagName;

		/*
		 * Get the attributes.
		 */
		$attributes = [];
		foreach ( $element->attributes as $attribute ) {
			$attributes[ $attribute->name ] = $attribute->value;
		}

		/*
		 * Add tag name and attributes to the result.
		 */
		$result['tag']        = $tag_name;
		$result['attributes'] = $attributes;

		/*
		 * Check if the element has children.
		 */
		if ( $element->hasChildNodes() ) {
			/*
			 * Iterate through children.
			 */
			foreach ( $element->childNodes as $child ) {
				/*
				 * Check if the child is an element node.
				 */
				if ( XML_ELEMENT_NODE === $child->nodeType ) {
					/*
					 * Recursively build array for child element.
					 */
					$result['children'][] = $this->vwtgf_converter_build_array_from_element( $child );
				} elseif ( XML_TEXT_NODE === $child->nodeType ) {
					/*
					 * Add text content.
					 */
					$result['text'] = trim( $child->textContent );
				}
			}
		}

		return $result;
	}

	/**
	 * Converts an HTML String to an Array
	 *
	 * @param string $html_string The HTML String to convert.
	 *
	 * @return array
	 */
	public function vwtgf_converter_html_to_nested_array( $html_string ) {
		/*
		 * Create a DOMDocument object.
		 */
		$dom = new DOMDocument();

		/*
		 * Load HTML string into the DOMDocument.
		 */
		$previous = libxml_use_internal_errors( true );
		$loaded   = $dom->loadHTML( $html_string, LIBXML_NONET );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		/*
		 * Get the root element.
		 */
		$root_element = $dom->documentElement;

		if ( ! $loaded || ! $root_element ) {
			return [];
		}

		/*
		* Build array from root element.
		*/

		return $this->vwtgf_converter_build_array_from_element( $root_element );
	}

	/**
	 * Recursively Iterates through the nested HTML Array and adds the Fields to $form_meta
	 *
	 * @param array $html_array The HTML Array.
	 * @param array $form_meta The Form Meta Array.
	 * @param int   $fieldid The ID of the Field.
	 *
	 * @return void
	 */
	public function vwtgf_converter_create_gf_form_object( $html_array, &$form_meta, &$fieldid ) {
		/*
		 *  Switch for the different HTML Tags.
		 */
		foreach ( $html_array as $element ) {
			$input_type  = 'hidden';
			$attributes  = isset( $element['attributes'] ) && is_array( $element['attributes'] ) ? $element['attributes'] : [];
			$attr_name   = isset( $attributes['name'] ) ? $attributes['name'] : '';
			$attr_value  = isset( $attributes['value'] ) ? $attributes['value'] : '';
			$attr_type   = isset( $attributes['type'] ) ? $attributes['type'] : '';
			$attr_action = isset( $attributes['action'] ) ? $attributes['action'] : '';
			$el_children = isset( $element['children'] ) && is_array( $element['children'] ) ? $element['children'] : [];

			switch ( $element['tag'] ) {
				case 'html':
				case 'body':
				case 'table':
				case 'tbody':
					/*
					 * Just go deeper Into the Array.
					 */
					$this->vwtgf_converter_create_gf_form_object( $el_children, $form_meta, $fieldid );
					break;
				case 'form':
					/*
					 * Sets the GF title and adds the VTiger URL as a Hidden Field. Then go deeper into the Array.
					 */
					$action_url = esc_url_raw( $attr_action );

					if ( empty( $action_url ) || ! VtigerUrl::is_allowed_vtiger_url( $action_url ) ) {
						return;
					}

					$form_meta['title'] = ! empty( $attr_name ) ? sanitize_text_field( $attr_name ) : __( 'Imported Vtiger Form', 'vtiger-webform-to-gravity-forms-converter' );

					$field = [
						'type'         => $input_type,
						'id'           => $fieldid++,
						'label'        => 'vtiger_POST_url',
						'adminLabel'   => 'vtiger_POST_url',
						'defaultValue' => $action_url,
						'size'         => 'large',
					];

					$form_meta['fields'][] = $field;

					$this->vwtgf_converter_create_gf_form_object( $el_children, $form_meta, $fieldid );
					break;
				case 'input':
					/*
					 * If the Input is a Submit Button, add the Button to the Form Meta.
					 */
					if ( 'submit' === $attr_type ) {
						$form_meta['button'] = [
							'type' => 'text',
							'text' => sanitize_text_field( $attr_value ),
						];
						break;
					}

					/*
					 * If the Input is hidden, set the field type to hidden.
					 */
					$field = [
						'type'         => $input_type,
						'id'           => $fieldid++,
						'label'        => sanitize_text_field( $attr_name ),
						'adminLabel'   => $this->sanitize_vtiger_field_name( $attr_name ),
						'defaultValue' => sanitize_text_field( $attr_value ?? '' ),
						'isRequired'   => array_key_exists( 'required', $attributes ),
						'size'         => 'large',
					];

					$form_meta['fields'][] = $field;
					break;
				case 'tr':
					$label = '';
					$index = 0;
					$field = [];

					/*
					 *
					 * If the Input has a Label, set the Label.
					 */
					if ( count( $el_children ) === 2 ) {
						$label = isset( $el_children[0]['children'][0]['text'] ) ? $el_children[0]['children'][0]['text'] : '';
						$index = 1;
					}

					/*
					 * Sets the Array to the Element itself (not the Label Array).
					 */
					$cell_children = isset( $el_children[ $index ]['children'] ) && is_array( $el_children[ $index ]['children'] ) ? $el_children[ $index ]['children'] : [];

					if ( empty( $cell_children ) ) {
						break;
					}

					if ( count( $cell_children ) === 2 ) {
						$element = $cell_children[1];
					} else {
						$element = $cell_children[0];
					}

					$attributes  = isset( $element['attributes'] ) && is_array( $element['attributes'] ) ? $element['attributes'] : [];
					$attr_name   = isset( $attributes['name'] ) ? $attributes['name'] : '';
					$attr_value  = isset( $attributes['value'] ) ? $attributes['value'] : '';
					$attr_type   = isset( $attributes['type'] ) ? $attributes['type'] : '';
					$el_children = isset( $element['children'] ) && is_array( $element['children'] ) ? $element['children'] : [];

					/*
					 *  Input field.
					 */
					if ( 'input' === $element['tag'] ) {
						$choices = [];

						/*
						 * Check for special fields.
						 */
						if ( empty( $label ) ) {
							$input_type = 'hidden';
						} elseif ( 'email' === $attr_name ) {
							$input_type = 'email';
						} elseif ( 'mobile' === $attr_name || 'phone' === $attr_name || 'fax' === $attr_name ) {
							$input_type = 'phone';
						} elseif ( 'file' === $attr_type ) {
							$input_type = 'fileupload';
						} elseif ( 'checkbox' === $attr_type ) {
							$input_type = 'checkbox';
						} elseif ( 'date' === $attr_type ) {
							$input_type = 'date';
						} elseif ( 'website' === $attr_name ) {
							$input_type = 'website';
						} else {
							$input_type = 'text';
						}

						/*
						 * Checks the field type with the label (label empty = hidden field).
						 */
							$field = [
								'type'         => $input_type,
								'id'           => $fieldid++,
								'label'        => sanitize_text_field( ! empty( $label ) ? $label : $attr_name ),
								'adminLabel'   => $this->sanitize_vtiger_field_name( $attr_name ),
								'defaultValue' => sanitize_text_field( $attr_value ?? '' ),
								'isRequired'   => array_key_exists( 'required', $attributes ),
								'size'         => 'large',
							];

							/*
							* Checks if the field is an email field and add extra field attributes.
							*/
							if ( 'email' === $input_type ) {
								$field['placeholder'] = 'name@example.ltd';
							}

							/*
							* Checks if the field is a phone field and add extra field attributes.
							*/
							if ( 'mobile' === $input_type || 'phone' === $input_type || 'fax' === $input_type ) {
								$field['placeholder'] = '+49 (0) 123 / 456 789 0';
							}

							/*
							* Checks if the field is a website field and add extra field attributes.
							*/
							if ( 'website' === $input_type ) {
								$field['placeholder'] = 'https://example.ltd';
							}

							/*
							* Checks if the field is a file upload field and add extra field attributes and remove some.
							*/
							if ( 'fileupload' === $input_type ) {
								$file_size = 5;

								/**
								 * Filter for the file size (in MB) for the file upload field.
								 *
								 * @param string $file_size The file size.
								 * @param array $field The field array.
								 */
								$file_size = apply_filters( 'vwtgf_converter_upload_file_size', $file_size, $field );

								$allowed_extensions = 'pdf,doc,docx,jpg,jpeg,png';

								/**
								 * Filter for the allowed file extensions for the file upload field.
								 *
								 * @param string $allowed_extensions The allowed file extensions.
								 * @param array $field The field array.
								 */
								$allowed_extensions = apply_filters( 'vwtgf_converter_upload_file_extensions', $allowed_extensions, $field );

								$field['multipleFiles']     = false;
								$field['maxFileSize']       = $file_size;
								$field['allowedExtensions'] = $allowed_extensions;
								unset( $field['size'] );
								unset( $field['defaultValue'] );
							}

							if ( 'date' === $input_type ) {
								$field['dateType']   = 'datedropdown';
								$field['dateFormat'] = 'ymd_dash';
							}

							if ( 'checkbox' === $input_type ) {
								$choices['text']       = sanitize_text_field( $label );
								$choices['value']      = sanitize_text_field( $label );
								$choices['isSelected'] = array_key_exists( 'checked', $attributes );
								$field['choices'][0] = $choices;
								unset( $field['label'] );
							}

							/*
							* Select Field.
							*/
					} elseif ( 'textarea' === $element['tag'] ) {
							/*
							 * Check for special fields.
							 */
						if ( empty( $label ) ) {
							$input_type = 'hidden';
						} else {
							$input_type = 'textarea';
						}

							/*
							 * Checks the field type with the label (label empty = hidden field).
							 */
							$field = [
								'type'         => $input_type,
								'id'           => $fieldid++,
								'label'        => sanitize_text_field( ! empty( $label ) ? $label : $attr_name ),
								'adminLabel'   => $this->sanitize_vtiger_field_name( $attr_name ),
								'defaultValue' => sanitize_textarea_field( $element['text'] ?? '' ),
								'isRequired'   => array_key_exists( 'required', $attributes ),
								'size'         => 'large',
							];
					} elseif ( 'select' === $element['tag'] ) {
						$is_multiple = array_key_exists( 'multiple', $attributes );

						/*
						 * Checks if the Select Field is Hidden.
						 */
						if ( ! empty( $label ) ) {
							/*
							 * Visible Select Field.
							 */
							$choices     = [];
							$placeholder = '';

							/*
							 *Iterates through all options and add them to the option Array.
							*/
							foreach ( $el_children as $index => $option ) {
								$option_attributes = isset( $option['attributes'] ) && is_array( $option['attributes'] ) ? $option['attributes'] : [];
								$text              = sanitize_text_field( $option['text'] ?? '' );

								// Only an explicit empty value on the first option denotes a placeholder.
								if ( ! $is_multiple && 0 === $index && '' === ( $option_attributes['value'] ?? null ) ) {
									$placeholder = in_array( strtolower( $text ), [ 'select value', 'select an option' ], true )
										? __( 'Select an option', 'vtiger-webform-to-gravity-forms-converter' )
										: $text;
									continue;
								}

								$choices[] = [
									'text'       => $text,
									'value'      => isset( $option_attributes['value'] ) ? sanitize_text_field( $option_attributes['value'] ) : $text,
									'isSelected' => array_key_exists( 'selected', $option_attributes ),
								];
							}

							$input_type = $is_multiple ? 'multiselect' : 'select';

									$field = [
										'type'        => $input_type,
										'id'          => $fieldid++,
										'label'       => sanitize_text_field( $label ),
										'adminLabel'  => $this->sanitize_vtiger_field_name( $attr_name ),
										'placeholder' => sanitize_text_field( $placeholder ),
										'choices'     => $choices,
										'isRequired'  => array_key_exists( 'required', $attributes ),
										'size'        => 'large',
									];
						} else {
							/*
							 * Hidden Select Field: Gets Converted to a Hidden Input Field.
							 */
							$select_value = '';

							/*
							* Gets the Selected option.
							*/
							foreach ( $el_children as $option ) {
								$option_attributes = isset( $option['attributes'] ) && is_array( $option['attributes'] ) ? $option['attributes'] : [];

								if ( array_key_exists( 'selected', $option_attributes ) ) {
									$select_value = isset( $option_attributes['value'] ) ? $option_attributes['value'] : '';
								}
							}
									$field = [
										'type'         => $input_type,
										'id'           => $fieldid++,
										'label'        => sanitize_text_field( $attr_name ),
										'adminLabel'   => $this->sanitize_vtiger_field_name( $attr_name ),
										'defaultValue' => sanitize_text_field( $select_value ),
										'size'         => 'large',
									];
						}
					}

					/**
					 * Allow to modify the field before adding it to the form meta.
					 *
					 * @param array $field The field array.
					 * @param array  $element The element array.
					 * @param string $input_type The input type.
					 */
					$field = apply_filters( "vwtgf_converter_field_meta_{$input_type}", $field, $element );

					/**
					 * Allow to modify the field before adding it to the form meta.
					 *
					 * @param array  $field The field array.
					 * @param array $element The element array.
					 * @param string $input_type The input type.
					 */
					$field = apply_filters( 'vwtgf_converter_field_meta', $field, $element, $input_type );

					$form_meta['fields'][] = $field;

					break;
				default:
					break;
			}
		}
	}

	/**
	 * Check if the given form already exists in Gravity Forms.
	 *
	 * @param array $form_meta The Form Meta Array.
	 * @param int   $form_id The Form ID.
	 *
	 * @return bool|WP_Error Whether a unique target was found, or an error for ambiguous matches.
	 */
	public function vwtgf_converter_check_given_form_meta( $form_meta, &$form_id ) {
		$import = array_column( $form_meta['fields'], 'defaultValue', 'adminLabel' );
		$match  = 0;
		foreach ( GFAPI::get_forms( null, false ) as $form ) {
			$values = [];
			foreach ( $form['fields'] as $field ) {
				if ( in_array( $field->adminLabel, [ 'publicid', 'vtiger_POST_url' ], true ) ) {
					$values[ $field->adminLabel ] = $field->defaultValue;
				}
			}
			if ( $import['publicid'] !== ( $values['publicid'] ?? null )
				|| $import['vtiger_POST_url'] !== ( $values['vtiger_POST_url'] ?? null ) ) {
				continue;
			}
			if ( 0 !== $match ) {
				return new WP_Error( 'vwtgf_ambiguous_form', __( 'More than one matching Vtiger form was found.', 'vtiger-webform-to-gravity-forms-converter' ) );
			}
			$match = (int) $form['id'];
		}

		$form_id = $match;
		return 0 !== $match;
	}

	/**
	 * Update the existing form with the new form meta.
	 *
	 * @param array $form_meta The Form Meta Array.
	 * @param int   $form_id The Form ID.
	 *
	 * @return true|WP_Error
	 */
	public function vwtgf_converter_update_existing_form( $form_meta, $form_id ) {
		$form = GFAPI::get_form( $form_id );
		if ( ! is_array( $form ) ) {
			return new WP_Error( 'vwtgf_form_not_found', __( 'The form could not be loaded.', 'vtiger-webform-to-gravity-forms-converter' ) );
		}

		$form['fields']        = $form_meta['fields'];
		$form['vwtgfImported'] = $form_meta['vwtgfImported'];
		return GFAPI::update_form( $form );
	}

	/**
	 * Sanitize a Vtiger field name while preserving the expected field identifier.
	 *
	 * @param string $name Field name.
	 *
	 * @return string
	 */
	private function sanitize_vtiger_field_name( $name ) {
		return preg_replace( '/[\r\n"\']/', '', sanitize_text_field( (string) $name ) );
	}
}
