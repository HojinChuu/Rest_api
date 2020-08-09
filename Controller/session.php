<?php

require_once 'db.php';
require_once '../Model/Response.php';

try {
    $writeDB = DB::connectWriteDB();
} catch (PDOException $e) {
    error_log("Connection error : {$e}", 0);
    $response = new Response();
    $response->setHttpStatusCode(500);
    $response->setSuccess(false);
    $response->addMessage("Database connect error");
    $response->send();
    exit();
}

if (array_key_exists("sessionid", $_GET)) {
    
    $session_id = $_GET['sessionid'];

    if ($session_id === '' || !is_numeric($session_id)) {
        $response = new Response();
        $response->setHttpStatusCode(400);
        $response->setSuccess(false);
        $session_id === '' ? $response->addMessage("SessionID blank error") : false;
        !is_numeric($session_id) ? $response->addMessage("SessionID must be type integer") : false;
        $response->send();
        exit();
    }

    if (!isset($_SERVER['HTTP_AUTHORIZATION']) || strlen($_SERVER['HTTP_AUTHORIZATION']) < 1) {
        $response = new Response();
        $response->setHttpStatusCode(401);
        $response->setSuccess(false);
        $response->addMessage("Access token missing");
        $response->send();
        exit();
    }

    $access_token = $_SERVER['HTTP_AUTHORIZATION'];

    if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
        try {
            $sql = 'DELETE FROM sessions 
                    WHERE id = :session_id 
                    AND access_token = :access_token';
            $query = $writeDB->prepare($sql);
            $query->bindParam(':session_id', $session_id, PDO::PARAM_INT);
            $query->bindParam(':access_token', $access_token, PDO::PARAM_STR);
            $query->execute();

            $rowCount = $query->rowCount();
            if ($rowCount === 0) {
                $response = new Response();
                $response->setHttpStatusCode(400);
                $response->setSuccess(false);
                $response->addMessage("Failed to logout");
                $response->send();
                exit();
            }

            $returnData = array();
            $returnData['session_id'] = intval($session_id);

            $response = new Response();
            $response->setHttpStatusCode(200);
            $response->setSuccess(true);
            $response->addMessage("Logged Out");
            $response->setData($returnData);
            $response->send();
            exit();

        } catch (PDOException $e) {
            $response = new Response();
            $response->setHttpStatusCode(500);
            $response->setSuccess(false);
            $response->addMessage("There was an issue login");
            $response->send();
            exit();
        }
    } 

    else if ($_SERVER['REQUEST_METHOD'] === 'PATCH') {
        
        if ($_SERVER['CONTENT_TYPE'] !== 'application/json') {
            $response = new Response();
            $response->setHttpStatusCode(400);
            $response->setSuccess(false);
            $response->addMessage("Content type header not json");
            $response->send();
            exit();
        }

        $patchData = file_get_contents('php://input');

        if (!$jsonData = json_decode($patchData)) {
            $response = new Response();
            $response->setHttpStatusCode(400);
            $response->setSuccess(false);
            $response->addMessage("Request is not valid json");
            $response->send();
            exit();
        }

        if (!isset($jsonData->refresh_token) || strlen($jsonData->refresh_token) < 1) {
            $response = new Response();
            $response->setHttpStatusCode(400);
            $response->setSuccess(false);
            !isset($jsonData->refresh_token) ? $response->addMessage("Refresh token not supplied") : false;
            strlen($jsonData->refresh_token) < 1 ? $response->addMessage("Refresh token cannot be blank") : false;
            $response->send();
            exit();
        }

        try {
            $refresh_token = $jsonData->refresh_token;

            $sql = 'SELECT sessions.id as session_id, 
                            sessions.user_id as userid, 
                            access_token, refresh_token, useractive, loginattempts, 
                            access_token_expiry, refresh_token_expiry 
                    FROM sessions, users
                    WHERE users.id = sessions.user_id 
                    AND sessions.id = :session_id
                    AND sessions.access_token = :access_token
                    AND sessions.refresh_token = :refresh_token';
            $query = $writeDB->prepare($sql);
            $query->bindParam(':session_id', $session_id, PDO::PARAM_INT);
            $query->bindParam(':access_token', $access_token, PDO::PARAM_STR);
            $query->bindParam(':refresh_token', $refresh_token, PDO::PARAM_STR);
            $query->execute();

            $rowCount = $query->rowCount();
            if ($rowCount === 0) {
                $response = new Response();
                $response->setHttpStatusCode(401);
                $response->setSuccess(false);
                $response->addMessage("Access token or refresh token is incorrect");
                $response->send();
                exit();
            }

            $row = $query->fetch();

            $returned_sessionid = $row->session_id;
            $returned_userid = $row->userid;
            $returned_accesstoken = $row->access_token;
            $returned_refreshtoken = $row->refresh_token;
            $returned_useractive = $row->useractive;
            $returned_loginattempts = $row->loginattempts;
            $returned_accesstokenexpiry = $row->access_token_expiry;
            $returned_refreshtokenexpiry = $row->refresh_token_expiry;

            if ($returned_useractive !== 'Y') {
                $response = new Response();
                $response->setHttpStatusCode(401);
                $response->setSuccess(false);
                $response->addMessage("User account is not active");
                $response->send();
                exit();
            }

            if ($returned_loginattempts >= 3) {
                $response = new Response();
                $response->setHttpStatusCode(401);
                $response->setSuccess(false);
                $response->addMessage("User account is currently locked");
                $response->send();
                exit();
            }

            if (strtotime($returned_refreshtokenexpiry) < time()) {
                $response = new Response();
                $response->setHttpStatusCode(401);
                $response->setSuccess(false);
                $response->addMessage("Refresh token has expired");
                $response->send();
                exit();
            }

            $access_token = base64_encode(bin2hex(openssl_random_pseudo_bytes(24)).time());  
            $refresh_token = base64_encode(bin2hex(openssl_random_pseudo_bytes(24)).time());  

            $access_token_expiry_seconds = 1200;
            $refresh_token_expiry_seconds = 1209600; // 14 day

            $sql = 'UPDATE sessions SET access_token = :access_token, access_token_expiry = date_add(NOW(), INTERVAL :access_token_expiry_seconds SECOND), refresh_token = :refresh_token, refresh_token_expiry = date_add(NOW(), INTERVAL :refresh_token_expiry_seconds SECOND) 
                    WHERE id = :session_id
                    AND user_id = :user_id
                    AND access_token = :returned_access_token
                    AND refresh_token = :returned_refresh_token';
            $query = $writeDB->prepare($sql);
            $query->bindParam(':user_id', $returned_userid, PDO::PARAM_INT);
            $query->bindParam(':session_id', $returned_sessionid, PDO::PARAM_INT);
            $query->bindParam(':access_token', $access_token, PDO::PARAM_STR);
            $query->bindParam(':access_token_expiry_seconds', $access_token_expiry_seconds, PDO::PARAM_INT);
            $query->bindParam(':refresh_token', $refresh_token, PDO::PARAM_STR);
            $query->bindParam(':refresh_token_expiry_seconds', $refresh_token_expiry_seconds, PDO::PARAM_INT);
            $query->bindParam(':returned_access_token', $returned_accesstoken, PDO::PARAM_STR);
            $query->bindParam(':returned_refresh_token', $returned_refreshtoken, PDO::PARAM_STR);
            $query->execute();

            $rowCount = $query->rowCount();
            if ($rowCount === 0) {
                $response = new Response();
                $response->setHttpStatusCode(401);
                $response->setSuccess(false);
                $response->addMessage("Access token could not be refreshed");
                $response->send();
                exit();
            }

            $returnData = array();
            $returnData['session_id'] = $returned_sessionid;
            $returnData['access_token'] = $access_token;
            $returnData['access_token_expiry'] = $access_token_expiry_seconds;
            $returnData['refresh_token'] = $refresh_token;
            $returnData['refresh_token_expiry'] = $refresh_token_expiry_seconds;

            $response = new Response();
            $response->setHttpStatusCode(200);
            $response->setSuccess(true);
            $response->addMessage("Token refreshed");
            $response->setData($returnData);
            $response->send();
            exit();

        } catch (PDOException $e) {
            $response = new Response();
            $response->setHttpStatusCode(500);
            $response->setSuccess(false);
            $response->addMessage("There was an issue refreshing access token");
            $response->send();
            exit();
        }
    }

    else {
        $response = new Response();
        $response->setHttpStatusCode(405);
        $response->setSuccess(false);
        $response->addMessage("Request method not allowed");
        $response->send();
        exit();
    }
} 

// login
else if (empty($_GET)) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        $response = new Response();
        $response->setHttpStatusCode(405);
        $response->setSuccess(false);
        $response->addMessage("Request method not allowed");
        $response->send();
        exit(); 
    }

    // attack protected
    sleep(1);

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

    if (!isset($jsonData->username) || !isset($jsonData->password)) {
        $response = new Response();
        $response->setHttpStatusCode(400);
        $response->setSuccess(false);
        !isset($jsonData->username) ? $response->addMessage("Username not supplied") : false;
        !isset($jsonData->password) ? $response->addMessage("Password not supplied") : false;
        $response->send();
        exit();
    }

    if (strlen($jsonData->username) < 1 || strlen($jsonData->username) > 255 || strlen($jsonData->password) < 1 || strlen($jsonData->password) > 255) {
        $response = new Response();
        $response->setHttpStatusCode(400);
        $response->setSuccess(false);
        strlen($jsonData->username) < 1 ? $response->addMessage("Username can not be blank") : false;
        strlen($jsonData->password) < 1 ? $response->addMessage("Password can not be blank") : false;
        strlen($jsonData->username) > 255 ? $response->addMessage("Username can not more than 255 characters") : false;
        strlen($jsonData->password) > 255 ? $response->addMessage("Password can not more than 255 characters") : false;
        $response->send();
        exit();
    }

    try {
        $username = $jsonData->username;
        $password = $jsonData->password;

        $sql = 'SELECT id, fullname, username, password, useractive, loginattempts
                FROM users
                WHERE username = :username';
        $query = $writeDB->prepare($sql);
        $query->bindParam(':username', $username, PDO::PARAM_STR);
        $query->execute();

        $rowCount = $query->rowCount();

        if ($rowCount === 0) {
            $response = new Response();
            $response->setHttpStatusCode(401);
            $response->setSuccess(false);
            $response->addMessage("user not exist");
            $response->send();
            exit();
        }

        // unique
        $row = $query->fetch();

        $returned_id = $row->id;
        $returned_fullname = $row->fullname;
        $returned_username = $row->username;
        $returned_password = $row->password;
        $returned_useractive = $row->useractive;
        $returned_loginattempts = $row->loginattempts;

        if ($returned_useractive !== 'Y') {
            $response = new Response();
            $response->setHttpStatusCode(401);
            $response->setSuccess(false);
            $response->addMessage("User account not active");
            $response->send();
            exit();
        }

        // login 3번이상 실패시 locked
        if ($returned_loginattempts >= 3) {
            $response = new Response();
            $response->setHttpStatusCode(401);
            $response->setSuccess(false);
            $response->addMessage("User account is currently locked");
            $response->send();
            exit();
        }

        if (!password_verify($password, $returned_password)) {
            $sql = 'UPDATE users SET loginattempts = loginattempts+1 WHERE id = :id';
            $query = $writeDB->prepare($sql);
            $query->bindParam(':id', $returned_id, PDO::PARAM_INT);
            $query->execute();

            $response = new Response();
            $response->setHttpStatusCode(401);
            $response->setSuccess(false);
            $response->addMessage("Username or Password is incorrect");
            $response->send();
            exit();
        }

        // randome key + time
        $access_token = base64_encode(bin2hex(openssl_random_pseudo_bytes(24)).time());
        $refresh_token = base64_encode(bin2hex(openssl_random_pseudo_bytes(24)).time());

        $access_token_expiry_seconds = 1200;
        $refresh_token_expiry_seconds = 1209600; // 14 day

    } catch (PDOException $e) {
        $response = new Response();
        $response->setHttpStatusCode(500);
        $response->setSuccess(false);
        $response->addMessage("There was an issue login");
        $response->send();
        exit();
    }

    try {
        // 트랜잭션 ( 두 테이블 간 일관성 )
        $writeDB->beginTransaction();

        $sql = 'UPDATE users SET loginattempts = 0 WHERE id = :id';
        $query = $writeDB->prepare($sql);
        $query->bindParam(':id', $returned_id, PDO::PARAM_INT);
        $query->execute();

        $sql = 'INSERT INTO sessions (user_id, access_token, access_token_expiry, refresh_token, refresh_token_expiry) 
                VALUES (:user_id, :access_token, date_add(NOW(), INTERVAL :access_token_expiry_seconds SECOND), :refresh_token, date_add(NOW(), INTERVAL :refresh_token_expiry_seconds SECOND))';
        $query = $writeDB->prepare($sql);
        $query->bindParam(':user_id', $returned_id, PDO::PARAM_INT);
        $query->bindParam(':access_token', $access_token, PDO::PARAM_STR);
        $query->bindParam(':access_token_expiry_seconds', $access_token_expiry_seconds, PDO::PARAM_INT);
        $query->bindParam(':refresh_token', $refresh_token, PDO::PARAM_STR);
        $query->bindParam(':refresh_token_expiry_seconds', $refresh_token_expiry_seconds, PDO::PARAM_INT);
        $query->execute();

        $lastSessionID = $writeDB->lastInsertId();

        $writeDB->commit();

        $returnData = array();
        $returnData['session_id'] = intval($lastSessionID);
        $returnData['access_token'] = $access_token;
        $returnData['access_token_expires_in'] = $access_token_expiry_seconds;
        $returnData['refresh_token'] = $refresh_token;
        $returnData['refresh_token_expires_in'] = $refresh_token_expiry_seconds;

        $response = new Response();
        $response->setHttpStatusCode(201);
        $response->setSuccess(true);
        $response->setData($returnData);
        $response->send();
        exit();

    } catch (PDOException $e) {
        $writeDB->rollBack();
        $response = new Response();
        $response->setHttpStatusCode(500);
        $response->setSuccess(false);
        $response->addMessage("There was an issue login");
        $response->send();
        exit();
    }
}

else {
    $response = new Response();
    $response->setHttpStatusCode(404);
    $response->setSuccess(false);
    $response->addMessage("EndPoint not found");
    $response->send();
    exit(); 
}