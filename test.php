<?php

require 'vendor/autoload.php';

use App\Model\Response;

// $response = new Response;

// $response->setSuccess(true);
// $response->setHttpStatusCode(200);
// $response->addMessage("test message 1");
// $response->addMessage("test message 2");
// $response->send();

use App\Controller\DB;

try {
    $writeDB = DB::connectWriteDB();
    $readDB = DB::connectReadDB();
} catch (PDOException $e) {
    $response = new Response();
    $response->setHttpStatusCode(500);
    $response->setSuccess(false);
    $response->addMessage("Database Connection error");
    $response->send();
    exit();
}