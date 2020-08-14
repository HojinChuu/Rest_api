<?php

require_once 'db.php';
require_once '../Model/Response.php';
require_once '../lib/cors.php';
require_once '../lib/Send.php';

// cors
$CORS = new Cors();
$CORS();

try {
    $writeDB = DB::connectWriteDB();
} catch (PDOException $e) {
    Send::sendResponse(500, false, "Database connect error");
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Send::sendResponse(405, false, "Request method not allowed");
}

if ($_SERVER['CONTENT_TYPE'] !== 'application/json') {
    Send::sendResponse(400, false, "Content type header not json");
}

$postData = file_get_contents('php://input');

if (!$jsonData = json_decode($postData)) {
    Send::sendResponse(400, false, "Request body is not valid json");
}

if (!isset($jsonData->fullname) || !isset($jsonData->username) || !isset($jsonData->password)) {
    $response = new Response();
    $response->setHttpStatusCode(400);
    $response->setSuccess(false);
    !isset($jsonData->fullname) ? $response->addMessage("Full name not supplied") : false;
    !isset($jsonData->username) ? $response->addMessage("Username not supplied") : false;
    !isset($jsonData->password) ? $response->addMessage("Password name not supplied") : false;
    $response->send();
    exit();
}

if (strlen($jsonData->fullname) < 1 || strlen($jsonData->fullname) > 255 || strlen($jsonData->username) < 1 || strlen($jsonData->username) > 255 || strlen($jsonData->password) < 1 || strlen($jsonData->password) > 255) {
    $response = new Response();
    $response->setHttpStatusCode(400);
    $response->setSuccess(false);
    strlen($jsonData->fullname) < 1 ? $response->addMessage("Full name can not be blank") : false;
    strlen($jsonData->username) < 1 ? $response->addMessage("Username can not be blank") : false;
    strlen($jsonData->password) < 1 ? $response->addMessage("Password can not be blank") : false;
    strlen($jsonData->fullname) > 255 ? $response->addMessage("Full name can not more than 255 characters") : false;
    strlen($jsonData->username) > 255 ? $response->addMessage("Username can not more than 255 characters") : false;
    strlen($jsonData->password) > 255 ? $response->addMessage("Password can not more than 255 characters") : false;
    $response->send();
    exit();
}

$fullname = trim($jsonData->fullname);
$username = trim($jsonData->username);
$password = $jsonData->password;

try {
    $sql = 'SELECT id FROM users WHERE username = :username';
    $query = $writeDB->prepare($sql);
    $query->bindParam(':username', $username, PDO::PARAM_STR);
    $query->execute();

    $rowCount = $query->rowCount();
    if ($rowCount !== 0) {
        Send::sendResponse(409, false, "Username already exists");
    }

    $hashed_password = password_hash($password, PASSWORD_DEFAULT);

    $sql = 'INSERT INTO users (fullname, username, password) VALUES (:fullname, :username, :password)';
    $query = $writeDB->prepare($sql);
    $query->bindParam(':fullname', $fullname, PDO::PARAM_STR);
    $query->bindParam(':username', $username, PDO::PARAM_STR);
    $query->bindParam(':password', $hashed_password, PDO::PARAM_STR);
    $query->execute();

    $rowCount = $query->rowCount();
    if ($rowCount === 0) {
        Send::sendResponse(500, false, "Failed to create user");
    }

    $lastUserID = $writeDB->lastInsertId();

    $returnData = array();
    $returnData['user_id'] = $lastUserID;
    $returnData['fullname'] = $fullname;
    $returnData['username'] = $username;

    Send::sendResponse(201, true, "User created", false, $returnData);

} catch (PDOException $e) {
    Send::sendResponse(500, false, "Failed to create user");
}

