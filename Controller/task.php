<?php

require_once 'db.php';
require_once '../Model/Task.php';
require_once '../Model/Response.php';
require_once '../lib/cors.php';

// cors
$CORS = new Cors();
$CORS();

try {
    $writeDB = DB::connectWriteDB();
    $readDB = DB::connectreadDB();
} catch (PDOException $e) {
    $response = new Response();
    $response->setHttpStatusCode(500);
    $response->setSuccess(false);
    $response->addMessage("Database connect error");
    $response->send();
    exit();
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
        $response = new Response();
        $response->setHttpStatusCode(401);
        $response->setSuccess(false);
        $response->addMessage("Invalid access token");
        $response->send();
        exit();
    }

    $row = $query->fetch();
    $returned_userid = $row->user_id;
    $returned_accesstokenexpiry = $row->access_token_expiry;
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

    if ($returned_loginattempts >= 3) {
        $response = new Response();
        $response->setHttpStatusCode(401);
        $response->setSuccess(false);
        $response->addMessage("User account is currently locked");
        $response->send();
        exit();
    }

    if (strtotime($returned_accesstokenexpiry) < time()) {
        $response = new Response();
        $response->setHttpStatusCode(401);
        $response->setSuccess(false);
        $response->addMessage("Access token expired");
        $response->send();
        exit();
    }

} catch (PDOException $e) {
    $response = new Response();
    $response->setHttpStatusCode(500);
    $response->setSuccess(false);
    $response->addMessage("Auth error");
    $response->send();
    exit();
}
// END AUTH logic

// URL request taskid
if (array_key_exists("taskid", $_GET)) {
    $taskId = $_GET['taskid'];

    if ($taskId == '' || !is_numeric($taskId)) {
        $response = new Response();
        $response->setHttpStatusCode(400);
        $response->setSuccess(false);
        $response->addMessage("Task ID can not be blank or must be numeric");
        $response->send();
        exit();
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
                $response = new Response();
                $response->setHttpStatusCode(404);
                $response->setSuccess(false);
                $response->addMessage("Task not found");
                $response->send();
                exit(); 
            }

            while($row = $query->fetch()) {
                $task = new Task($row->id, $row->title, $row->description, $row->deadline, $row->completed);
                $taskArray[] = $task->returnTaskArray();
            }

            $returnData = array();
            $returnData['rows_returned'] = $rowCount;
            $returnData['tasks'] = $taskArray;
            
            $response = new Response();
            $response->setHttpStatusCode(200);
            $response->setSuccess(true);
            // $response->toCache(true);
            $response->setData($returnData);
            $response->send();
            exit();

        } catch (TaskException $e) {
            $response = new Response();
            $response->setHttpStatusCode(500);
            $response->setSuccess(false);
            $response->addMessage($e->getMessage());
            $response->send();
            exit();
        } catch (PDOException $e) {
            $response = new Response();
            $response->setHttpStatusCode(500);
            $response->setSuccess(false);
            $response->addMessage("Failed to get Task");
            $response->send();
            exit();
        }
    } 

    // DELETE REQUEST
    else if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
        try {
            $sql = 'DELETE FROM tasks WHERE id = :taskid AND user_id = :user_id';
            $query = $writeDB->prepare($sql);
            $query->bindParam(':taskid', $taskId, PDO::PARAM_INT);
            $query->bindParam(':user_id', $returned_userid, PDO::PARAM_INT);
            $query->execute();

            $rowCount = $query->rowCount();
            if ($rowCount === 0) {
                $response = new Response();
                $response->setHttpStatusCode(404);
                $response->setSuccess(false);
                $response->addMessage("Task not found");
                $response->send();
                exit(); 
            }

            $response = new Response();
            $response->setHttpStatusCode(200);
            $response->setSuccess(true);
            $response->addMessage("Task deleted");
            $response->send();
            exit();

        } catch (PDOException $e) {
            $response = new Response();
            $response->setHttpStatusCode(500);
            $response->setSuccess(false);
            $response->addMessage("Failed to delete Task");
            $response->send();
            exit();
        }
    } 
    
    // PATCH REQUEST
    else if ($_SERVER['REQUEST_METHOD'] === 'PATCH') {
        try {
            if ($_SERVER['CONTENT_TYPE'] !== 'application/json') {
                $response = new Response();
                $response->setHttpStatusCode(400);
                $response->setSuccess(false);
                $response->addMessage("Content type header is not set to JSON");
                $response->send();
                exit();
            }

            $patchData = file_get_contents('php://input');

            // if(($jsonData = json_decode($patchData)) === false) {
            if(!$jsonData = json_decode($patchData)) {
                $response = new Response();
                $response->setHttpStatusCode(400);
                $response->setSuccess(false);
                $response->addMessage("Request body is not valid json");
                $response->send();
                exit();
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
                $response = new Response();
                $response->setHttpStatusCode(400);
                $response->setSuccess(false);
                $response->addMessage("No task fields");
                $response->send();
                exit();
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
                $response = new Response();
                $response->setHttpStatusCode(404);
                $response->setSuccess(false);
                $response->addMessage("No task found to udpate");
                $response->send();
                exit();
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
                $response = new Response();
                $response->setHttpStatusCode(404);
                $response->setSuccess(false);
                $response->addMessage("Task not updated");
                $response->send();
                exit();
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
                $response = new Response();
                $response->setHttpStatusCode(404);
                $response->setSuccess(false);
                $response->addMessage("No task found after update");
                $response->send();
                exit();
            }

            $taskArray = array();

            while($row = $query->fetch()) {
                $task = new Task($row->id, $row->title, $row->description, $row->deadline, $row->completed);
                $taskArray[] = $task->returnTaskArray();
            }

            $returnData = array();
            $returnData['rows_returned'] = $rowCount;
            $returnData['tasks'] = $taskArray;
            
            $response = new Response();
            $response->setHttpStatusCode(200);
            $response->setSuccess(true);
            $response->addMessage("Task udpated");
            $response->setData($returnData);
            $response->send();
            exit();
            
        } catch (TaskException $e) {
            $response = new Response();
            $response->setHttpStatusCode(400);
            $response->setSuccess(false);
            $response->addMessage($e->getMessage());
            $response->send();
            exit();
        } catch (PDOException $e) {
            $response = new Response();
            $response->setHttpStatusCode(500);
            $response->setSuccess(false);
            $response->addMessage("Failed to update Task");
            $response->send();
            exit();
        }
    } 

    // REQUEST
    else {
        $response = new Response();
        $response->setHttpStatusCode(405);
        $response->setSuccess(false);
        $response->addMessage("Request method not allowed");
        $response->send();
        exit();
    }
}

// URL request completed
else if (array_key_exists("completed", $_GET)) {
    $completed = $_GET['completed'];

    if ($completed !== 'Y' && $completed !== 'N') {
        $response = new Response();
        $response->setHttpStatusCode(400);
        $response->setSuccess(false);
        $response->addMessage("Bad request");
        $response->send();
        exit();
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
                $task = new Task($row->id, $row->title, $row->description, $row->deadline, $row->completed);
                $taskArray[] = $task->returnTaskArray();
            }

            $returnData = array();
            $returnData['rows_returned'] = $rowCount;
            $returnData['tasks'] = $taskArray;
            
            $response = new Response();
            $response->setHttpStatusCode(200);
            $response->setSuccess(true);
            // $response->toCache(true);
            $response->setData($returnData);
            $response->send();
            exit();

        } catch (TaskException $e) {
            $response = new Response();
            $response->setHttpStatusCode(500);
            $response->setSuccess(false);
            $response->addMessage($e->getMessage());
            $response->send();
            exit();
        } catch (PDOException $e) {
            $response = new Response();
            $response->setHttpStatusCode(500);
            $response->setSuccess(false);
            $response->addMessage("Failed to get Task");
            $response->send();
            exit();
        }
    } 
    
    // REQUEST
    else {
        $response = new Response();
        $response->setHttpStatusCode(405);
        $response->setSuccess(false);
        $response->addMessage("Request method not allowed");
        $response->send();
        exit();
    }
}

// URL request page : paginate
else if (array_key_exists("page", $_GET)) {

    // GET METHOD
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $page = $_GET['page'];

        if ($page == '' || !is_numeric($page)) {
            $response = new Response();
            $response->setHttpStatusCode(400);
            $response->setSuccess(false);
            $response->addMessage("Page number can not be balank and must be number");
            $response->send();
            exit();
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
                $response = new Response();
                $response->setHttpStatusCode(404);
                $response->setSuccess(false);
                $response->addMessage("Page not found");
                $response->send();
                exit();
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
                $task = new Task($row->id, $row->title, $row->description, $row->deadline, $row->completed);
                $taskArray[] = $task->returnTaskArray();
            }

            $returnData = array();
            $returnData['rows_returned'] = $rowCount;
            $returnData['total_rows'] = $tasksCount;
            $returnData['total_pages'] = $numOfPages;
            $page < $numOfPages ? $returnData['has_next_page'] = true : $returnData['has_next_page'] = false;
            $page > 1 ? $returnData['has_previous_page'] = true : $returnData['has_previous_page'] = false;
            $returnData['tasks'] = $taskArray;

            $response = new Response();
            $response->setHttpStatusCode(200);
            $response->setSuccess(true);
            // $response->toCache(true);
            $response->setData($returnData);
            $response->send();
            exit();

        } catch (TaskException $e) {
            $response = new Response();
            $response->setHttpStatusCode(500);
            $response->setSuccess(false);
            $response->addMessage($e->getMessage());
            $response->send();
            exit();
        } catch (PDOException $e) {
            $response = new Response();
            $response->setHttpStatusCode(500);
            $response->setSuccess(false);
            $response->addMessage("Failed to get Tasks");
            $response->send();
            exit();
        }
    } 
    
    // REQUEST
    else {
        $response = new Response();
        $response->setHttpStatusCode(405);
        $response->setSuccess(false);
        $response->addMessage("Request method not allowed");
        $response->send();
        exit();
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
                $task = new Task($row->id, $row->title, $row->description, $row->deadline, $row->completed);
                $taskArray[] = $task->returnTaskArray();
            }

            $returnData = array();
            $returnData['rows_returned'] = $rowCount;
            $returnData['tasks'] = $taskArray;
            
            $response = new Response();
            $response->setHttpStatusCode(200);
            $response->setSuccess(true);
            // $response->toCache(true);
            $response->setData($returnData);
            $response->send();
            exit();

        } catch (TaskException $e) {
            $response = new Response();
            $response->setHttpStatusCode(500);
            $response->setSuccess(false);
            $response->addMessage($e->getMessage());
            $response->send();
            exit();
        } catch (PDOException $e) {
            $response = new Response();
            $response->setHttpStatusCode(500);
            $response->setSuccess(false);
            $response->addMessage("Failed to get Tasks");
            $response->send();
            exit();
        }
    } 
    
    // POST REQUEST : create
    else if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        try {
            if ($_SERVER['CONTENT_TYPE'] !== 'application/json') {
                $response = new Response();
                $response->setHttpStatusCode(400);
                $response->setSuccess(false);
                $response->addMessage("Content type header is not set to JSON");
                $response->send();
                exit();
            }

            $postData = file_get_contents('php://input');

            // if(($jsonData = json_decode($postData)) === false) {
            if(!$jsonData = json_decode($postData)) {
                $response = new Response();
                $response->setHttpStatusCode(400);
                $response->setSuccess(false);
                $response->addMessage("Request body is not valid json");
                $response->send();
                exit();
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
                $response = new Response();
                $response->setHttpStatusCode(500);
                $response->setSuccess(false);
                $response->addMessage("Failed to create Task");
                $response->send();
                exit();
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
                $response = new Response();
                $response->setHttpStatusCode(500);
                $response->setSuccess(false);
                $response->addMessage("Failed to retrieve task after create");
                $response->send();
                exit();
            }

            $taskArray = array();

            while($row = $query->fetch()) {
                $task = new Task($row->id, $row->title, $row->description, $row->deadline, $row->completed);
                $taskArray[] = $task->returnTaskArray();
            }

            $returnData = array();
            $returnData['rows_returned'] = $rowCount;
            $returnData['tasks'] = $taskArray;
            
            $response = new Response();
            $response->setHttpStatusCode(201);
            $response->setSuccess(true);
            $response->addMessage("Task created");
            $response->setData($returnData);
            $response->send();
            exit();

        } catch (TaskException $e) {
            $response = new Response();
            $response->setHttpStatusCode(400);
            $response->setSuccess(false);
            $response->addMessage($e->getMessage());
            $response->send();
            exit();
        } catch (PDOException $e) {
            $response = new Response();
            $response->setHttpStatusCode(500);
            $response->setSuccess(false);
            $response->addMessage("Failed to insert Tasks");
            $response->send();
            exit();
        }
    } 

    // REQUEST
    else {
        $response = new Response();
        $response->setHttpStatusCode(405);
        $response->setSuccess(false);
        $response->addMessage("Request method not allowed");
        $response->send();
        exit();
    }
}

// error response
else {
    $response = new Response();
    $response->setHttpStatusCode(404);
    $response->setSuccess(false);
    $response->addMessage("Endpoint not found");
    $response->send();
    exit();
}