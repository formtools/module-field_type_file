<?php

namespace FormTools\Modules\FieldTypeFile;

use FormTools\Core;
use FormTools\FieldTypes;
use Exception;


/**
 * Contains all the code executed on the various core hooks. See Module.class.php to show what methods get called for
 * which hook.
 */
class Settings
{
	private static $fieldTypeRecordMap = array(
		"is_editable" => "no",
		"non_editable_info" => "This module can only be edited via the File Upload module.",
		"field_type_name" => "{\$LANG.word_file}",
		"field_type_identifier" => "file",
		"is_file_field" => "yes",
		"is_date_field" => "no",
		"raw_field_type_map" => "file",
		"raw_field_type_map_multi_select_id" => null,
		"compatible_field_sizes" => "large,very_large",
		"view_field_rendering_type" => "smarty",
		"view_field_php_function_source" => "core",
		"view_field_php_function" => "",
		"php_processing" => "",
		"resources_js" => "/* all JS for this module is found in /modules/field_type_file/scripts/edit_submission.js */"
	);

	private static $resourcesCss = <<< END
.cf_file_list {
	list-style-type: none;
	margin: 0 0 4px;
	padding: 0;
}
.cf_file_top_row {
	border-bottom: 1px solid #dddddd;
}
.cf_file_col {
	font-style: italic;
	color: #999999;
}
END;

	private static $viewFieldSmartyMarkup = <<< END
{if \$VALUE}
    <a href="{\$folder_url}/{\$VALUE}"
    {if \$use_fancybox == "yes"}class="fancybox"{/if}>{\$VALUE}</a>
{/if}
END;


	/*
	scenarios:

	1. no data
		- (single + multiple) show file upload field only
	2. one item
		- (single + multiple) show item, delete button, file upload field (same row)
	2. two items
	 	- show table
		- underneath: delete button, file upload field

	scenario:
	- in multiple, user deletes all but one.
		- needs to switch
	*/

	private static $editFieldSmartyMarkup = <<< END
<div class="cf_file">
    <input type="hidden" class="cf_file_field_id" value="{\$FIELD_ID}" />
	{assign var=filenames value=":"|explode:\$VALUE} 
	{assign var=num_files value=\$filenames|@count}

	<ul class="cf_file_list" {if empty(\$VALUE)}style="display: none"{/if}>
		<li class="cf_file_top_row">
			<input type="checkbox" class="cf_file_toggle_all" />
			<span class="cf_file_col">Filename</span>
		</li>
		{foreach from=\$filenames item=filename}
		<li>
			<input type="checkbox" name="cf_files[]" class="cf_file_row_cb" value="{\$filename}" />
			<a href="{\$folder_url}/{\$filename}" 
				{if \$use_fancybox == "yes"}class="fancybox"{/if}>{\$filename}</a>
			{if \$num_files == 1}
				<input type="button" class="cf_delete_file" value="{\$LANG.phrase_delete_file|upper}" />
			{/if}
		</li>
		{/foreach}
	</ul>
	<input type="button" value="Delete Selected" class="cf_file_delete_selected"
		disabled="disabled" {if empty(\$VALUE)}style="display: none"{/if} />
	<input type="file" name="{\$NAME}{if \$multiple_files == "yes"}[]{/if}" {if \$multiple_files == "yes"}multiple="multiple"{/if}" /> 

    <div id="file_field_{\$FIELD_ID}_message_id" class="cf_file_message"></div>
</div>

{if \$comments}
    <div class="cf_field_comments">{\$comments}</div>
{/if}
END;

//
//<div id="cf_file_{\$FIELD_ID}_no_content" {if \$multiple_files === "no" || \$VALUE}style="display:none"{/if}>
//<input type="file" name="{\$NAME}{if \$multiple_files == "yes"}[]{/if}" {if \$multiple_files == "yes"}multiple="multiple"{/if}" />
//</div>

	private static $fieldSettings = array(
		array(
			"field_label" => "Open link with Fancybox",
			"field_setting_identifier" => "use_fancybox",
			"field_type" => "radios",
			"field_orientation" => "horizontal",
			"default_value_type" => "static",
			"default_value" => "no",
			"settings" => array(
				array(
					"option_text" => "Yes",
					"option_value" => "yes",
					"option_order" => 1,
					"is_new_sort_group" => "yes"
				),
				array(
					"option_text" => "No",
					"option_value" => "no",
					"option_order" => 2,
					"is_new_sort_group" => "no"
				)
			)
		),

		array(
			"field_label" => "Allow multiple file uploads",
			"field_setting_identifier" => "multiple_files",
			"field_type" => "radios",
			"field_orientation" => "horizontal",
			"default_value_type" => "static",
			"default_value" => "no",
			"settings" => array(
				array(
					"option_text" => "Yes",
					"option_value" => "yes",
					"option_order" => 1,
					"is_new_sort_group" => "yes"
				),
				array(
					"option_text" => "No",
					"option_value" => "no",
					"option_order" => 2,
					"is_new_sort_group" => "no"
				)
			)
		),

		array(
			"field_label" => "Folder Path",
			"field_setting_identifier" => "folder_path",
			"field_type" => "textbox",
			"field_orientation" => "na",
			"default_value_type" => "dynamic",
			"default_value" => "file_upload_dir",
			"settings" => array()
		),

		array(
			"field_label" => "Folder URL",
			"field_setting_identifier" => "folder_url",
			"field_type" => "textbox",
			"field_orientation" => "na",
			"default_value_type" => "dynamic",
			"default_value" => "file_upload_url,core",
			"settings" => array()
		),

		array(
			"field_label" => "Permitted File Types",
			"field_setting_identifier" => "permitted_file_types",
			"field_type" => "textbox",
			"field_orientation" => "na",
			"default_value_type" => "dynamic",
			"default_value" => "file_upload_filetypes,core",
			"settings" => array()
		),

		array(
			"field_label" => "Max File Size (KB)",
			"field_setting_identifier" => "max_file_size",
			"field_type" => "textbox",
			"field_orientation" => "na",
			"default_value_type" => "dynamic",
			"default_value" => "file_upload_max_size,core",
			"settings" => array()
		),

		array(
			"field_label" => "Field Comments",
			"field_setting_identifier" => "comments",
			"field_type" => "textbox",
			"field_orientation" => "na",
			"default_value_type" => "static",
			"default_value" => "",
			"settings" => array()
		)
	);

	public static function addFieldType($module_id, $group_id, $list_order)
	{
		$db = Core::$db;

		$db->query("
            INSERT INTO {PREFIX}field_types (is_editable, non_editable_info, managed_by_module_id, field_type_name,
                field_type_identifier, group_id, is_file_field, is_date_field, raw_field_type_map, 
                raw_field_type_map_multi_select_id, list_order, compatible_field_sizes,
                view_field_rendering_type, view_field_php_function_source, view_field_php_function,
                view_field_smarty_markup, edit_field_smarty_markup, php_processing, resources_css, resources_js)
            VALUES (:is_editable, :non_editable_info, :module_id, :field_type_name, :field_type_identifier, :group_id,
                :is_file_field, :is_date_field, :raw_field_type_map, :raw_field_type_map_multi_select_id, :list_order,
                :compatible_field_sizes, :view_field_rendering_type, :view_field_php_function_source, :view_field_php_function,
                :view_field_smarty_markup, :edit_field_smarty_markup, :php_processing, :resources_css, :resources_js)
        ");
		$db->bindAll(self::$fieldTypeRecordMap);
		$db->bindAll(array(
			"module_id" => $module_id,
			"group_id" => $group_id,
			"list_order" => $list_order,
			"view_field_smarty_markup" => self::$viewFieldSmartyMarkup,
			"edit_field_smarty_markup" => self::$editFieldSmartyMarkup,
			"resources_css" => self::$resourcesCss
		));
		$db->execute();

		return $db->getInsertId();
	}


	public static function updateFieldType()
	{
		$db = Core::$db;

		$field_type = FieldTypes::getFieldTypeByIdentifier("file");
		if (empty($field_type)) {
			return;
		}

		try {
			$db->query("
				UPDATE {PREFIX}field_types 
				SET    is_editable = :is_editable,
					   non_editable_info = :non_editable_info,
					   managed_by_module_id = :module_id,
					   field_type_name = :field_type_name,
					   field_type_identifier = :field_type_identifier,
					   group_id = :group_id,
					   is_file_field = :is_file_field,
					   is_date_field = :is_date_field,
					   raw_field_type_map = :raw_field_type_map,
					   raw_field_type_map_multi_select_id = :raw_field_type_map_multi_select_id,
					   list_order = :list_order,
					   compatible_field_sizes = :compatible_field_sizes,
					   view_field_rendering_type = :view_field_rendering_type,
					   view_field_php_function_source = :view_field_php_function_source,
					   view_field_php_function = :view_field_php_function,
					   view_field_smarty_markup = :view_field_smarty_markup,
					   edit_field_smarty_markup = :edit_field_smarty_markup,
					   php_processing = :php_processing,
					   resources_css = :resources_css,
					   resources_js = :resources_js
				WHERE field_type_id = :field_type_id
			");
			$db->bindAll(self::$fieldTypeRecordMap);
			$db->bindAll(array(
				"field_type_id" => $field_type["field_type_id"],
				"module_id" => $field_type["module_id"],
				"group_id" => $field_type["group_id"],
				"list_order" => $field_type["list_order"],
				"view_field_smarty_markup" => self::$viewFieldSmartyMarkup,
				"edit_field_smarty_markup" => self::$editFieldSmartyMarkup,
				"resources_css" => self::$resourcesCss
			));

			$db->execute();
		} catch (Exception $e) {
			echo $e->getMessage();
		}
	}


	private static function resetFieldSettingOptions($setting_id, $settings)
	{
		$db = Core::$db;

		$db->query("
			DELETE FROM {PREFIX}field_type_setting_options
			WHERE setting_id = :setting_id 
		");
		$db->bind("setting_id", $setting_id);
		$db->execute();

		if (!empty($settings)) {
			$data = array();
			foreach ($settings as $setting) {
				$setting["setting_id"] = $setting_id;
				$data[] = $setting;
			}
			FieldTypes::addFieldTypeSettingOptions($data);
		}
	}


	private static function resetValidationRules($field_type_id)
	{
		$db = Core::$db;

		$db->query("DELETE FROM {PREFIX}field_type_validation_rules WHERE field_type_id = :field_type_id");
		$db->bind("field_type_id", $field_type_id);
		$db->execute();

		$db->query("
            INSERT INTO {PREFIX}field_type_validation_rules (field_type_id, rsv_rule, rule_label,
                rsv_field_name, custom_function, custom_function_required, default_error_message, list_order)
            VALUES (:field_type_id, :rsv_rule, :rule_label, :rsv_field_name, :custom_function,
                :custom_function_required, :default_error_message, :list_order)
        ");
		$db->bindAll(array(
			"field_type_id" => $field_type_id,
			"rsv_rule" => "function",
			"rule_label" => "{\$LANG.word_required}",
			"rsv_field_name" => "",
			"custom_function" => "files_ns.check_required",
			"custom_function_required" => "yes",
			"default_error_message" => "{\$LANG.validation_default_rule_required}",
			"list_order" => 1
		));
		$db->execute();
	}


	public static function installOrUpdateFieldTypeSettings($field_type_id)
	{
		$db = Core::$db;

		$field_type_settings = FieldTypes::getFieldTypeSettings($field_type_id);
		$settings_by_setting_identifier = array();
		foreach ($field_type_settings as $setting_info) {
			$settings_by_setting_identifier[$setting_info["field_setting_identifier"]] = $setting_info;
		}

		$list_order = 1;
		foreach (self::$fieldSettings as $setting_info) {
			$setting_identifier = $setting_info["field_setting_identifier"];

			// if the field type already exists, update it
			if (isset($settings_by_setting_identifier[$setting_identifier])) {
				$setting_id = $settings_by_setting_identifier[$setting_identifier]["setting_id"];

				try {
					$db->query("
						UPDATE {PREFIX}field_type_settings
						SET    field_type_id = :field_type_id,
							   field_label = :field_label,
							   field_setting_identifier = :field_setting_identifier,
							   field_type = :field_type,
							   field_orientation = :field_orientation,
							   default_value_type = :default_value_type,
							   default_value = :default_value,
							   list_order = :list_order
						WHERE setting_id = :setting_id
					");
					$db->bindAll(array(
						"field_type_id" => $field_type_id,
						"field_label" => $setting_info["field_label"],
						"field_setting_identifier" => $setting_info["field_setting_identifier"],
						"field_type" => $setting_info["field_type"],
						"field_orientation" => $setting_info["field_orientation"],
						"default_value_type" => $setting_info["default_value_type"],
						"default_value" => $setting_info["default_value"],
						"list_order" => $list_order,
						"setting_id" => $setting_id
					));
					$db->execute();
				} catch (Exception $e) {
					echo $e->getMessage();
					exit;
				}
			} else {
				$setting_id = FieldTypes::addFieldTypeSetting(
					$field_type_id, $setting_info["field_label"], $setting_info["field_setting_identifier"],
					$setting_info["field_type"], $setting_info["field_orientation"], $setting_info["default_value_type"],
					$setting_info["default_value"], $list_order
				);
			}

			self::resetFieldSettingOptions($setting_id, $setting_info["settings"]);
			$list_order++;
		}

		self::resetValidationRules($field_type_id);
	}
}