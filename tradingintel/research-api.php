<?php
declare(strict_types=1);
require __DIR__.'/master-lib.php';
header('Content-Type: application/json');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
try {
    if (empty($_SERVER['HTTPS']) || $_SERVER['HTTPS']==='off') {
        http_response_code(403);
        throw new InvalidArgumentException('HTTPS required');
    }
    $header=$_SERVER['HTTP_AUTHORIZATION']??$_SERVER['REDIRECT_HTTP_AUTHORIZATION']??'';
    $saved=@file_get_contents(ml_private().'/master-api-key.sha256');
    if (!$saved || !preg_match('/^Bearer ([a-f0-9]{64})$/D',$header,$match) ||
        !hash_equals(trim($saved),hash('sha256',$match[1]))) {
        http_response_code(401);
        throw new InvalidArgumentException('Valid bearer token required');
    }
    if ($_SERVER['REQUEST_METHOD']!=='GET') {
        http_response_code(405);
        header('Allow: GET');
        throw new InvalidArgumentException('GET required');
    }
    echo json_encode(['api_version'=>1,'data'=>ml_result($_GET)],JSON_THROW_ON_ERROR);
} catch (InvalidArgumentException $e) {
    if(http_response_code()<400)http_response_code(400);
    echo json_encode(['error'=>$e->getMessage()]);
} catch (Throwable $e) {
    http_response_code(503);
    echo json_encode(['error'=>'Research snapshot unavailable. Check installation and PHP PDO SQLite.']);
}

