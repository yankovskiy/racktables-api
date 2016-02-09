<?php
require_once 'vendor/autoload.php';
require_once 'ServerMgmt.php';

$app = new \Slim\Slim();
$app->contentType('text/html; charset=utf-8');

$app->post('/server', 'addServer');        // Добавление сервера
$app->get('/test', 'test');
$app->run();


/**
 * Добавление сервера
 */
function addServer() {
    $srvMgmt = new ServerMgmt();
    $app = \Slim\Slim::getInstance();
    try {
        $srvMgmt->addServer($app->request()->getBody());
    } catch (ServerMgmtException $e) {
        //$app->halt(403, $e->getMessage());
        echo $e->getMessage();
    } catch (Exception $e) {
        //$app->halt(500, $e->getMessage());
        echo $e->getMessage();
    }
}

function test() {
    //var_dump(getAttrId("CPU, Тип / количество"));
}