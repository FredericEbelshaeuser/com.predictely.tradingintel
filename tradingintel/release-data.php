<?php
require __DIR__.'/bootstrap.php';auth();session_write_close();
try{
 if(($_GET['action']??'')!=='pair'){jsonout(['events'=>stmt('SELECT event_date AS day,event_time AS clock,event_type AS kind,release_title AS title FROM ti_bls_calendar ORDER BY event_date,event_time,release_title')->fetchAll(PDO::FETCH_ASSOC)]);}
 require __DIR__.'/research.php';$cfg=$_GET;$id=(int)($cfg['dataset']??0);if(!$id)$id=(int)stmt("SELECT id FROM ti_datasets WHERE status='ready' ORDER BY id DESC LIMIT 1")->fetchColumn();
 if(!stmt("SELECT id FROM ti_datasets WHERE id=? AND status='ready'",[$id])->fetchColumn())throw new RuntimeException('Select a ready dataset');
 $bounds=[];foreach(['start','end'] as $k){$v=$cfg[$k]??'';$d=DateTimeImmutable::createFromFormat('!Y-m-d',$v,new DateTimeZone('UTC'));if(!$d||$d->format('Y-m-d')!==$v)throw new RuntimeException('Invalid date');$bounds[]=$d;}[$a,$b]=$bounds;if($a>$b||$a->diff($b)->days>31)throw new RuntimeException('Select up to 32 dates');
 $data=stmt('SELECT ts_et,o,h,l,c,v FROM ti_candles WHERE dataset_id=? AND ts_et>=? AND ts_et<? ORDER BY ts_et,source_row LIMIT 100001',[$id,$a->format('Y-m-d'),$b->modify('+1 day')->format('Y-m-d')])->fetchAll(PDO::FETCH_NUM);if(count($data)>100000)throw new RuntimeException('Window too large');
 foreach($data as &$r){$r[0]=str_replace(' ','T',substr($r[0],0,16));for($k=1;$k<=5;$k++)$r[$k]=(float)$r[$k];}unset($r);$audit=[];ti_pair_study($data,$cfg,$audit);foreach($audit as &$v){foreach(['pivot1','pivot2','first','at','hit'] as $k)if(isset($v[$k]))$v[$k]=$data[$v[$k]][0];}unset($v);jsonout(['pairs'=>$audit]);
}catch(Throwable $e){http_response_code(400);jsonout(['error'=>$e instanceof PDOException?'Database lookup failed. For calendar access import the supplied BLS SQL first.':$e->getMessage()]);}

