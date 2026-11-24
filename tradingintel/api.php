<?php
require 'bootstrap.php';auth();
try {
 $action=$_GET['action']??'';$post=$_SERVER['REQUEST_METHOD']==='POST';
 if($post)csrf();
 $p=$post&&$action!=='chunk'?(json_decode(file_get_contents('php://input'),true,512,JSON_THROW_ON_ERROR)):[];
 if($action==='datasets')jsonout(['datasets'=>stmt("SELECT * FROM ti_datasets WHERE status='ready' ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC)]);
 if($action==='jobs')jsonout(['jobs'=>stmt("SELECT j.*,d.name FROM ti_import_jobs j JOIN ti_datasets d ON d.id=j.dataset_id ORDER BY j.created_at DESC LIMIT 30")->fetchAll(PDO::FETCH_ASSOC)]);
 if($action==='init'&&$post){
  $fingerprint=(string)($p['fingerprint']??'');if(!preg_match('/^[a-f0-9]{64}$/D',$fingerprint))throw new RuntimeException('Missing file fingerprint');$size=(int)($p['size']??0);$name=trim((string)($p['name']??''));
  if($size<1||$size>1073741824||!$name||strlen($name)>160)throw new RuntimeException('File must be under 1 GiB and have a short name.');
  if(!is_dir($private.'/uploads')&&!mkdir($private.'/uploads',0700,true))throw new RuntimeException('Private upload directory is not writable');
  $id=bin2hex(random_bytes(16));db()->beginTransaction();stmt('INSERT INTO ti_datasets(name) VALUES(?)',[$name]);$dataset=db()->lastInsertId();stmt('INSERT INTO ti_import_jobs(id,dataset_id,total_bytes,file_fingerprint) VALUES(?,?,?,?)',[$id,$dataset,$size,$fingerprint]);db()->commit();jsonout(['id'=>$id]);
 }
 if(in_array($action,['chunk','import'],true)&&$post){
  $id=$_GET['job']??$p['job']??'';if(!preg_match('/^[a-f0-9]{32}$/D',$id))throw new RuntimeException('Invalid job');
  db()->beginTransaction();$job=stmt('SELECT * FROM ti_import_jobs WHERE id=? FOR UPDATE',[$id])->fetch(PDO::FETCH_ASSOC);if(!$job)throw new RuntimeException('Unknown import');
  $path=$private.'/uploads/'.$id.'.csv';
  if($action==='chunk'){
   $offset=(int)($_GET['offset']??-1);$body=file_get_contents('php://input');$length=strlen($body);
   if($job['status']!=='uploading'||$offset!==(int)$job['uploaded_bytes']||$length<1||$length>1048576||$offset+$length>$job['total_bytes'])throw new RuntimeException('Upload offset mismatch; reload Import and resume.');
   $f=fopen($path,'c+b');if(!$f)throw new RuntimeException('Cannot write private upload');fseek($f,$offset);if(fwrite($f,$body)!==$length)throw new RuntimeException('Disk full');ftruncate($f,$offset+$length);fclose($f);
   stmt('UPDATE ti_import_jobs SET uploaded_bytes=?,status=? WHERE id=?',[$offset+$length,$offset+$length==$job['total_bytes']?'uploaded':'uploading',$id]);db()->commit();jsonout(['uploaded'=>$offset+$length]);
  }
  if($job['status']==='ready'){db()->commit();jsonout(['done'=>true,'rows'=>(int)$job['rows_done']]);}
  if(!in_array($job['status'],['uploaded','importing'],true))throw new RuntimeException('Finish uploading first');
  $f=fopen($path,'rb');if(!$f)throw new RuntimeException('Uploaded CSV missing');fseek($f,(int)$job['read_offset']);
  if(!$job['read_offset']){$header=fgetcsv($f,0,',','"','');if(!$header)throw new RuntimeException('Empty CSV');$header[0]=preg_replace('/^\xEF\xBB\xBF/','',$header[0]);if(array_slice($header,0,6)!==['timestamp ET','open','high','low','close','volume']||(isset($header[6])&&$header[6]!=='Vwap_RTH')||(isset($header[7])&&$header[7]!=='Vwap_ETH'))throw new RuntimeException('Expected timestamp ET,open,high,low,close,volume columns');}
  $insert=db()->prepare('INSERT INTO ti_candles(dataset_id,source_row,ts_et,o,h,l,c,v,vwap_rth,vwap_eth) VALUES(?,?,?,?,?,?,?,?,?,?)');$count=(int)$job['rows_done'];$batch=0;
  while($batch<2000&&($r=fgetcsv($f,0,',','"',''))!==false){
   $date=DateTimeImmutable::createFromFormat('!m/d/Y H:i',trim($r[0]??''),new DateTimeZone('UTC'));$errors=DateTimeImmutable::getLastErrors();
   if(!$date||($errors&&($errors['warning_count']||$errors['error_count'])))throw new RuntimeException('Invalid timestamp at CSV row '.($count+2));
   $nums=[];foreach(array_slice($r,1,5) as $x){if(!is_numeric($x)||!is_finite((float)$x))throw new RuntimeException('Invalid numeric data at row '.($count+2));$nums[]=(float)$x;}
   if(count($nums)!==5)throw new RuntimeException('Missing columns');[$o,$h,$l,$c,$v]=$nums;
   if($l>min($o,$c)||$h<max($o,$c)||$v<0)throw new RuntimeException('Invalid OHLCV at row '.($count+2));
   $vw=[];foreach([6,7] as $i){$x=$r[$i]??'';if($x!==''&&(!is_numeric($x)||!is_finite((float)$x)))throw new RuntimeException('Invalid VWAP');$vw[]=$x===''?null:(float)$x;}
   $count++;$batch++;$insert->execute([$job['dataset_id'],$count,$date->format('Y-m-d H:i:00'),$o,$h,$l,$c,$v,...$vw]);
  }
  $offset=ftell($f);$done=$offset>=(int)$job['total_bytes'];fclose($f);
  stmt('UPDATE ti_import_jobs SET read_offset=?,rows_done=?,status=? WHERE id=?',[$offset,$count,$done?'ready':'importing',$id]);
  if($done){$first=stmt('SELECT ts_et FROM ti_candles WHERE dataset_id=? ORDER BY ts_et,source_row LIMIT 1',[$job['dataset_id']])->fetchColumn();$last=stmt('SELECT ts_et FROM ti_candles WHERE dataset_id=? ORDER BY ts_et DESC,source_row DESC LIMIT 1',[$job['dataset_id']])->fetchColumn();if(!$first)throw new RuntimeException('CSV contains no candles');stmt("UPDATE ti_datasets SET status='ready',row_count=?,first_stamp=?,last_stamp=? WHERE id=?",[$count,$first,$last,$job['dataset_id']]);}
  db()->commit();jsonout(['done'=>$done,'rows'=>$count,'bytes'=>$offset,'total'=>(int)$job['total_bytes']]);
 }
 $dataset=(int)($_GET['dataset']??$p['dataset']??0);
 if(!$dataset)$dataset=(int)stmt("SELECT id FROM ti_datasets WHERE status='ready' ORDER BY id DESC LIMIT 1")->fetchColumn();
 $meta=stmt("SELECT * FROM ti_datasets WHERE id=? AND status='ready'",[$dataset])->fetch(PDO::FETCH_ASSOC);
 if($action==='meta')jsonout(['first'=>$meta?str_replace(' ','T',substr($meta['first_stamp'],0,16)):null,'last'=>$meta?str_replace(' ','T',substr($meta['last_stamp'],0,16)):null]);
 if(!$meta){if($action==='notes'&&!$post)jsonout(['notes'=>[]]);throw new RuntimeException('Import and select a ready dataset first');}
 if($action==='notes'){
  if($post){$id=(int)($p['id']??0);if(!empty($p['archive']))stmt('UPDATE ti_journal_notes SET archived=1 WHERE id=? AND dataset_id=?',[$id,$dataset]);
   else{$stamp=(string)($p['stamp']??'');$date=DateTimeImmutable::createFromFormat('!Y-m-d\TH:i',$stamp,new DateTimeZone('UTC'));if(!$date||$date->format('Y-m-d\TH:i')!==$stamp)throw new RuntimeException('Invalid ET timestamp');
    $price=$p['price']??null;$tf=(int)($p['tf']??0);$title=trim((string)($p['title']??''));$body=(string)($p['body']??'');$tags=(string)($p['tags']??'');
    if(!is_numeric($price)||!is_finite((float)$price)||!in_array($tf,[1,2,3,5,10,15,30,60,240])||!$title||strlen($title)>160||strlen($body)>10000||strlen($tags)>500)throw new RuntimeException('Invalid note or note too long');
    $values=[$date->format('Y-m-d H:i:00'),$price,$tf,$title,$body,$tags];
    if($id){if(!stmt('SELECT id FROM ti_journal_notes WHERE id=? AND dataset_id=? AND archived=0',[$id,$dataset])->fetchColumn())throw new RuntimeException('Note not found');stmt('UPDATE ti_journal_notes SET stamp=?,price=?,tf=?,title=?,body=?,tags=? WHERE id=? AND dataset_id=?',[...$values,$id,$dataset]);}
    else stmt('INSERT INTO ti_journal_notes(stamp,price,tf,title,body,tags,dataset_id) VALUES(?,?,?,?,?,?,?)',[...$values,$dataset]);
   }
  }
  $notes=stmt('SELECT * FROM ti_journal_notes WHERE dataset_id=? AND archived=0 ORDER BY updated_at DESC LIMIT 1000',[$dataset])->fetchAll(PDO::FETCH_ASSOC);foreach($notes as &$n){$n['stamp']=str_replace(' ','T',substr($n['stamp'],0,16));$n['price']=(float)$n['price'];}unset($n);jsonout(['notes'=>$notes]);
 }
 require 'research.php';$start=$_GET['start']??'';$end=$_GET['end']??'';
 foreach([$start,$end] as $day){$d=DateTimeImmutable::createFromFormat('!Y-m-d',$day,new DateTimeZone('UTC'));if(!$d||$d->format('Y-m-d')!==$day)throw new RuntimeException('Invalid date');}
 $a=new DateTimeImmutable($start,new DateTimeZone('UTC'));$b=new DateTimeImmutable($end,new DateTimeZone('UTC'));$days=($b->getTimestamp()-$a->getTimestamp())/86400;if($days<0||$days>31)throw new RuntimeException('Select at most 32 calendar days');
 $data=stmt('SELECT ts_et,o,h,l,c,v FROM ti_candles WHERE dataset_id=? AND ts_et>=? AND ts_et<? ORDER BY ts_et,source_row LIMIT 100001',[$dataset,$start,$b->modify('+1 day')->format('Y-m-d')])->fetchAll(PDO::FETCH_NUM);
 if(count($data)>100000)throw new RuntimeException('Window exceeds 100,000 rows');foreach($data as &$r){$r[0]=str_replace(' ','T',substr($r[0],0,16));for($i=1;$i<6;$i++)$r[$i]=(float)$r[$i];}unset($r);
 if($action==='candles')jsonout(['candles'=>aggregate_bars($data,(int)($_GET['tf']??1)),'source_rows'=>count($data)]);
 if($action==='test')jsonout(backtest($data,$_GET));
 throw new RuntimeException('Unknown action');
}catch(Throwable $e){if(isset($config)){try{if(db()->inTransaction())db()->rollBack();}catch(Throwable $ignored){}}http_response_code(400);error_log('TradingIntel: '.$e->getMessage());
//jsonout(['error'=>$e instanceof PDOException?'Database operation failed. Check installation and server error log.':$e->getMessage()]);
  jsonout(['error'=>$e->getMessage()]);  
}
