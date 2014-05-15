<?php

require_once("../../global/session_start.php");
require_once(dirname(__FILE__) . "/library.php");
ft_check_permission("user");

$request = array_merge($_POST, $_GET);

$return_str = "";
if (isset($request["return_vars"]))
{
  $vals = array();
  while (list($key, $value) = each($request["return_vars"]))
  {
    $vals[] = "\"$key\": \"$value\"";
  }
  $return_str = ", " . implode(", ", $vals);
}


switch ($request["action"])
{
  // called by the administrator or client on the Edit Submission page. Note that we pull the submission ID
  // and the form ID from sessions rather than have them explictly passed by the JS. This is a security precaution -
  // it prevents a potential hacker exploiting this function here. Instead they'd have to set the sessions by another
  // route which is trickier
  case "delete_submission_file":
    $form_id       = $request["form_id"];
    $submission_id = $request["submission_id"];
    $field_id      = $request["field_id"];
    $force_delete  = ($request["force_delete"] == "true") ? true : false;

    // TODO beef up the security here. Check that the person logged in is permitted to see this submission & field...

    list($success, $message) = ft_file_delete_file_submission($form_id, $submission_id, $field_id, $force_delete);
    $success = ($success) ? 1 : 0;
    $message = ft_sanitize($message);
    $message = preg_replace("/\\\'/", "'", $message);
    echo "{ \"success\": \"$success\", \"message\": \"$message\" {$return_str} }";
    break;
}