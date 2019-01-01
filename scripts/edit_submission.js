/**
 * Contains all JS for the file upload module.
 */

$(function () {

	var updateDeleteSelectedBtn = function (group, enabled) {
		if (enabled) {
			$(group).find(".cf_file_delete_selected").removeAttr("disabled");
		} else {
			$(group).find(".cf_file_delete_selected").attr("disabled", "disabled");
		}
	};

	$(".cf_delete_file,.cf_file_delete_selected").each(function () {
		var group = $(this).closest(".cf_file");
		var field_id = group.find(".cf_file_field_id").val();

		$(this).bind("click", function () {
			var files = [];
			group.find(".cf_file_row_cb:checked").each(function () {
				files.push($(this).val());
			});
			return files_ns.delete_submission_files(field_id, files, false);
		});
	});

	$(".cf_file_toggle_all").each(function () {
		$(this).bind("click", function (e) {
			var group = $(this).closest(".cf_file");
			var cbs = group.find(".cf_file_row_cb");
			cbs.each(function () {
				this.checked = e.target.checked;
			});
			updateDeleteSelectedBtn(group, e.target.checked);
		});
	});

	$(".cf_file_row_cb").bind("click", function () {
		var group = $(this).closest(".cf_file");

		var num_checked = 0;
		var num_unchecked = 0;
		$(group).find(".cf_file_row_cb").each(function () {
			if (this.checked) {
				num_checked++;
			} else {
				num_unchecked++;
			}
		});

		if (num_checked > 0 && num_unchecked === 0) {
			$(group).find(".cf_file_toggle_all").attr("checked", "checked");
		} else {
			$(group).find(".cf_file_toggle_all").removeAttr("checked");
		}

		updateDeleteSelectedBtn(group, num_checked > 0);
	});
});


// ------------------------------------------------------------------------------------------------

var files_ns = {};
files_ns.confirm_delete_dialog = $("<div id=\"confirm_delete_dialog\"></div>");


/**
 * Checks the file field has a value in it. This is used instead of the default RSV "required" rule
 * because if a file's already uploaded, it needs to pass validation.
 */
files_ns.check_required = function () {
	var errors = [];
	for (var i = 0; i < rsv_custom_func_errors.length; i++) {
		if (rsv_custom_func_errors[i].func == "files_ns.check_required") {
			var field = document.edit_submission_form[rsv_custom_func_errors[i].field];
			var field_id = rsv_custom_func_errors[i].field_id;
			var has_file = ($("#cf_file_" + field_id + "_content").css("display") == "block" && $("#cf_file_" + field_id + "_content").html() != "");
			if (!has_file && !field.value) {
				errors.push([field, rsv_custom_func_errors[i].err]);
			}
		}
	}
	if (errors.length) {
		return errors;
	}
	return true;
};


/**
 * Deletes a submission file.
 *
 * @param field_id
 * @param force_delete boolean
 */
files_ns.delete_submission_files = function (field_id, files, force_delete) {
	var page_url = g.root_url + "/modules/field_type_file/actions.php";

	var data = {
		action: "delete_submission_files",
		field_id: field_id,
		files: files,
		form_id: $("#form_id").val(),
		submission_id: $("#submission_id").val(),
		return_vars: { target_message_id: "file_field_" + field_id + "_message_id", field_id: field_id },
		force_delete: force_delete
	};

	if (!force_delete) {
		ft.create_dialog({
			dialog: files_ns.confirm_delete_dialog,
			popup_type: "warning",
			title: g.messages["phrase_please_confirm"],
			content: g.messages["confirm_delete_submission_file"],
			buttons: [{
				"text": g.messages["word_yes"],
				"click": function () {
					ft.dialog_activity_icon($("#confirm_delete_dialog"), "show");
					$.ajax({
						url: page_url,
						data: data,
						type: "GET",
						dataType: "json",
						success: files_ns.delete_submission_file_response,
						error: ft.error_handler
					});
				}
			},
				{
					"text": g.messages["word_no"],
					"click": function () {
						$(this).dialog("close");
					}
				}]
		});
	} else {
		$.ajax({
			url: page_url,
			data: data,
			type: "GET",
			dataType: "json",
			success: files_ns.delete_submission_file_response,
			error: ft.error_handler
		});
	}

	return false;
}


/**
 * Handles the successful responses for the delete file feature. Whether or not the file was *actually*
 * deleted is a separate matter. If the file couldn't be deleted, the user is provided the option of updating
 * the database record to just remove the reference.
 */
files_ns.delete_submission_file_response = function (data) {
	ft.dialog_activity_icon($("#confirm_delete_dialog"), "hide");
	$("#confirm_delete_dialog").dialog("close");

	// if it was a success, remove the link from the page
	if (data.success == 1) {
		var field_id = data.field_id;
		$("#cf_file_" + field_id + "_content").html("");
		$("#cf_file_" + field_id + "_no_content").show();
	}

	ft.display_message(data.target_message_id, data.success, data.message);
}
