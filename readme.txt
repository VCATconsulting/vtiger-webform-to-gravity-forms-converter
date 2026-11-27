=== Vtiger Webform to Gravity Forms Converter ===

Contributors: VCATconsulting, shogathu, nida78
Requires at least: 5.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.3.0
License: GPLv3
License URI: https://www.gnu.org/licenses/gpl-3.0.txt

Convert Vtiger Webforms to Gravity Forms

== Description ==

This plugin is an add-on to the [Gravity Forms](https://www.gravityforms.com/ "visit Gravity Forms website") form builder plugin.
It offers the opportunity to convert Vtiger Webforms to Gravity Forms and post the data to [Vtiger CRM (open source version)](https://www.vtiger.com/de/open-source-crm/download-open-source/ "visit Vtiger CRM website").

It requires a Vtiger CRM Open Source installation and the [Gravity Forms](https://www.gravityforms.com/ "visit Gravity Forms website") plugin.

Paste the HTML code from your Vtiger webform into the converter and click "Convert". The plugin creates a Gravity Forms form or updates an existing form that matches the Vtiger public ID and capture URL.

== Installation ==

1. Install and configure Gravity Forms plugin
2. Find this Vtiger Webform to Gravity Forms Converter plugin in the "Add Plugins" page within your WordPress installation or upload the Vtiger Webform to Gravity Forms Converter plugin to your blog
3. Activate it
4. Find the Vtiger Webform Converter in the admin menu under Forms!

== Screenshots ==

1. The input field where you can paste the Vtiger Webform code

== Frequently Asked Questions ==

= Can I change the form metadata before form creation? =

Yes. Use the `vwtgf_converter_form_meta` filter to change form settings before creation, such as disabling the honeypot.

You can find an example usage of this filter in [a small plugin in a GIST](https://gist.github.com/vcat-support/487c3fe6e711a6336a0927f83aaf2db5).

= Can I change the field metadata before form creation? =

Yes, the plugin offers 2 filters called `vwtgf_converter_field_meta` and type specific `vwtgf_converter_field_meta_{$input_type}` which you can use to change field meta like field length.

You can find an example usage of this filter in [a small plugin in a GIST](https://gist.github.com/vcat-support/bdfacfb8377362901e985af1165d57a3).

= Can I change the max upload file size and file extension for an upload field? =

Yes, the plugin offers 2 filters called `vwtgf_converter_upload_file_size` and `vwtgf_converter_upload_file_extensions` to customize the upload field.

= Is it possible to update an existing form? =

Yes. Paste the Vtiger webform code into the converter and click "Convert". The plugin checks both active and inactive Gravity Forms for a match; forms in the trash are excluded.
A form matches only if both the Vtiger public ID and the capture URL match. If no form matches, the plugin creates a new one. If more than one form matches, the import stops without overwriting any form.
Updating a form replaces its field list. Fields added manually and changes made to field settings in the Gravity Forms editor will be lost.

= Can I use the default Gravity Forms time field? =

Yes. The converter imports the Vtiger time input as a text field. To use a Gravity Forms Time field instead, add one after conversion, copy the imported field's Admin Field Label to it, and remove the imported text field.
You can use either the 12-hour or 24-hour format. The converter sends the time to Vtiger as HH:MM in 24-hour format.

= Can I rearrange the fields? =

Yes, you can drag and drop the fields in the Gravity Forms editor.
IMPORTANT: When you re-import the Vtiger webform, the fields will be rearranged to the original order of the Vtiger webform.

= I accidentally deleted an important field, can I re-add it? =

Yes, you can re-add the field by pasting the Vtiger webform code again and click the "Convert" button.
Or you can add the field manually in the Gravity Forms editor, but keep in mind to set the admin label to the right value.

= Can I enable multiple files for Vtiger upload fields? =

No. Vtiger accepts one file per upload field. For upload fields imported with version 1.3.0 or later, the Gravity Forms editor hides the multi-file option and the converter enforces single-file mode when the form is saved.
If an existing Vtiger upload field is already configured for multiple files, the converter skips that field during submission and writes a warning to the PHP error log.

= Why are default values from the Vtiger webform still submitted? =

A preselected option in Gravity Forms and a default configured in Vtiger are not the same. For a visible dropdown, the converter sends the visitor's selection, but Vtiger may still apply its own webform default. For an imported hidden field with a fixed value, the converter sends the configured value.
If Vtiger should use the visitor's selection, remove the default from the Vtiger webform. If a deleted default still takes effect, ask your Vtiger administrator to check for a stale value in the database.

== Changelog ==

= 1.3.0 =

* Show which forms and fields were imported from Vtiger in the Gravity Forms editor. Use the `vwtgf_converter_source_info_color` filter to customize the field notice color.
* Keep imported Vtiger upload fields in single-file mode when editing and saving a form.
* Match forms on re-import by both the Vtiger public ID and the capture URL, including inactive forms. Stop if multiple forms match and report failed updates.
* Send Gravity Forms Date field values to Vtiger as YYYY-MM-DD using the field's configured date format.
* Send Gravity Forms Time field values to Vtiger as HH:MM in 24-hour format, correctly handling noon and midnight for 12-hour fields.
* Preserve required dropdowns, preselected options, and checked checkboxes. Keep the first real dropdown option instead of treating it as a placeholder.
* Translate standard Vtiger dropdown placeholders at import time and preserve custom placeholder text.
* Preserve line breaks in textarea defaults and submissions, and retain explicit zero values when importing field defaults.
* Use configured defaults for fixed hidden fields when forwarding submissions, while respecting dynamically populated fields.
* Resolve local uploads using the file location saved with the entry, including custom upload directories. Verify that each file is inside the expected directory before forwarding it.
* Disable automatic redirects when forwarding submissions to Vtiger.
* Show an admin notice if the Composer autoloader is missing, even when composer.json is not included in the release package.


= 1.2.0 =

* Improve security checks for webform conversion requests.
* Add stricter validation for Vtiger webform target URLs.
* Improve handling of Vtiger upload fields, including local file forwarding and support for field names like `imagename[]`.
* Prevent unsupported Gravity Forms multi-file uploads from being sent to Vtiger and log a warning instead.
* Improve sanitization of imported Gravity Forms field metadata.
* Improve compatibility with newer WordPress admin notices.

= 1.1.2 =

* Fix a bug where admin notice was not shown after form conversion/update

= 1.1.1 =

* Fix some wording

= 1.1.0 =

* Add own sanitization for the Vtiger Webform code with wp_kses
* Add 2 new filters to change the allowed tags and attributes for the Vtiger Webform code
* Made some code improvements

= 1.0.1 =

* Update Github Actions

= 1.0.0 =

* First stable version
