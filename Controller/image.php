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

function uploadImageRoute($readDB, $writeDB, $taskid, $returned_userid)
{
    try {
        
        if (!isset($_SERVER['CONTENT_TYPE']) || strpos($_SERVER['CONTENT_TYPE'], "multipart/form-data; boundary=") === false) {
            sendResponse(400, false, "Content type header not set to multipart/form-data with a boundary");
        }

        $sql = 'SELECT id FROM tasks 
                WHERE id = :taskid AND user_id = :userid';
        $query = $readDB->prepare($sql);
        $query->bindParam(':taskid', $taskid, PDO::PARAM_INT);
        $query->bindParam(':userid', $returned_userid, PDO::PARAM_INT);
        $query->execute();

        $rowCount = $query->rowCount();
        if ($rowCount === 0) {
            sendResponse(404, false, "Task Not found");
        }
        
        if (!isset($_POST['attributes'])) {
            sendResponse(400, false, "Attributes missing from body of request");
        }

        if (!$jsonImageAttributes = json_decode($_POST['attributes'])) {
            sendResponse(400, false, "Attributes field is not valid json");
        } 

        if (!isset($jsonImageAttributes->title) || !isset($jsonImageAttributes->filename) || $jsonImageAttributes->title == '' || $jsonImageAttributes->filename == '') {
            sendResponse(400, false, "Title and Filename fields error");
        }

        if (strpos($jsonImageAttributes->filename, ".") > 0) {
            sendResponse(400, false, "Filename dot err");
        }

        if (!isset($_FILES['imagefile']) || $_FILES['imagefile']['error'] !== 0) {
            sendResponse(500, false, "Image file upload failed");
        }

        $imageFileDetails = getimagesize($_FILES['imagefile']['tmp_name']);

        if (isset($_FILES['imagefile']['size']) && $_FILES['imagefile']['size'] > 5242880) {
            sendResponse(500, false, "File mush be under 5mb");
        }

        $allowedImageFileTypes = array('image/jpeg', 'image/gif', 'image/png');

        if (!in_array($imageFileDetails['mime'], $allowedImageFileTypes)) {
            sendResponse(400, false, "File type not supported");
        }

        $fileExtension = "";
        switch ($imageFileDetails['mime']) {
            case 'image/jpeg':
                $fileExtension = ".jpg";
                break;
            case 'image/gif':
                $fileExtension = ".gif";
                break;
            case 'image/png':
                $fileExtension = ".png";
                break;
            default:
                break;
        }

        if ($fileExtension == "") {
            sendResponse(400, false, "No valid file extension found for mime");
        }

        $image = new Image(null, $jsonImageAttributes->title, $jsonImageAttributes->filename.$fileExtension, $imageFileDetails['mime'], $taskid);

        $title = $image->getTitle();
        $newFileName = $image->getFilename();
        $mimetype = $image->getMimetype();

        $sql = 'SELECT images.id FROM images, tasks
                WHERE images.taskid = tasks.id
                AND tasks.id = :taskid
                AND tasks.user_id = :userid
                AND images.filename = :filename';
        $query = $readDB->prepare($sql);
        $query->bindParam(':taskid', $taskid, PDO::PARAM_INT);
        $query->bindParam(':userid', $returned_userid, PDO::PARAM_INT);
        $query->bindParam(':filename', $newFileName, PDO::PARAM_STR);
        $query->execute();

        $rowCount = $query->rowCount();
        ($rowCount !== 0) ? sendResponse(404, false, "already filename for this task") : null;

        // transaction
        $writeDB->beginTransaction();

        $sql = 'INSERT INTO images (title, filename, mimetype, taskid)
                VALUES (:title, :filename, :mimetype, :taskid)';
        $query = $writeDB->prepare($sql);
        $query->bindParam(':title', $title, PDO::PARAM_STR);
        $query->bindParam(':filename', $newFileName, PDO::PARAM_STR);
        $query->bindParam(':mimetype', $mimetype, PDO::PARAM_STR);
        $query->bindParam(':taskid', $taskid, PDO::PARAM_INT);
        $query->execute();

        $rowCount = $query->rowCount();
        if ($rowCount === 0) {
            if ($writeDB->inTransaction()) {
                $writeDB->rollBack();
            }
            sendResponse(404, false, "Failed to upload image");
        }
        
        $lastImageID = $writeDB->lastInsertId();

        $sql = 'SELECT images.id, images.title, images.filename, images.mimetype, images.taskid
                FROM images, tasks
                WHERE images.id = :imageid
                AND tasks.id = :taskid
                AND tasks.user_id = :userid
                AND images.taskid = tasks.id';
        $query = $writeDB->prepare($sql);
        $query->bindParam(':imageid', $lastImageID, PDO::PARAM_INT);
        $query->bindParam(':taskid', $taskid, PDO::PARAM_INT);
        $query->bindParam(':userid', $returned_userid, PDO::PARAM_INT);
        $query->execute();

        $rowCount = $query->rowCount();
        if ($rowCount === 0) {
            if ($writeDB->inTransaction()) {
                $writeDB->rollBack();
            }
            sendResponse(500, false, "Failed to get image attributes");
        }

        $imageArray = [];

        while($row = $query->fetch()) {
            $image = new Image($row->id, $row->title, $row->filename, $row->mimetype, $row->taskid);
            $imageArray[] = $image->returnImageAsArray();
        }

        $image->saveImageFile($_FILES['imagefile']['tmp_name']);

        $writeDB->commit();

        sendResponse(201, true, "Image uploaded", false, $imageArray);

    } catch (PDOException $e) {
        if ($writeDB->inTransaction()) {
            $writeDB->rollBack();
        }
        sendResponse(500, false, "Failed to upload the image");
    } catch (ImageException $e) {
        if ($writeDB->inTransaction()) {
            $writeDB->rollBack();
        }
        sendResponse(500, false, $e->getMessage());
    }
}

function getImageAttributesRoute($readDB, $taskid, $imageid, $returned_userid)
{
    try {
        
        $sql = 'SELECT images.id, images.title, images.filename, images.mimetype, images.taskid
                FROM images, tasks
                WHERE images.id = :imageid
                AND tasks.id = :taskid
                AND tasks.user_id = :userid
                AND images.taskid = tasks.id';
        $query = $readDB->prepare($sql);
        $query->bindParam(':imageid', $imageid, PDO::PARAM_INT);
        $query->bindParam(':taskid', $taskid, PDO::PARAM_INT);
        $query->bindParam(':userid', $returned_userid, PDO::PARAM_INT);
        $query->execute();

        $rowCount = $query->rowCount();
        if ($rowCount === 0) {
            sendResponse(404, false, "Image not found");
        }

        $imageArray = [];

        while($row = $query->fetch()) {
            $image = new Image($row->id, $row->title, $row->filename, $row->mimetype, $row->taskid);
            $imageArray[] = $image->returnImageAsArray();
        }

        sendResponse(200, true, null, false, $imageArray);

    } catch (ImageException $e) {
        sendResponse(500, false, $e->getMessage());
    } catch (PDOException $e) {
        sendResponse(500, false, "Failed to get image attributes");
    }
}

function getImageRoute($readDB, $taskid, $imageid, $returned_userid) 
{
    try {

        $sql = 'SELECT images.id, images.title, images.filename, images.mimetype, images.taskid
                FROM images, tasks
                WHERE images.id = :imageid
                AND tasks.id = :taskid
                AND tasks.user_id = :userid
                AND images.taskid = tasks.id';
        $query = $readDB->prepare($sql);
        $query->bindParam(':imageid', $imageid, PDO::PARAM_INT);
        $query->bindParam(':taskid', $taskid, PDO::PARAM_INT);
        $query->bindParam(':userid', $returned_userid, PDO::PARAM_INT);
        $query->execute();

        $rowCount = $query->rowCount();
        if ($rowCount === 0) {
            sendResponse(404, false, "Image not found");
        }

        $image = null;

        while($row = $query->fetch()) {
            $image = new Image($row->id, $row->title, $row->filename, $row->mimetype, $row->taskid);
        }

        if ($image == null) {
            sendResponse(500, false, "Image not found");
        }

        $image->returnImageFile();
        
    } catch (ImageException $e) {
        sendResponse(500, false, $e->getMessage());
    } catch (PDOException $e) {
        sendResponse(500, false, "Failed getting image");
    }
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
        if ($rowCount === 0) {
            sendResponse(401, false, "Invalid access token");
        }

        $row = $query->fetch();
        $returned_userid = $row->user_id;
        $returned_accesstokenexpiry = $row->access_token_expiry;
        $returned_useractive = $row->useractive;
        $returned_loginattempts = $row->loginattempts;

        if ($returned_useractive !== 'Y') {
            sendResponse(401, false, "User account not active");
        } 

        if ($returned_loginattempts >= 3) {
            sendResponse(401, false, "User account is currently locked");
        }
        
        if (strtotime($returned_accesstokenexpiry) < time()) {
            sendResponse(401, false, "Access token expired");
        } 

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

$returned_userid = checkAuthStatusAndReturnUserID($writeDB);

// /tasks/1/images/1/attributes
if (array_key_exists("taskid", $_GET) && array_key_exists("imageid", $_GET) && array_key_exists("attributes", $_GET)) {
    $taskid = $_GET['taskid'];
    $imageid = $_GET['imageid'];
    $attributes = $_GET['attributes'];

    if ($imageid == '' || !is_numeric($imageid) || $taskid == '' || !is_numeric($taskid)) {
        sendResponse(400, false, "Image ID or Tsk ID can not be blank and must be number");
    }

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        getImageAttributesRoute($readDB, $taskid, $imageid, $returned_userid);
    } elseif ($_SERVER['REQUEST_METHOD'] === 'PATCH') {

    } else {
        sendResponse(405, false, "Request method not allowed");
    }
}

// /tasks/1/images/1
elseif (array_key_exists("taskid", $_GET) && array_key_exists("imageid", $_GET)) {
    $taskid = $_GET['taskid'];
    $imageid = $_GET['imageid'];

    if ($imageid == '' || !is_numeric($imageid) || $taskid == '' || !is_numeric($taskid)) {
        sendResponse(400, false, "Image ID or Tsk ID can not be blank and must be number");
    }
    
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        getImageRoute($readDB, $taskid, $imageid, $returned_userid);
    } elseif ($_SERVER['REQUEST_METHOD'] === 'DELETE') {

    } else {
        sendResponse(405, false, "Request method not allowed");
    }
}

// /task/1/images
elseif (array_key_exists("taskid", $_GET) && !array_key_exists("imageid", $_GET)) {
    $taskid = $_GET['taskid'];
    
    if ($taskid == '' || !is_numeric($taskid)) {
        sendResponse(400, false, "Tsk ID can not be blank and must be number");
    } 

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        uploadImageRoute($readDB, $writeDB, $taskid, $returned_userid);
    } else {
        sendResponse(405, false, "Request method not allowed");
    }
}

else {
    sendResponse(404, false, "Endpoint not found");
}