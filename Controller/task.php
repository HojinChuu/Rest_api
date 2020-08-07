<?php

require_once './db.php';
require_once '../Model/Task.php';
require_once '../Model/Response.php';

try {
    $writeDB = DB::connectWriteDB();
    $readDB = DB::connectreadDB();
} catch (PDOException $e) {
    error_log("Connection error : {$e}", 0);
    $response = new Response();
    $response->setHttpStatusCode(500);
    $response->setSuccess(false);
    $response->addMessage("Database connect error");
    $response->send();
    exit();
}

// url request taskid
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
                    FROM tbltasks 
                    WHERE id = :taskid';
            $query = $readDB->prepare($sql); 
            $query->bindParam(':taskid', $taskId, PDO::PARAM_INT);
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
            $response->toCache(true);
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
            error_log("DB query error : {$e}", 0);
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
            $sql = 'DELETE FROM tbltasks WHERE id = :taskid';
            $query = $writeDB->prepare($sql);
            $query->bindParam(':taskid', $taskId, PDO::PARAM_INT);
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
            error_log("DB query error : {$e}", 0);
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

// url request completed
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
                    FROM tbltasks 
                    WHERE completed = :completed';
            $query = $readDB->prepare($sql);
            $query->bindParam(':completed', $completed, PDO::PARAM_STR);
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
            $response->toCache(true);
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
            error_log("DB query error : {$e}", 0);
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