( function () {
	'use strict';

	gform.addFilter( 'gform_editor_field_settings', function ( settings, field ) {
		if ( field.vwtgfImported !== true ) {
			return settings;
		}

		if ( field.type === 'fileupload' ) {
			return settings.filter( function ( setting ) {
				return setting !== '.multiple_files_setting';
			} ).concat( '.vwtgf_source_setting', '.vwtgf_single_upload_setting' );
		}

		return settings.concat( '.vwtgf_source_setting' );
	} );

	function showSourceNotice() {
		const form = window.form;
		if ( ! form || document.getElementById( 'vwtgf-form-source' ) ) {
			return;
		}

		// Older imports only have the converter's hidden destination field.
		const imported = form.vwtgfImported === true ||
			( form.vwtgfImported === undefined && Array.isArray( form.fields ) &&
				form.fields.some( field => field.adminLabel === 'vtiger_POST_url' ) );
		if ( ! imported ) {
			return;
		}

		const editor = document.querySelector( '.gform_editor.gform_wrapper' );
		const template = document.getElementById( 'vwtgf-form-source-template' );
		if ( editor && template ) {
			editor.before( template.content.cloneNode( true ) );
		}
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', showSourceNotice, { once: true } );
	} else {
		showSourceNotice();
	}
	document.addEventListener( 'gform/form_editor/initialized', showSourceNotice );
} )();
