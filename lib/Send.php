<?php

require_once '../Model/Response.php';

class Send 
{
    static function sendResponse($status, $success, $message = null, $toCache = false, $data = null)  
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
}