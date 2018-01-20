<?php

require_once("../../global/library.php");

use FormTools\Modules;


switch ($request["action"]) {

    // called by the administrator or client on the Edit Submission page. Note that we pull the submission ID
    // and the form ID from sessions rather than have them explictly passed by the JS. This is a security precaution -
    // it prevents a potential hacker exploiting this function here. Instead they'd have to set the sessions by another
    // route which is trickier
    case "delete_submission_file":
        $module = Modules::initModulePage("client");

        $form_id       = $request["form_id"];
        $submission_id = $request["submission_id"];
        $field_id      = $request["field_id"];
        $force_delete  = ($request["force_delete"] == "true") ? true : false;

        // TODO beef up the security here. Check that the person logged in is permitted to see this submission & field...

        list ($success, $message) = $module->deleteFileSubmission($form_id, $submission_id, $field_id, $force_delete);
        output_json_with_return_vars(array(
            "success" => ($success) ? 1 : 0,
            "message" => $message
        ));
        break;

    // this is called when the field type is being used in the Form Builder. This is just slightly more restrictive than
    // the logged-in context: it pulls the form ID and submission ID from sessions instead of from the page (which could
    // be hacked)
    case "delete_submission_file_standalone":
        $module = Modules::initModulePage();

        $published_form_id = (isset($request["published_form_id"])) ? $request["published_form_id"] : "";

        if (empty($published_form_id)) {
            output_json_with_return_vars(array(
                "success" => 0,
                "message" => "Your form is missing the form_tools_published_form_id ID field."
            ));
            exit;
        }
        $form_id       = $_SESSION["form_builder_{$published_form_id}"]["form_tools_form_id"];
        $submission_id = $_SESSION["form_builder_{$published_form_id}"]["form_tools_submission_id"];
        $field_id      = $request["field_id"];
        $force_delete  = ($request["force_delete"] == "true") ? true : false;

        list ($success, $message) = $module->deleteFileSubmission($form_id, $submission_id, $field_id, $force_delete);
        output_json_with_return_vars(array(
            "success" => 1,
            "message" => $message
        ));
        break;
}


function output_json_with_return_vars($data)
{
    global $request;
    echo json_encode(array_merge($request["return_vars"], $data));
}
