<?php
require __DIR__.'/bootstrap.php';auth();require __DIR__.'/master-lib.php';
try{
 $dataset=ml_uint($_GET,'dataset',0,2147483647);
 if(!$dataset)$dataset=(int)stmt("SELECT id FROM ti_datasets WHERE status='ready' ORDER BY id DESC LIMIT 1")->fetchColumn();
 if(!$dataset)throw new InvalidArgumentException('Select an imported dataset');
 $loader=function($a,$b)use($dataset){
  $rows=stmt('SELECT ts_et,o,h,l,c,v FROM ti_candles WHERE dataset_id=? AND ts_et>=? AND ts_et<=? ORDER BY ts_et,source_row LIMIT 4001',[$dataset,str_replace('T',' ',$a).':00',str_replace('T',' ',$b).':00'])->fetchAll(PDO::FETCH_ASSOC);
  foreach($rows as &$v){$v['ts']=str_replace(' ','T',substr($v['ts_et'],0,16));unset($v['ts_et']);}return $rows;
 };
 jsonout(sv_result($_GET,$loader));
}catch(InvalidArgumentException $e){http_response_code(400);jsonout(['error'=>$e->getMessage()]);}
catch(Throwable $e){http_response_code(503);error_log('Swing levels: '.$e->getMessage());jsonout(['error'=>'Swing level data unavailable; check selected dataset and server log.']);}
