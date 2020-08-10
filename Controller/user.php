<?php

require_once 'db.php';
require_once '../Model/Response.php';
require_once '../lib/cors.php';

// cors
$CORS = new Cors();
$CORS();

try {
    $writeDB = DB::connectWriteDB();
} catch (PDOException $e) {
    $response = new Response();
    $response->setHttpStatusCode(500);
    $response->setSuccess(false);
    $response->addMessage("Database connect error");
    $response->send();
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $response = new Response();
    $response->setHttpStatusCode(405);
    $response->setSuccess(false);
    $response->addMessage("Request method not allowed");
    $response->send();
    exit();
}

if ($_SERVER['CONTENT_TYPE'] !== 'application/json') {
    $response = new Response();
    $response->setHttpStatusCode(400);
    $response->setSuccess(false);
    $response->addMessage("Content type header not json");
    $response->send();
    exit();
}

$postData = file_get_contents('php://input');

if (!$jsonData = json_decode($postData)) {
    $response = new Response();
    $response->setHttpStatusCode(400);
    $response->setSuccess(false);
    $response->addMessage("Request body is not valid json");
    $response->send();
    exit();
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
        $response = new Response();
        $response->setHttpStatusCode(409);
        $response->setSuccess(false);
        $response->addMessage("Username already exists");
        $response->send();
        exit();
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
        $response = new Response();
        $response->setHttpStatusCode(500);
        $response->setSuccess(false);
        $response->addMessage("Failed to create user");
        $response->send();
        exit();
    }

    $lastUserID = $writeDB->lastInsertId();

    $returnData = array();
    $returnData['user_id'] = $lastUserID;
    $returnData['fullname'] = $fullname;
    $returnData['username'] = $username;

    $response = new Response();
    $response->setHttpStatusCode(201);
    $response->setSuccess(true);
    $response->addMessage("User created");
    $response->setData($returnData);
    $response->send();
    exit();

} catch (PDOException $e) {
    $response = new Response();
    $response->setHttpStatusCode(500);
    $response->setSuccess(false);
    $response->addMessage("Failed to create user");
    $response->send();
    exit();
}

