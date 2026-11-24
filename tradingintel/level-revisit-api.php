<?php
declare(strict_types=1);
require __DIR__.'/level-revisit-lib.php';
header('Content-Type: application/json');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
try {
    if (empty($_SERVER['HTTPS']) || $_SERVER['HTTPS']==='off') {
        http_response_code(403);
        throw new InvalidArgumentException('HTTPS required');
    }
    require_once __DIR__.'/api-auth.php';
    $headers=function_exists('getallheaders') ? getallheaders() : [];
    if (!ti_api_authorized(ml_private().'/master-api-key.sha256',$_SERVER,is_array($headers)?$headers:[])) {
        http_response_code(401);
        throw new InvalidArgumentException('Valid bearer token required');
    }
    if ($_SERVER['REQUEST_METHOD']!=='GET') {
        http_response_code(405);
        header('Allow: GET');
        throw new InvalidArgumentException('GET required');
    }
    echo json_encode(['api_version'=>1,'data'=>lr_result($_GET)],JSON_THROW_ON_ERROR);
} catch (InvalidArgumentException $e) {
    if(http_response_code()<400)http_response_code(400);
    echo json_encode(['error'=>$e->getMessage()]);
} catch (Throwable $e) {
    http_response_code(503);
    echo json_encode(['error'=>'Level-revisit snapshot unavailable. Check the private level-revisit.sqlite and PDO SQLite installation.']);
}

