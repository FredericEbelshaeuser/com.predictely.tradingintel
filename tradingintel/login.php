<?php
require 'bootstrap.php';$error='';
if($_SERVER['REQUEST_METHOD']==='POST'){
 try{csrf();
 // A shared filesystem throttle survives session-cookie resets.
 $f=fopen($private.'/login-rate.json','c+');if(!$f||!flock($f,LOCK_EX))throw new RuntimeException('Login temporarily unavailable');
 $state=json_decode(stream_get_contents($f),true)?:['start'=>time(),'count'=>0];
 if(time()-$state['start']>300)$state=['start'=>time(),'count'=>0];
 $allowed=$state['count']<20;$state['count']++;rewind($f);ftruncate($f,0);fwrite($f,json_encode($state));flock($f,LOCK_UN);fclose($f);
 if(!$allowed)throw new RuntimeException('Too many attempts; wait five minutes.');
 if(password_verify($_POST['password']??'',$config['password_hash'])){session_regenerate_id(true);$_SESSION['logged_in']=true;header('Location: index.php');exit;}
 $error='Incorrect password';
 }catch(Throwable $e){$error=$e->getMessage();}
}
?><!doctype html><meta name="viewport" content="width=device-width,initial-scale=1"><title>TradingIntel login</title><style>body{background:#101724;color:#def;font:16px system-ui;max-width:420px;margin:12vh auto;padding:20px}input,button{box-sizing:border-box;width:100%;padding:14px;margin:10px 0}</style><h1>Predictely · TradingIntel</h1><p>Private research and chart journal</p><form method="post"><input type="hidden" name="csrf" value="<?=htmlspecialchars($_SESSION['csrf'])?>"><input name="password" type="password" autocomplete="current-password" placeholder="Private password" required><button>Sign in</button></form><p><?=htmlspecialchars($error)?></p>
