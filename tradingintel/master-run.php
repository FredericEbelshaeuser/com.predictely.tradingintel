<?php
require __DIR__.'/bootstrap.php';auth();
require __DIR__.'/master-lib.php';
require __DIR__.'/master-runner.php';
$reserve=str_repeat(' ',65536);
register_shutdown_function(function()use(&$reserve){
    $last=error_get_last();
    if(!$last||!in_array($last['type'],[E_ERROR,E_PARSE,E_CORE_ERROR,E_COMPILE_ERROR],true))return;
    $reserve='';
    $detail=str_replace(ml_private(),'[private folder]',$last['message']);
    http_response_code(503);header('Content-Type: application/json');
    echo json_encode(['error'=>($GLOBALS['mr_stage']??'Starting run').': '.substr($detail,0,600),'stage'=>$GLOBALS['mr_stage']??'start']);
});
try{
    if($_SERVER['REQUEST_METHOD']!=='POST')throw new InvalidArgumentException('POST required');
    csrf();session_write_close();
    $input=json_decode(file_get_contents('php://input'),true,512,JSON_THROW_ON_ERROR);
    $action=$input['action']??'';
    $GLOBALS['mr_stage']='Creating saved run';
    if($action==='start')jsonout(mr_create($input));
    $id=(string)($input['run']??'');
    if($action==='step')jsonout(mr_step($id));
    $db=mr_open($id);
    if($action==='cancel')$db->exec("UPDATE state SET status='cancelled' WHERE status='running'");
    elseif($action==='resume')$db->exec("UPDATE state SET status='running' WHERE status='cancelled'");
    elseif($action!=='status')throw new InvalidArgumentException('Unknown action');
    jsonout(['run'=>$id,...$db->query('SELECT * FROM state')->fetch()]);
}catch(InvalidArgumentException $e){http_response_code(400);jsonout(['error'=>$e->getMessage()]);}
catch(Throwable $e){
    $stage=$GLOBALS['mr_stage']??'Starting run';
    error_log('Master run ['.$stage.']: '.$e->getMessage());
    $detail=str_replace(ml_private(),'[private folder]',$e->getMessage());
    http_response_code(503);
    jsonout(['error'=>$stage.': '.substr($detail,0,600),'stage'=>$stage,'php_version'=>PHP_VERSION]);
}
