<?php

namespace FormTools\Modules\FieldTypeFile;

use FormTools\Core;
use FormTools\FieldTypes;
use FormTools\Fields;
use FormTools\Files;
use FormTools\General;
use FormTools\Hooks as CoreHooks;
use FormTools\Submissions;
use PDO, Exception;


/**
 * Contains all the code executed on the various core hooks. See Module.class.php to show what methods get called for
 * which hook.
 */
class Hooks
{
	/**
	 * Our template hook. This includes all required JS for the Edit Submission page.
	 * @param $curr_page
	 */
	public static function includeJs($curr_page)
	{
		$root_url = Core::getRootUrl();
		if ($curr_page != "admin_edit_submission" && $curr_page != "client_edit_submission") {
			return;
		}
		echo "<script src=\"$root_url/modules/field_type_file/scripts/edit_submission.js\"></script>\n";
	}


	/**
	 * Used for any module (e.g. Form Builder) that uses the form fields in a standalone context.
	 */
	public static function includeStandaloneJs()
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
	 * @return array
	 */
	public static function processFormSubmissionHook($params)
	{
		$LANG = Core::$L;

		$file_fields = $params["file_fields"];
		if (empty($file_fields)) {
			return array(true, "");
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

			list($success, $message, $filename) = self::uploadSubmissionFile($form_id, $submission_id,
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
	 * @param $params
	 * @return array
	 */
	public static function apiProcessFormSubmissionHook($params)
	{
		$LANG = Core::$L;

		// if the form being submitted doesn't contain any form fields we do nothing
		$file_fields = $params["file_fields"];
		if (empty($file_fields)) {
			return array(true, "");
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

			list($success, $message, $filename) = self::uploadSubmissionFile($form_id, $submission_id, $file_field_info);
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
	 * @param $params
	 * @param $L
	 * @return array
	 */
	public static function deleteSubmissionHook($params, $L)
	{
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
	 * This is the hook for the Files::getUploadedFiles core function. It returns an array of hashes.
	 * @param $params
	 * @return array
	 */
	public static function getUploadedFilesHook($params)
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
	 * Handles all the actual work for uploading a file. Called by: FormTools\\Submissions::updateSubmission
	 * @param $params
	 * @return array
	 */
	public static function updateSubmissionHook($params)
	{
		$LANG = Core::$L;

		$file_fields = $params["file_fields"];

		if (empty($file_fields)) {
			return array(true, "");
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

			list($success, $message) = self::uploadSubmissionFile($form_id, $submission_id, $file_field_info);
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
	public static function uploadSubmissionFile($form_id, $submission_id, $file_field_info)
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

		$fileinfo = self::extractSingleFieldFileUploadData($is_multiple_files, $field_name, $_FILES);

		$final_file_upload_info = array();
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

			// check file extension is valid. Note: this is rather basic - it just tests for the file extension string,
			// not the actual file type based on its header info [this is done because I want to allow users to permit
			// uploading of any file types, and I can't know about all header types]
			$is_valid_extension = true;
			if (!empty($permitted_file_types)) {
				$is_valid_extension = false;
				$raw_extensions = explode(",", $permitted_file_types);

				foreach ($raw_extensions as $ext) {
					$clean_extension = str_replace(".", "", trim($ext)); // remove whitespace and periods

					if (preg_match("/$clean_extension$/i", $row["filename"])) {
						$is_valid_extension = true;
					}
				}
			}

			// not a valid extension - inform the user
			if (!$is_valid_extension) {
				$errors[] = $LANG["notify_unsupported_file_extension"]; // TODO error cleanup for MULTI
				continue;
			}

			$final_file_upload_info[] = array(
				"tmp_filename" => $row["tmp_filename"],
				"original_filename" => $row["filename"],
				"unique_filename" => Files::getUniqueFilename($file_upload_dir, $row["filename"])
			);
		}

		// find out if there was already a file/files uploaded in this field. For single file upload fields, uploading
		// a new file removes the old one. For files that allow multiple uploads, uploading a new file just appends it
		$submission_info = Submissions::getSubmissionInfo($form_id, $submission_id);
		$old_filename = (!empty($submission_info[$col_name])) ? $submission_info[$col_name] : "";

		if ($is_multiple_files === "no") {
			self::removeOldSubmissionFieldFiles($old_filename, $file_upload_dir);
		}

		$successfully_uploaded_files = array();
		$upload_file_errors = array();
		foreach ($final_file_upload_info as $row) {
			if (@rename($row["tmp_filename"], "$file_upload_dir/{$row["unique_filename"]}")) {
				@chmod("$file_upload_dir/{$row["unique_filename"]}", 0777);
				$successfully_uploaded_files[] = $row["unique_filename"];
			} else {
				$upload_file_errors[] = $row["original_filename"];
			}
		}

		// since we can upload multiple files at once, SOME may success, some may fail. The best behaviour is
		// to upload as much as we can, then list errors for any fields that failed

		if (!empty($successfully_uploaded_files)) {
			if ($is_multiple_files) {
				$existing_files = empty($old_filename) ? array() : explode(":", $old_filename);
				$new_files = $successfully_uploaded_files;
				$file_list = array_merge($existing_files, $new_files);
				$file_list_str = implode(":", $file_list);
			} else {
				$file_list_str = implode(":", $successfully_uploaded_files);
			}

			try {
				$db->query("
					UPDATE {PREFIX}form_{$form_id}
					SET    $col_name = :file_names
					WHERE  submission_id = :submission_id
				");
				$db->bindAll(array(
					"file_names" => $file_list_str,
					"submission_id" => $submission_id
				));
				$db->execute();

				// TODO
				return array(true, $LANG["notify_file_uploaded"], $file_list);
			} catch (Exception $e) {

				// TPODOP
				return array(false, $LANG["notify_file_not_uploaded"] . ": " . $e->getMessage());
			}
		} else {
			return array(false, $LANG["notify_file_not_uploaded"] . "..... TODO");
		}
	}


	/**
	 * Deletes a single file that has been uploaded through a form submission file field. This works for fields
	 * configured to allow single or multiple file uploads.
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
	public static function deleteFileSubmission($form_id, $submission_id, $field_id, $force_delete)
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

		extract(CoreHooks::processHookCalls("end", compact("form_id", "submission_id", "field_id", "force_delete"), array("success", "message")), EXTR_OVERWRITE);

		return array($success, $message);
	}


	// -----------------------------------------------------------------------------------------------------------------
	// helpers


	/**
	 * Returns an array of hashes. Each hash contains details about the file being uploaded; if there's a single file,
	 * the top level array contains a single hash.
	 * @param $is_multiple_files
	 * @param $field_name
	 * @param $files
	 * @return array
	 */
	private static function extractSingleFieldFileUploadData($is_multiple_files, $field_name, $files)
	{
		$file_info = $files[$field_name];

		// clean up the filename according to the whitelist chars
		$file_data = array();
		if ($is_multiple_files == "no") {
			$file_data[] = self::getSingleUploadedFileData($file_info["name"], $file_info["size"], $file_info["tmp_name"]);
		} else {
			$num_files = count($files[$field_name]["name"]);
			for ($i = 0; $i < $num_files; $i++) {
				$file_data[] = self::getSingleUploadedFileData($file_info["name"][$i], $file_info["size"][$i], $file_info["tmp_name"][$i]);
			}
		}

		return $file_data;
	}


	private static function getSingleUploadedFileData($filename, $file_size, $tmp_name)
	{
		$char_whitelist = Core::getFilenameCharWhitelist();
		$valid_chars = preg_quote($char_whitelist);

		$filename_parts = explode(".", $filename);
		$extension = $filename_parts[count($filename_parts) - 1];
		array_pop($filename_parts);
		$filename_without_extension = implode(".", $filename_parts);

		$filename_without_ext_clean = preg_replace("/[^$valid_chars]/", "", $filename_without_extension);
		if (empty($filename_without_ext_clean)) {
			$filename_without_ext_clean = "file";
		}

		return array(
			"filename" => $filename_without_ext_clean . "." . $extension,
			"filesize" => $file_size / 1000,
			"tmp_filename" => $tmp_name
		);
	}


	/**
	 * Removes any file(s) for a single form field.
	 *
	 * Called when uploading a new file into a field flagged with "is_multiple" = no. Generally a field with
	 * that configuration will only ever contain a single file, but the user may have just switched the field from
	 * multiple to single, so the field actually contains MULTIPLE filenames.
	 *
	 * @param $submission_field_value
	 * @param $file_upload_dir
	 */
	private static function removeOldSubmissionFieldFiles($submission_field_value, $file_upload_dir)
	{
		if (!empty($submission_field_value)) {
			return;
		}

		$files = explode(":", $submission_field_value);

		foreach ($files as $file) {
			if (file_exists("$file_upload_dir/$file")) {
				@unlink("$file_upload_dir/$file");
			}
		}
	}

}