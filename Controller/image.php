<?php

require_once 'db.php';
require_once '../Model/Response.php';
require_once '../Model/Image.php';
require_once '../lib/cors.php';

// cors
$CORS = new Cors();
$CORS();

function sendResponse($status, $success, $message = null, $toCache = false, $data = null)  
{
    $response = new Response();
    $response->setHttpStatusCode($status);
    $response->setSuccess($success);
    ($message != null) ? $response->addMessage($message) : null;
    $response->toCache($toCache);
    ($data != null) ? $response->setData($data) : null;
    $response->send();
    exit();
}

function checkAuthStatusAndReturnUserID($writeDB) 
{
    if (!isset($_SERVER['HTTP_AUTHORIZATION']) || strlen($_SERVER['HTTP_AUTHORIZATION']) < 1) {
        $message = null;
        (!isset($_SERVER['HTTP_AUTHORIZATION'])) ? $message = "Access token is missing from the header" : null;
        sendResponse(401, false, $message);
    }

    $access_token = $_SERVER['HTTP_AUTHORIZATION'];

    try {
        $sql = 'SELECT user_id, access_token_expiry, useractive, loginattempts
                FROM sessions, users
                WHERE sessions.user_id = users.id
                AND access_token = :access_token';
        $query = $writeDB->prepare($sql);
        $query->bindParam(':access_token', $access_token, PDO::PARAM_STR);
        $query->execute();

        $rowCount = $query->rowCount();
        ($rowCount === 0) ? sendResponse(401, false, "Invalid access token") : null;

        $row = $query->fetch();
        $returned_userid = $row->user_id;
        $returned_accesstokenexpiry = $row->access_token_expiry;
        $returned_useractive = $row->useractive;
        $returned_loginattempts = $row->loginattempts;

        ($returned_useractive !== 'Y') ? sendResponse(401, false, "User account not active") : null;
        ($returned_loginattempts >= 3) ? sendResponse(401, false, "User account is currently locked") : null;
        (strtotime($returned_accesstokenexpiry) < time()) ? sendResponse(401, false, "Access token expired") : null;

        return $returned_userid;

    } catch (PDOException $e) {
        sendResponse(500, false, "Auth error");
    }
}

try {
    $writeDB = DB::connectWriteDB();
    $readDB = DB::connectreadDB();
} catch (PDOException $e) {
    sendResponse(500, false, "Database connect error");
}

var_dump($writeDB);
die();
$returned_userid = checkAuthStatusAndReturnUserID($writeDB);

// /tasks/1/images/1/attributes
if (array_key_exists("taskid", $_GET) && array_key_exists("imageid", $_GET) && array_key_exists("attributes", $_GET)) {
    $taskid = $_GET['taskid'];
    $imageid = $_GET['imageid'];
    $attributes = $_GET['attributes'];

    ($imageid == '' || !is_numeric($imageid) || $taskid == '' || !is_numeric($taskid)) ?
    sendResponse(400, false, "Image ID or Tsk ID can not be blank and must be number") : null;

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {

    }

    elseif ($_SERVER['REQUEST_METHOD'] === 'PATCH') {

    }

    else {
        sendResponse(405, false, "Request method not allowed");
    }
}

// /tasks/1/images/1
elseif (array_key_exists("taskid", $_GET) && array_key_exists("imageid", $_GET)) {
    $taskid = $_GET['taskid'];
    $imageid = $_GET['imageid'];

    ($imageid == '' || !is_numeric($imageid) || $taskid == '' || !is_numeric($taskid)) ?
    sendResponse(400, false, "Image ID or Tsk ID can not be blank and must be number") : null;
    

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {

    }

    elseif ($_SERVER['REQUEST_METHOD'] === 'DELETE') {

    }

    else {
        sendResponse(405, false, "Request method not allowed");
    }
}

// /task/1/images
elseif (array_key_exists("taskid", $_GET) && !array_key_exists("imageid", $_GET)) {
    $taskid = $_GET['taskid'];
    
    ($taskid == '' || !is_numeric($taskid)) ? 
    sendResponse(400, false, "Tsk ID can not be blank and must be number") : null;

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    } 
    
    else {
        sendResponse(405, false, "Request method not allowed");
    }
}

else {
    sendResponse(404, false, "Endpoint not found");
}