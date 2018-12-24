<?php


namespace FormTools\Modules\FieldTypeFile;

use FormTools\Core;
use FormTools\FieldTypes;
use FormTools\Fields;
use FormTools\Files;
use FormTools\General;
use FormTools\Hooks;
use FormTools\Module as FormToolsModule;
use FormTools\Submissions;
use PDO, Exception;


class Module extends FormToolsModule
{
	protected $moduleName = "File Upload";
	protected $moduleDesc = "This module provides a simple file upload field for use in your Form Tools fields.";
	protected $author = "Ben Keen";
	protected $authorEmail = "ben.keen@gmail.com";
	protected $authorLink = "https://formtools.org";
	protected $version = "2.1.0";
	protected $date = "2018-01-20";
	protected $originLanguage = "en_us";

	protected $nav = array(
		"module_name" => array("index.php", false)
	);

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
		"resources_css" => "",
		"resources_js" => "/* all JS for this module is found in /modules/field_type_file/scripts/edit_submission.js */"
	);

	private static $viewFieldSmartyMarkup = <<< END
{if \$VALUE}
    <a href="{\$folder_url}/{\$VALUE}"
    {if \$use_fancybox == "yes"}class="fancybox"{/if}>{\$VALUE}</a>
{/if}
END;

	private static $editFieldSmartyMarkup = <<< END
<div class="cf_file">
    <input type="hidden" class="cf_file_field_id" value="{\$FIELD_ID}" />
    <div id="cf_file_{\$FIELD_ID}_content" {if !\$VALUE}style="display:none"{/if}>
        <a href="{\$folder_url}/{\$VALUE}" 
            {if \$use_fancybox == "yes"}class="fancybox"{/if}>{\$VALUE}</a>
        <input type="button" class="cf_delete_file" 
            value="{\$LANG.phrase_delete_file|upper}" />
    </div>
    <div id="cf_file_{\$FIELD_ID}_no_content" {if \$VALUE}style="display:none"{/if}>
        <input type="file" name="{\$NAME}{if \$multiple_files == "yes"}[]{/if}" {if \$multiple_files == "yes"}multiple="multiple"{/if}" />
    </div>
    <div id="file_field_{\$FIELD_ID}_message_id" class="cf_file_message"></div>
</div>
{if \$comments}
    <div class="cf_field_comments">{\$comments}</div>
{/if}
END;

	private $fieldSettings = array(
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


	public function install($module_id)
	{
		$db = Core::$db;
		$LANG = Core::$L;

		// check it's not already installed (i.e. check for the unique field type identifier)
		$field_type_info = FieldTypes::getFieldTypeIdByIdentifier("file");
		if (!empty($field_type_info)) {
			return array(false, $LANG["notify_module_already_installed"]);
		}

		// find the FIRST field type group. Most installations won't have the Custom Fields module installed so
		// the last group will always be "Special Fields". For installations that DO, and that it's been customized,
		// the user can always move this new field type to whatever group they want. Plus, this module will be
		// installed by default, so it's almost totally moot
		$db->query("
            SELECT group_id
            FROM   {PREFIX}list_groups
            WHERE  group_type = 'field_types'
            ORDER BY list_order ASC
            LIMIT 1
        ");
		$db->execute();

		// assumption: there's at least one field type group
		$group_id = $db->fetch(PDO::FETCH_COLUMN);

		// now find out how many field types there are in the group so we can add the row with the correct list order
		$db->query("SELECT count(*) FROM {PREFIX}field_types WHERE group_id = :group_id");
		$db->bind("group_id", $group_id);
		$db->execute();

		$next_list_order = $db->fetch(PDO::FETCH_COLUMN) + 1;

		$field_type_id = $this->addFieldType($module_id, $group_id, $next_list_order);
		$this->installOrUpdateFieldTypeSettings($field_type_id);

		// lastly, add our hooks
		$this->resetHooks();

		return array(true, "");
	}


	/**
	 * Uninstallation completely removes the field type. It also changes the field type ID from any file fields
	 * to a generic text field.
	 * @param $module_id
	 * @return array
	 */
	public function uninstall($module_id)
	{
		$field_type_info = FieldTypes::getFieldTypeByIdentifier("file");

		if (!empty($field_type_info)) {
			FieldTypes::deleteFieldType("file", "textbox");
		}

		return array(true, "");
	}


	public function upgrade($module_id, $old_module_version)
	{
		$this->updateFieldType();

		$field_type_info = FieldTypes::getFieldTypeByIdentifier("file");
		$this->installOrUpdateFieldTypeSettings($field_type_info["field_type_id"]);
		$this->resetHooks();
	}


	public function installOrUpdateFieldTypeSettings($field_type_id)
	{
		$db = Core::$db;

		$field_type_settings = FieldTypes::getFieldTypeSettings($field_type_id);
		$settings_by_setting_identifier = array();
		foreach ($field_type_settings as $setting_info) {
			$settings_by_setting_identifier[$setting_info["field_setting_identifier"]] = $setting_info;
		}

		$list_order = 1;
		foreach ($this->fieldSettings as $setting_info) {
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

			$this->resetFieldSettingOptions($setting_id, $setting_info["settings"]);
			$list_order++;
		}

		$this->resetValidationRules($field_type_id);
	}


	/**
	 * Handles all the actual work for uploading a file.
	 * @param $params
	 * @return array|void
	 */
	public function updateSubmissionHook($params)
	{
		$LANG = Core::$L;

		$file_fields = $params["file_fields"];

		// if there are no files being uploaded, do nuthin'
		if (empty($file_fields)) {
			return;
		}

		$form_id = $params["form_id"];
		$submission_id = $params["submission_id"];
		$module_field_type_id = FieldTypes::getFieldTypeIdByIdentifier("file");

		$problem_files = array();

		$return_info = array(
			"success" => true
		);

		foreach ($file_fields as $file_field_info) {
			$field_type_id = $file_field_info["field_info"]["field_type_id"];
			$field_name = $file_field_info["field_info"]["field_name"];

			if ($field_type_id != $module_field_type_id) {
				continue;
			}

			// nothing was included in this field, just ignore it
			if (empty($_FILES[$field_name]["name"])) {
				continue;
			}

			list($success, $message) = $this->uploadSubmissionFile($form_id, $submission_id, $file_field_info);
			if (!$success) {
				$problem_files[] = array($_FILES[$field_name]["name"], $message);
			} else {
				$return_info["message"] = $message;
			}
		}

		if (!empty($problem_files)) {
			$message = $LANG["notify_submission_updated_file_problems"] . "<br /><br />";
			foreach ($problem_files as $problem) {
				$message .= "&bull; <b>{$problem[0]}</b>: $problem[1]<br />\n";
			}

			$return_info = array(
				"success" => false,
				"message" => $message
			);
		}

		return $return_info;
	}


	/**
	 * Uploads a file for a particular form submission field. This is called AFTER the submission has already been
	 * added to the database so there's an available, valid submission ID. It uploads the file to the appropriate
	 * folder then updates the database record.
	 *
	 * Since any submission file field can only ever store a single file at once, this function automatically deletes
	 * the old file in the event of the new file being successfully uploaded.
	 *
	 * @param integer $form_id the unique form ID
	 * @param integer $submission_id a unique submission ID
	 * @param array $file_field_info
	 * @return array returns array with indexes:<br/>
	 *               [0]: true/false (success / failure)<br/>
	 *               [1]: message string<br/>
	 *               [2]: If success, the filename of the uploaded file
	 */
	public function uploadSubmissionFile($form_id, $submission_id, $file_field_info)
	{
		$db = Core::$db;
		$LANG = Core::$L;


		// get the column name and upload folder for this field
		$col_name = $file_field_info["field_info"]["col_name"];

		// if the column name wasn't found, the $field_id passed in was invalid. Somethin' aint right...
		if (empty($col_name)) {
			return array(false, $LANG["notify_submission_no_field_id"]);
		}

		$is_multiple_files = $file_field_info["settings"]["multiple_files"];
		$field_name = $file_field_info["field_info"]["field_name"];
		$file_upload_max_size = $file_field_info["settings"]["max_file_size"];
		$file_upload_dir = $file_field_info["settings"]["folder_path"];
		$permitted_file_types = $file_field_info["settings"]["permitted_file_types"];

		// check upload folder is valid and writable
		if (!is_dir($file_upload_dir) || !is_writable($file_upload_dir)) {
			return array(false, $LANG["notify_invalid_field_upload_folder"]);
		}

		$fileinfo = $this->extractFileUploadInfo($is_multiple_files, $field_name, $_FILES);

		// find out if there was already a file/files uploaded in this field. We make a note of this so that
		// in case the new file upload is successful, we automatically delete the old file
		$submission_info = Submissions::getSubmissionInfo($form_id, $submission_id);

		$errors = array();
		foreach ($fileinfo as $row) {

			// check file size
			if ($row["filesize"] > $file_upload_max_size) {
				$placeholders = array(
					"FILESIZE" => round($row["filesize"], 1),
					"MAXFILESIZE" => $file_upload_max_size
				);

				// TODO if MULTI, need better error message
				$error = General::evalSmartyString($LANG["notify_file_too_large"], $placeholders);
				$errors[] = $error;
				continue;
			}

			// check file extension is valid. Note: this is "dumb" - it just tests for the file extension string, not
			// the actual file type based on it's header info [this is done because I want to allow users to permit
			// uploading of any file types, and I can't know about all header types]
			$is_valid_extension = true;
			if (!empty($permitted_file_types)) {
				$is_valid_extension = false;
				$raw_extensions = explode(",", $permitted_file_types);

				foreach ($raw_extensions as $ext) {
					// remove whitespace and periods
					$clean_extension = str_replace(".", "", trim($ext));

					if (preg_match("/$clean_extension$/i", $row["filename"])) {
						$is_valid_extension = true;
					}
				}
			}

			// not a valid extension - inform the user
			if (!$is_valid_extension) {
				// TODO error cleanup for MULTI
				$errors[] = $LANG["notify_unsupported_file_extension"];
				continue;
			}
		}


		exit;
		$old_filename = (!empty($submission_info[$col_name])) ? $submission_info[$col_name] : "";

		// check for duplicate filenames and get a unique name
		$unique_filename = Files::getUniqueFilename($file_upload_dir, $filename);

		// copy file to uploads folder and remove temporary file
		if (@rename($tmp_filename, "$file_upload_dir/$unique_filename")) {
			@chmod("$file_upload_dir/$unique_filename", 0777);

			// update the database
			try {
				$db->query("
                    UPDATE {PREFIX}form_{$form_id}
                    SET    $col_name = :unique_filename
                    WHERE  submission_id = :submission_id
                ");
				$db->bindAll(array(
					"unique_filename" => $unique_filename,
					"submission_id" => $submission_id
				));
				$db->execute();

				// if there was a file previously uploaded in this field, delete it!
				if (!empty($old_filename)) {
					@unlink("$file_upload_dir/$old_filename");
				}

				return array(true, $LANG["notify_file_uploaded"], $unique_filename);
			} catch (Exception $e) {
				return array(false, $LANG["notify_file_not_uploaded"] . ": " . $e->getMessage());
			}
		} else {
			return array(false, $LANG["notify_file_not_uploaded"]);
		}
	}


	/**
	 * Deletes a file that has been uploaded through a form submission file field.
	 *
	 * @param integer $form_id the unique form ID
	 * @param integer $submission_id a unique submission ID
	 * @param integer $field_id a unique form field ID
	 * @param boolean $force_delete this forces the file to be deleted from the database, even if the
	 *                file itself doesn't exist or doesn't have the right permissions.
	 * @return array Returns array with indexes:<br/>
	 *               [0]: true/false (success / failure)<br/>
	 *               [1]: message string<br/>
	 */
	public function deleteFileSubmission($form_id, $submission_id, $field_id, $force_delete = false)
	{
		$db = Core::$db;
		$LANG = Core::$L;

		// get the column name and upload folder for this field
		$field_info = Fields::getFormField($field_id);
		$col_name = $field_info["col_name"];

		// if the column name wasn't found, the $field_id passed in was invalid. Return false.
		if (empty($col_name)) {
			return array(false, $LANG["notify_submission_no_field_id"]);
		}

		$field_settings = Fields::getFieldSettings($field_id);
		$file_folder = $field_settings["folder_path"];

		$db->query("
            SELECT $col_name
            FROM   {PREFIX}form_{$form_id}
            WHERE  submission_id = :submission_id
        ");
		$db->bind("submission_id", $submission_id);
		$db->execute();

		$file = $db->fetch(PDO::FETCH_COLUMN);

		$update_database_record = false;
		$success = true;
		$message = "";

		if (!empty($file)) {
			if ($force_delete) {
				@unlink("$file_folder/$file");
				$message = $LANG["notify_file_deleted"];
				$update_database_record = true;
			} else {
				if (@unlink("$file_folder/$file")) {
					$success = true;
					$message = $LANG["notify_file_deleted"];
					$update_database_record = true;
				} else {
					if (!is_file("$file_folder/$file")) {
						$success = false;
						$update_database_record = false;
						$replacements = array("js_link" => "return files_ns.delete_submission_file($field_id, true)");
						$message = General::evalSmartyString($LANG["notify_file_not_deleted_no_exist"] . "($file_folder/$file)", $replacements);
					} else {
						if (is_file("$file_folder/$file") && (!is_readable("$file_folder/$file") || !is_writable("$file_folder/$file"))) {
							$success = false;
							$update_database_record = false;
							$replacements = array("js_link" => "return files_ns.delete_submission_file($field_id, true)");
							$message = General::evalSmartyString($LANG["notify_file_not_deleted_permissions"], $replacements);
						} else {
							$success = false;
							$update_database_record = false;
							$replacements = array("js_link" => "return files_ns.delete_submission_file($field_id, true)");
							$message = General::evalSmartyString($LANG["notify_file_not_deleted_unknown_error"], $replacements);
						}
					}
				}
			}
		}

		// if need be, update the database record to remove the reference to the file in the database. Generally this
		// should always work, but in case something funky happened, like the permissions on the file were changed to
		// forbid deleting, I think it's best if the record doesn't get deleted to remind the admin/client it's still
		// there.
		if ($update_database_record) {
			$db->query("
                UPDATE {PREFIX}form_{$form_id}
                SET    $col_name = ''
                WHERE  submission_id = :submission_id
            ");
			$db->bind("submission_id", $submission_id);
			$db->execute();
		}

		extract(Hooks::processHookCalls("end", compact("form_id", "submission_id", "field_id", "force_delete"), array("success", "message")), EXTR_OVERWRITE);

		return array($success, $message);
	}


	/**
	 * Our template hook. This includes all required JS for the Edit Submission page.
	 */
	public function includeJs($template, $page_data)
	{
		$root_url = Core::getRootUrl();

		$curr_page = $page_data["page"];
		if ($curr_page != "admin_edit_submission" && $curr_page != "client_edit_submission") {
			return;
		}

		echo "<script src=\"$root_url/modules/field_type_file/scripts/edit_submission.js\"></script>\n";
	}


	/**
	 * Used for any module (e.g. Form Builder) that uses the form fields in a standalone context.
	 */
	public function includeStandaloneJs($template, $page_data)
	{
		$root_url = Core::getRootUrl();
		$LANG = Core::$L;

		// this includes the necessary JS for the file upload field type
		echo <<< END
  <script src="$root_url/modules/field_type_file/scripts/standalone.js"></script>
  <script>
  if (typeof g.messages == 'undefined')
    g.messages = {};

  g.messages["confirm_delete_submission_file"] = "{$LANG["confirm_delete_submission_file"]}";
  g.messages["phrase_please_confirm"] = "{$LANG["phrase_please_confirm"]}";
  g.messages["word_yes"] = "{$LANG["word_yes"]}";
  g.messages["word_no"] = "{$LANG["word_no"]}";
  </script>
END;
	}


	/**
	 * Called by the ft_process_form function. It handles the file upload for all "File" Field types.
	 * @param $params
	 * @return array|void
	 */
	public function processFormSubmissionHook($params)
	{
		$LANG = Core::$L;

		$file_fields = $params["file_fields"];
		if (empty($file_fields)) {
			return;
		}

		$form_id = $params["form_id"];
		$submission_id = $params["submission_id"];

		$module_field_type_id = FieldTypes::getFieldTypeIdByIdentifier("file");
		$problem_files = array();
		$redirect_query_params = $params["redirect_query_params"];

		$return_info = array(
			"success" => true,
			"message" => "",
			"redirect_query_params" => $redirect_query_params
		);

		foreach ($file_fields as $file_field_info) {
			$field_id = $file_field_info["field_info"]["field_id"];
			$field_type_id = $file_field_info["field_info"]["field_type_id"];
			$field_name = $file_field_info["field_info"]["field_name"];
			$include_on_redirect = $file_field_info["field_info"]["include_on_redirect"];

			if ($module_field_type_id != $field_type_id) {
				continue;
			}

			$field_settings = Fields::getFieldSettings($field_id);
			$file_field_info["settings"] = $field_settings;

			// nothing was included in this field, just ignore it
			if (empty($_FILES[$field_name]["name"])) {
				continue;
			}

			list($success, $message, $filename) = $this->uploadSubmissionFile($form_id, $submission_id,
				$file_field_info);
			if (!$success) {
				$problem_files[] = array($_FILES[$field_name]["name"], $message);
			} else {
				$return_info["message"] = $message;
				if ($include_on_redirect == "yes") {
					$redirect_query_params[] = "$field_name=" . rawurlencode($filename);
				}
			}
		}

		if (!empty($problem_files)) {
			$message = $LANG["notify_submission_updated_file_problems"] . "<br /><br />";
			foreach ($problem_files as $problem) {
				$message .= "&bull; <b>{$problem[0]}</b>: $problem[1]<br />\n";
			}

			$return_info = array(
				"success" => false,
				"message" => $message,
				"redirect_query_params" => $redirect_query_params
			);
		} else {
			$return_info["redirect_query_params"] = $redirect_query_params;
		}

		return $return_info;
	}


	/**
	 * This is called by the ft_process_form function. It handles the file upload for all "File" Field types.
	 *
	 * @param array $params
	 */
	public function apiProcessFormSubmissionHook($params)
	{
		$LANG = Core::$L;

		// if the form being submitted doesn't contain any form fields we do nothing
		$file_fields = $params["file_fields"];
		if (empty($file_fields)) {
			return;
		}

		$form_id = $params["form_id"];
		$submission_id = $params["submission_id"];
		$namespace = $params["namespace"];

		$module_field_type_id = FieldTypes::getFieldTypeIdByIdentifier("file");
		$problem_files = array();

		$return_info = array(
			"success" => true,
			"message" => ""
		);

		foreach ($file_fields as $file_field_info) {
			$field_type_id = $file_field_info["field_info"]["field_type_id"];
			if ($module_field_type_id != $field_type_id) {
				continue;
			}

			$field_id = $file_field_info["field_info"]["field_id"];
			$field_name = $file_field_info["field_info"]["field_name"];
			$field_settings = Fields::getFieldSettings($field_id);
			$file_field_info["settings"] = $field_settings;

			// nothing was included in this field, just ignore it
			if (empty($_FILES[$field_name]["name"])) {
				continue;
			}

			list($success, $message, $filename) = $this->uploadSubmissionFile($form_id, $submission_id,
				$file_field_info);
			if (!$success) {
				$problem_files[] = array($_FILES[$field_name]["name"], $message);
			} else {
				$return_info["message"] = $message;
				$curr_file_info = array(
					"filename" => $filename,
					"file_upload_dir" => $file_field_info["settings"]["folder_path"],
					"file_upload_url" => $file_field_info["settings"]["folder_url"]
				);
				$_SESSION[$namespace][$field_name] = $curr_file_info;
			}
		}

		if (!empty($problem_files)) {
			$message = $LANG["notify_submission_updated_file_problems"] . "<br /><br />";
			foreach ($problem_files as $problem) {
				$message .= "&bull; <b>{$problem[0]}</b>: $problem[1]<br />\n";
			}

			$return_info = array(
				"success" => false,
				"message" => $message
			);
		}

		return $return_info;
	}


	/**
	 * Called whenever a submission or submissions are deleted. It's the hook for the ft_delete_submission_files
	 * Core function.
	 *
	 * @param array $params this contains all the details passed by the hook.
	 */
	public function deleteSubmissionsHook($params)
	{
		$L = $this->getLangStrings();

		$file_field_info = $params["file_field_info"];

		$problems = array();
		$module_field_type_id = FieldTypes::getFieldTypeIdByIdentifier("file");
		foreach ($file_field_info as $info) {
			if ($info["field_type_id"] != $module_field_type_id) {
				continue;
			}

			$field_id = $info["field_id"];
			$filename = $info["filename"];

			$field_settings = Fields::getFieldSettings($field_id);
			$folder = $field_settings["folder_path"];

			if (!@unlink("$folder/$filename")) {
				if (!is_file("$folder/$filename")) {
					$problems[] = array(
						"filename" => $filename,
						"error" => General::evalSmartyString($L["notify_file_not_deleted_no_exist"], array("folder" => $folder))
					);
				} else {
					if (is_file("$folder/$filename") && (!is_readable("$folder/$filename") || !is_writable("$folder/$filename"))) {
						$problems[] = array(
							"filename" => $filename,
							"error" => General::evalSmartyString($L["notify_file_not_deleted_permissions"], array("folder" => $folder))
						);
					} else {
						$problems[] = array(
							"filename" => $filename,
							"error" => General::evalSmartyString($L["notify_file_not_deleted_unknown_error"], array("folder" => $folder))
						);
					}
				}
			}
		}

		if (empty($problems)) {
			return array(true, "");
		} else {
			return array(false, $problems);
		}
	}


	/**
	 * This is the hook for the Files::getUploadedFiles core function. It returns an array of hashes;
	 *
	 * @param array $params
	 */
	public function getUploadedFilesHook($params)
	{
		$db = Core::$db;

		$form_id = $params["form_id"];
		$field_ids = (isset($params["field_ids"]) && is_array($params["field_ids"])) ? $params["field_ids"] : array();

		$module_field_type_id = FieldTypes::getFieldTypeIdByIdentifier("file");

		$data = array();
		foreach ($field_ids as $field_id) {
			$field_type_id = FieldTypes::getFieldTypeIdByFieldId($field_id);
			if ($field_type_id != $module_field_type_id) {
				continue;
			}

			$result = Fields::getFieldColByFieldId($form_id, $field_id);
			$col_name = $result[$field_id];
			if (empty($col_name)) {
				continue;
			}

			try {
				$db->query("SELECT submission_id, $col_name FROM {PREFIX}form_{$form_id}");
				$db->execute();
			} catch (Exception $e) {
				continue;
			}

			$field_settings = Fields::getFieldSettings($field_id);
			foreach ($field_settings as $row) {
				// here, nothing's been uploaded in the field
				if (empty($row[$col_name])) {
					continue;
				}

				$data[] = array(
					"submission_id" => $row["submission_id"],
					"field_id" => $field_id,
					"field_type_id" => $module_field_type_id,
					"folder_path" => $field_settings["folder_path"],
					"folder_url" => $field_settings["folder_url"],
					"filename" => $row[$col_name]
				);
			}
		}

		return array(
			"uploaded_files" => $data
		);
	}


	/**
	 * Called on installation and upgrades.
	 */
	public function resetHooks()
	{
		Hooks::unregisterModuleHooks("field_type_file");

		Hooks::registerHook("code", "field_type_file", "manage_files", "FormTools\\Submissions::updateSubmission", "updateSubmissionHook", 50, true);
		Hooks::registerHook("code", "field_type_file", "manage_files", "FormTools\\Submissions::processFormSubmission", "processFormSubmissionHook", 50, true);
		Hooks::registerHook("code", "field_type_file", "manage_files", "FormTools\\API->processFormSubmission", "apiProcessFormSubmissionHook", 50, true);
		Hooks::registerHook("code", "field_type_file", "start", "FormTools\\Files::deleteSubmissionFiles", "deleteSubmissionsHook", 50, true);
		Hooks::registerHook("code", "field_type_file", "start", "FormTools\\Fields::getUploadedFiles", "getUploadedFilesHook", 50, true);
		Hooks::registerHook("template", "field_type_file", "head_bottom", "", "includeJs");
		Hooks::registerHook("template", "field_type_file", "standalone_form_fields_head_bottom", "", "includeStandaloneJs");
	}


	private static function addFieldType($module_id, $group_id, $list_order)
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
			"edit_field_smarty_markup" => self::$editFieldSmartyMarkup
		));
		$db->execute();

		return $db->getInsertId();
	}


	private static function updateFieldType()
	{
		$db = Core::$db;

		$field_type = FieldTypes::getFieldTypeByIdentifier("file");
		if (empty($file_type)) {
			return;
		}

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
                   resources_js = :resources_js,
			WHERE field_type_id = :field_type_id
        ");
		$db->bindAll(self::$fieldTypeRecordMap);
		$db->bindAll(array(
			"module_id" => $field_type["module_id"],
			"group_id" => $field_type["group_id"],
			"list_order" => $field_type["list_order"],
			"view_field_smarty_markup" => self::$viewFieldSmartyMarkup,
			"edit_field_smarty_markup" => self::$editFieldSmartyMarkup
		));
		$db->execute();
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


	/**
	 * Returns an array of the following structure:
	 * [
	 *   [
	 *     "filename" => "",
	 *   ],
	 *    ...
	 * ]
	 * @param $is_multiple_files
	 * @param $field_name
	 * @param $files
	 */
	private static function extractFileUploadInfo($is_multiple_files, $field_name, $files)
	{
		$char_whitelist = Core::getFilenameCharWhitelist();
		$file_info = $files[$field_name];

		// clean up the filename according to the whitelist chars
		$filedata = array();
		if ($is_multiple_files == "no") {
			$filename_parts = explode(".", $file_info["name"]);
			$extension = $filename_parts[count($filename_parts) - 1];
			array_pop($filename_parts);
			$filename_without_extension = implode(".", $filename_parts);
			$valid_chars = preg_quote($char_whitelist);
			$filename_without_ext_clean = preg_replace("/[^$valid_chars]/", "", $filename_without_extension);
			if (empty($filename_without_ext_clean)) {
				$filename_without_ext_clean = "file";
			}

			$filedata[] = array(
				"filename" => $filename_without_ext_clean . "." . $extension,
				"filesize" => $file_info["size"] / 1000,
				"tmp_filename" => $file_info["tmp_name"]
			);
		} else {
			$num_files = count($files[$field_name]["name"]);
			for ($i=0; $i<$num_files; $i++) {
				$filename_parts = explode(".", $file_info["name"][$i]);
				$extension = $filename_parts[count($filename_parts) - 1];
				array_pop($filename_parts);
				$filename_without_extension = implode(".", $filename_parts);
				$valid_chars = preg_quote($char_whitelist);
				$filename_without_ext_clean = preg_replace("/[^$valid_chars]/", "", $filename_without_extension);
				if (empty($filename_without_ext_clean)) {
					$filename_without_ext_clean = "file";
				}

				$filedata[] = array(
					"filename" => $filename_without_ext_clean . "." . $extension,
					"filesize" => $file_info["size"][$i] / 1000,
					"tmp_filename" => $file_info["tmp_name"][$i]
				);
			}
		}

		print_r($filedata);
		exit;

		return $filedata;
	}
}
