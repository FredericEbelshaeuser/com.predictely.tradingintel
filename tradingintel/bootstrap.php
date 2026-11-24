<?php
declare(strict_types=1);
$private=dirname(__DIR__,2).'/tradingintel-private';
if (!is_file($private.'/config.php')) { http_response_code(503);exit('Install private config.php first; see INSTALL.md.'); }
$config=require $private.'/config.php';
$config['dsn'] = sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4',$config['host'],$config['database']);
if ($config['https_only'] && (empty($_SERVER['HTTPS']) || $_SERVER['HTTPS']==='off')) { http_response_code(403);exit('HTTPS is required. Open the https:// address.'); }
session_name('tradingintel_session');
session_set_cookie_params(['lifetime'=>0,'path'=>'/tradingintel/','secure'=>$config['https_only'],'httponly'=>true,'samesite'=>'Strict']);
session_start();
header('Cache-Control: no-store');header('X-Content-Type-Options: nosniff');header('X-Frame-Options: DENY');
$_SESSION['csrf']??=bin2hex(random_bytes(32));
function db(): PDO {global $config;static $pdo;return $pdo??=new PDO($config['dsn'],$config['db_user'],$config['db_password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);}
function auth(): void {if(empty($_SESSION['logged_in'])){http_response_code(401);exit('Login required. Open login.php.');}}
function csrf(): void {if(!hash_equals($_SESSION['csrf'],$_SERVER['HTTP_X_CSRF_TOKEN']??$_POST['csrf']??''))throw new RuntimeException('Session expired. Reload and log in again.');}
function jsonout($data): never {header('Content-Type: application/json');echo json_encode($data,JSON_THROW_ON_ERROR);exit;}
function stmt(string $sql,array $args=[]): PDOStatement {$s=db()->prepare($sql);$s->execute($args);return $s;}
