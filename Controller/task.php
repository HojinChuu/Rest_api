<?php

require_once 'db.php';
require_once '../Model/Task.php';
require_once '../Model/Image.php';
require_once '../Model/Response.php';
require_once '../lib/cors.php';
require_once '../lib/Send.php';

// cors
$CORS = new Cors();
$CORS();

function searchTaskImages($dbConn, $taskid, $returned_userid)  
{
    $sql = 'SELECT images.id, images.title, images.filename, images.mimetype, images.taskid
            FROM images, tasks
            WHERE tasks.id = :taskid
            AND tasks.user_id = :userid
            AND tasks.id = images.taskid';
    $imageQuery = $dbConn->prepare($sql);
    $imageQuery->bindParam(':taskid', $taskid, PDO::PARAM_INT);
    $imageQuery->bindParam(':userid', $returned_userid, PDO::PARAM_INT);
    $imageQuery->execute();

    $imageArray = array();

    while($imageRow = $imageQuery->fetch()) {
        $image = new Image($imageRow->id, $imageRow->title, $imageRow->filename, $imageRow->mimetype, $imageRow->taksid);
        $imageArray[] = $image->returnImageAsArray();
    }

    return $imageArray;
}

try {
    $writeDB = DB::connectWriteDB();
    $readDB = DB::connectreadDB();
} catch (PDOException $e) {
    Send::sendResponse(500, false, "Database connect error");
}

// AUTH check
if (!isset($_SERVER['HTTP_AUTHORIZATION']) || strlen($_SERVER['HTTP_AUTHORIZATION']) < 1) {
    $response = new Response();
    $response->setHttpStatusCode(401);
    $response->setSuccess(false);
    !isset($_SERVER['HTTP_AUTHORIZATION']) ? $response->addMessage("Access token is missing from header") : false;
    // strlen($_SERVER['HTTP_AUTHORIZATION']) < 1 ? $response->addMessage("Access token cannot be blank") : false;
    $response->send();
    exit();
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
        Send::sendResponse(401, false, "Invalid access token");
    }

    $row = $query->fetch();
    $returned_userid = $row->user_id;
    $returned_accesstokenexpiry = $row->access_token_expiry;
    $returned_useractive = $row->useractive;
    $returned_loginattempts = $row->loginattempts;

    if ($returned_useractive !== 'Y') {
        Send::sendResponse(401, false, "User account not active");
    }

    if ($returned_loginattempts >= 3) {
        Send::sendResponse(401, false, "User account is currently locked");
    }

    if (strtotime($returned_accesstokenexpiry) < time()) {
        Send::sendResponse(401, false, "Access token expired");
    }

} catch (PDOException $e) {
    Send::sendResponse(500, false, "Auth error");
}
// END AUTH logic

// URL request taskid
if (array_key_exists("taskid", $_GET)) {
    $taskId = $_GET['taskid'];

    if ($taskId == '' || !is_numeric($taskId)) {
        Send::sendResponse(400, false, "Task ID can not be blank or must be numeric");
    }

    // GET REQUEST
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        try {
            $sql = 'SELECT id, title, description, DATE_FORMAT(deadline, "%d/%m/%Y %H:%i") AS deadline, completed 
                    FROM tasks 
                    WHERE id = :taskid
                    AND user_id = :user_id';
            $query = $readDB->prepare($sql); 
            $query->bindParam(':taskid', $taskId, PDO::PARAM_INT);
            $query->bindParam(':user_id', $returned_userid, PDO::PARAM_INT);
            $query->execute();

            $rowCount = $query->rowCount();
            if ($rowCount === 0) {
                Send::sendResponse(404, false, "Task not found");
            }

            while($row = $query->fetch()) {
                $imageArray = searchTaskImages($readDB, $taskId, $returned_userid);
                $task = new Task($row->id, $row->title, $row->description, $row->deadline, $row->completed, $imageArray);
                $taskArray[] = $task->returnTaskArray();
            }

            $returnData = array();
            $returnData['rows_returned'] = $rowCount;
            $returnData['tasks'] = $taskArray;
            
            Send::sendResponse(200, true, null, false, $returnData);

        } catch (ImageException $e) {
            Send::sendResponse(500, false, $e->getMessage());
        } catch (TaskException $e) {
            Send::sendResponse(500, false, $e->getMessage());
        } catch (PDOException $e) {
            Send::sendResponse(500, false, "Failed to get Task");
        }
    } 

    // DELETE REQUEST
    else if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
        try {

            $sql = 'SELECT images.id, images.title, images.filename, images.mimetype, images.taskid
                    FROM images, tasks
                    WHERE tasks.id = :taskid
                    AND tasks.user_id = :userid
                    AND images.taskid = tasks.id';
            $imageSelectQuery = $readDB->prepare($sql);
            $imageSelectQuery->bindParam(':taskid', $taskId, PDO::PARAM_INT);
            $imageSelectQuery->bindParam(':userid', $returned_userid, PDO::PARAM_INT);
            $imageSelectQuery->execute();

            while($imageRow = $imageSelectQuery->fetch()) {
                $writeDB->beginTransaction();

                $image = new Image($imageRow->id, $imageRow->title, $imageRow->filename, $imageRow->mimetype, $imageRow->taskid);
                $imageID = $image->getID();

                $sql = 'DELETE FROM images, tasks 
                        WHERE images.id = :imageid
                        AND images.taskid = :taskid 
                        AND tasks.user_id = :userid 
                        AND images.taskid = tasks.id';
                $query = $writeDB->prepare($sql);
                $query->bindParam(':imageid', $imageID, PDO::PARAM_INT);
                $query->bindParam(':taskid', $taskId, PDO::PARAM_INT);
                $query->bindParam(':userid', $returned_userid, PDO::PARAM_INT);
                $query->execute();

                $image->deleteImageFile();

                $writeDB->commit();
            }   

            $sql = 'DELETE FROM tasks WHERE id = :taskid AND user_id = :user_id';
            $query = $writeDB->prepare($sql);
            $query->bindParam(':taskid', $taskId, PDO::PARAM_INT);
            $query->bindParam(':user_id', $returned_userid, PDO::PARAM_INT);
            $query->execute();

            $rowCount = $query->rowCount();
            if ($rowCount === 0) {
                Send::sendResponse(404, false, "Task not found");
            }

            $taskImageFolder = "../images/".$taskId;

            if (is_dir($taskImageFolder)) {
                rmdir($taskImageFolder);
            }

            Send::sendResponse(200, true, "Task deleted");

        } catch (ImageException $e) {
            if ($writeDB->inTransaction()) {
                $writeDB->rollBack();
            }
            Send::sendResponse(500, false, $e->getMessage());
        } catch (PDOException $e) {
            if ($writeDB->inTransaction()) {
                $writeDB->rollBack();
            }
            Send::sendResponse(500, false, "Failed to delete Task");
        }
    } 
    
    // PATCH REQUEST
    else if ($_SERVER['REQUEST_METHOD'] === 'PATCH') {
        try {
            if ($_SERVER['CONTENT_TYPE'] !== 'application/json') {
                Send::sendResponse(400, false, "Content type header is not set to JSON");
            }

            $patchData = file_get_contents('php://input');

            // if(($jsonData = json_decode($patchData)) === false) {
            if(!$jsonData = json_decode($patchData)) {
                Send::sendResponse(400, false, "Request body is not valid json");
            }

            $title_updated = false;
            $description_updated = false;
            $deadline_updated = false;
            $completed_updated = false;

            $queryFields = "";

            if (isset($jsonData->title)) {
                $title_updated = true;
                $queryFields .= "title = :title, ";
            }

            if (isset($jsonData->description)) {
                $description_updated = true;
                $queryFields .= "description = :description, ";
            }

            if (isset($jsonData->deadline)) {
                $deadline_updated = true;
                $queryFields .= 'deadline = STR_TO_DATE(:deadline, \'%d/%m/%Y %H:%i\'), ';
            }

            if (isset($jsonData->completed)) {
                $completed_updated = true;
                $queryFields .= "completed = :completed, ";
            }

            if ($title_updated === false && $description_updated === false && $deadline_updated === false && $completed_updated === false) {
                Send::sendResponse(400, false, "No task fields");
            }

            $queryFields = rtrim($queryFields, ", ");

            $sql = 'SELECT id, title, description, DATE_FORMAT(deadline, "%d/%m/%Y %H:%i") AS deadline, completed 
                    FROM tasks 
                    WHERE id = :taskid
                    AND user_id = :user_id';
            $query = $writeDB->prepare($sql);
            $query->bindParam(':taskid', $taskId, PDO::PARAM_INT);
            $query->bindParam(':user_id', $returned_userid, PDO::PARAM_INT);
            $query->execute();

            $rowCount = $query->rowCount();
            if ($rowCount === 0) {
                Send::sendResponse(404, false, "No task found to udpate");
            }

            while($row = $query->fetch()) {
                $task = new Task($row->id, $row->title, $row->description, $row->deadline, $row->completed);
            }

            $queryString = "UPDATE tasks SET {$queryFields} WHERE id = :taskid AND user_id = :user_id";

            $query = $writeDB->prepare($queryString);

            if ($title_updated === true) {
                $task->setTitle($jsonData->title);
                $up_title = $task->getTitle();
                $query->bindParam(':title', $up_title, PDO::PARAM_STR);
            }

            if ($description_updated === true) {
                $task->setDescription($jsonData->description);
                $up_description = $task->getDescription();
                $query->bindParam(':description', $up_description, PDO::PARAM_STR);
            }

            if ($deadline_updated === true) {
                $task->setDeadline($jsonData->deadline);
                $up_deadline = $task->getDeadline();
                $query->bindParam(':deadline', $up_deadline, PDO::PARAM_STR);
            }

            if ($completed_updated === true) {
                $task->setCompleted($jsonData->completed);
                $up_completed = $task->getCompleted();
                $query->bindParam(':completed', $up_completed, PDO::PARAM_STR);
            }

            $query->bindParam(':taskid', $taskId, PDO::PARAM_INT);
            $query->bindParam(':user_id', $returned_userid, PDO::PARAM_INT);
            $query->execute();

            $rowCount = $query->rowCount();

            if ($rowCount === 0) {
                Send::sendResponse(404, false, "Task not updated");
            }

            $sql = 'SELECT id, title, description, DATE_FORMAT(deadline, "%d/%m/%Y %H:%i") AS deadline, completed 
                    FROM tasks 
                    WHERE id = :taskid
                    AND user_id = :user_id';
            $query = $writeDB->prepare($sql);
            $query->bindParam(':taskid', $taskId, PDO::PARAM_INT);
            $query->bindParam(':user_id', $returned_userid, PDO::PARAM_INT);
            $query->execute();

            $rowCount = $query->rowCount();

            if ($rowCount === 0) {
                Send::sendResponse(404, false, "No task found after update");
            }

            $taskArray = array();

            while($row = $query->fetch()) {
                $imageArray = searchTaskImages($writeDB, $taskId, $returned_userid);
                $task = new Task($row->id, $row->title, $row->description, $row->deadline, $row->completed, $imageArray);
                $taskArray[] = $task->returnTaskArray();
            }

            $returnData = array();
            $returnData['rows_returned'] = $rowCount;
            $returnData['tasks'] = $taskArray;
            
            Send::sendResponse(200, true, "Task udpated", false, $returnData);
            
        } catch (ImageException $e) {
            Send::sendResponse(400, false, $e->getMessage());
        } catch (TaskException $e) {
            Send::sendResponse(400, false, $e->getMessage());
        } catch (PDOException $e) {
            Send::sendResponse(500, false, "Failed to update Task");
        }
    } 

    // REQUEST
    else {
        Send::sendResponse(405, false, "Request method not allowed");
    }
}

// URL request completed
else if (array_key_exists("completed", $_GET)) {
    $completed = $_GET['completed'];

    if ($completed !== 'Y' && $completed !== 'N') {
        Send::sendResponse(400, false, "Bad request");
    }

    // GET METHOD
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        try {
            $sql = 'SELECT id, title, description, DATE_FORMAT(deadline, "%d/%m/%Y %H:%i") AS deadline, completed 
                    FROM tasks 
                    WHERE completed = :completed
                    AND user_id = :user_id';
            $query = $readDB->prepare($sql);
            $query->bindParam(':completed', $completed, PDO::PARAM_STR);
            $query->bindParam(':user_id', $returned_userid, PDO::PARAM_INT);
            $query->execute();

            $rowCount = $query->rowCount();
            $taskArray = array();

            while($row = $query->fetch()) {
                $imageArray = searchTaskImages($readDB, $row->id, $returned_userid);
                $task = new Task($row->id, $row->title, $row->description, $row->deadline, $row->completed, $imageArray);
                $taskArray[] = $task->returnTaskArray();
            }

            $returnData = array();
            $returnData['rows_returned'] = $rowCount;
            $returnData['tasks'] = $taskArray;
            
            Send::sendResponse(200, true, null, false, $returnData);

        } catch (ImageException $e) {
            Send::sendResponse(500, false, $e->getMessage());
        } catch (TaskException $e) {
            Send::sendResponse(500, false, $e->getMessage());
        } catch (PDOException $e) {
            Send::sendResponse(500, false, "Failed to get Task");
        }
    } 
    
    // REQUEST
    else {
        Send::sendResponse(405, false, "Request method not allowed");
    }
}

// URL request page : paginate
else if (array_key_exists("page", $_GET)) {

    // GET METHOD
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $page = $_GET['page'];

        if ($page == '' || !is_numeric($page)) {
            Send::sendResponse(400, false, "Page number can not be balank and must be number");
        }

        $limitPerPage = 20;

        try {
            $sql = 'SELECT count(id) as totalCount FROM tasks WHERE user_id = :user_id';
            $query = $readDB->prepare($sql);
            $query->bindParam(':user_id', $returned_userid, PDO::PARAM_INT);
            $query->execute();

            $row = $query->fetch();

            $tasksCount = intval($row->totalCount);
            $numOfPages = ceil($tasksCount / $limitPerPage);

            if ($numOfPages == 0) {
                $numOfPages = 1;
            }

            if ($page > $numOfPages || $page == 0) {
                Send::sendResponse(404, false, "Page not found");
            }

            $offset = ($page == 1 ? 0 : ($limitPerPage * ($page - 1)));

            $sql = 'SELECT id, title, description, DATE_FORMAT(deadline, "%d/%m/%Y %H:%i") AS deadline, completed 
                    FROM tasks 
                    WHERE user_id = :user_id
                    LIMIT :pageLimit offset :offset';           
            $query = $readDB->prepare($sql);
            $query->bindParam(':user_id', $returned_userid, PDO::PARAM_INT);
            $query->bindParam(':pageLimit', $limitPerPage, PDO::PARAM_INT);
            $query->bindParam(':offset', $offset, PDO::PARAM_INT);
            $query->execute();

            $rowCount = $query->rowCount();
            $taskArray = array();

            while($row = $query->fetch()) {
                $imageArray = searchTaskImages($readDB, $row->id, $returned_userid);
                $task = new Task($row->id, $row->title, $row->description, $row->deadline, $row->completed, $imageArray);
                $taskArray[] = $task->returnTaskArray();
            }

            $returnData = array();
            $returnData['rows_returned'] = $rowCount;
            $returnData['total_rows'] = $tasksCount;
            $returnData['total_pages'] = $numOfPages;
            $page < $numOfPages ? $returnData['has_next_page'] = true : $returnData['has_next_page'] = false;
            $page > 1 ? $returnData['has_previous_page'] = true : $returnData['has_previous_page'] = false;
            $returnData['tasks'] = $taskArray;

            Send::sendResponse(200, true, null, false, $returnData);

        } catch (ImageException $e) {
            Send::sendResponse(500, false, $e->getMessage());
        } catch (TaskException $e) {
            Send::sendResponse(500, false, $e->getMessage());
        } catch (PDOException $e) {
            Send::sendResponse(500, false, "Failed to get Tasks");
        }
    } 
    
    // REQUEST
    else {
        Send::sendResponse(405, false, "Request method not allowed");
    }
}

// URL request none : ALL tasks
else if (empty($_GET)) {

    // GET METHOD
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        try {
            $sql = 'SELECT id, title, description, DATE_FORMAT(deadline, "%d/%m/%Y %H:%i") AS deadline, completed 
                    FROM tasks
                    WHERE user_id = :user_id';
            $query = $readDB->prepare($sql);
            $query->bindParam(':user_id', $returned_userid, PDO::PARAM_INT);
            $query->execute();

            $rowCount = $query->rowCount();
            $taskArray = array();

            while($row = $query->fetch()) {
                $imageArray = searchTaskImages($readDB, $row->id, $returned_userid);
                $task = new Task($row->id, $row->title, $row->description, $row->deadline, $row->completed, $imageArray);
                $taskArray[] = $task->returnTaskArray();
            }

            $returnData = array();
            $returnData['rows_returned'] = $rowCount;
            $returnData['tasks'] = $taskArray;
            
            Send::sendResponse(200, true, null, false, $returnData);

        } catch (ImageException $e) {
            Send::sendResponse(500, false, $e->getMessage());
        } catch (TaskException $e) {
            Send::sendResponse(500, false, $e->getMessage());
        } catch (PDOException $e) {
            Send::sendResponse(500, false, "Failed to get Tasks");
        }
    } 
    
    // POST REQUEST : create
    else if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        try {
            if ($_SERVER['CONTENT_TYPE'] !== 'application/json') {
                Send::sendResponse(400, false, "Content type header is not set to JSON");
            }

            $postData = file_get_contents('php://input');

            // if(($jsonData = json_decode($postData)) === false) {
            if(!$jsonData = json_decode($postData)) {
                Send::sendResponse(400, false, "Request body is not valid json");
            }

            if (!isset($jsonData->title) || !isset($jsonData->completed)) {
                $response = new Response();
                $response->setHttpStatusCode(400);
                $response->setSuccess(false);
                !isset($jsonData->title) ? $response->addMessage("must be title") : false;
                !isset($jsonData->completed) ? $response->addMessage("must be completed") : false;
                $response->send();
                exit();
            }

            $newTask = new Task(null, $jsonData->title, (isset($jsonData->description) ? $jsonData->description : null), (isset($jsonData->deadline) ? $jsonData->deadline : null), $jsonData->completed);

            $title = $newTask->getTitle();
            $description = $newTask->getDescription();
            $deadline = $newTask->getDeadline();
            $completed = $newTask->getCompleted();

            $sql = 'INSERT INTO tasks (title, description, deadline, completed, user_id)
                    VALUES (:title, :description, STR_TO_DATE(:deadline, \'%d/%m/%Y %H:%i\'), :completed, :user_id)';
            $query = $writeDB->prepare($sql);
            $query->bindParam(':title', $title, PDO::PARAM_STR);
            $query->bindParam(':description', $description, PDO::PARAM_STR);
            $query->bindParam(':deadline', $deadline, PDO::PARAM_STR);
            $query->bindParam(':completed', $completed, PDO::PARAM_STR);
            $query->bindParam(':user_id', $returned_userid, PDO::PARAM_INT);
            $query->execute();

            $rowCount = $query->rowCount();
            if ($rowCount === 0) {
                Send::sendResponse(500, false, "Failed to create Task");
            }

            $lastTaskID = $writeDB->lastInsertId();

            $sql = 'SELECT id, title, description, DATE_FORMAT(deadline, "%d/%m/%Y %H:%i") AS deadline, completed 
                    FROM tasks 
                    WHERE id = :taskid
                    AND user_id = :user_id';
            $query = $writeDB->prepare($sql);
            $query->bindParam(':taskid', $lastTaskID, PDO::PARAM_INT);
            $query->bindParam(':user_id', $returned_userid, PDO::PARAM_INT);
            $query->execute();

            $rowCount = $query->rowCount();
            if ($rowCount === 0) {
                Send::sendResponse(500, false, "Failed to retrieve task after create");
            }

            $taskArray = array();

            while($row = $query->fetch()) {
                $task = new Task($row->id, $row->title, $row->description, $row->deadline, $row->completed);
                $taskArray[] = $task->returnTaskArray();
            }

            $returnData = array();
            $returnData['rows_returned'] = $rowCount;
            $returnData['tasks'] = $taskArray;
            
            Send::sendResponse(201, true, "Task created", false, $returnData);

        } catch (TaskException $e) {
            Send::sendResponse(400, false, $e->getMessage());
        } catch (PDOException $e) {
            Send::sendResponse(500, false, "Failed to insert Tasks");
        }
    } 

    // REQUEST
    else {
        Send::sendResponse(405, false, "Request method not allowed");
    }
}

// error response
else {
    Send::sendResponse(404, false, "Endpoint not found");
}