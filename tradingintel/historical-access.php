<?php
require __DIR__.'/bootstrap.php';
auth();
require __DIR__.'/master-lib.php';
try {
    if(($_GET['resource']??'')==='historical_context'){
        $dataset=ml_uint($_GET,'dataset',0,2147483647);
        if(!$dataset)$dataset=(int)stmt("SELECT id FROM ti_datasets WHERE status='ready' ORDER BY id DESC LIMIT 1")->fetchColumn();
        if(!$dataset)throw new InvalidArgumentException('Select an imported dataset');
        $loader=function($a,$b)use($dataset){
            $rows=stmt('SELECT ts_et,o,h,l,c,v FROM ti_candles WHERE dataset_id=? AND ts_et>=? AND ts_et<=? ORDER BY ts_et,source_row LIMIT 1000',
                [$dataset,str_replace('T',' ',$a).':00',str_replace('T',' ',$b).':00'])->fetchAll(PDO::FETCH_ASSOC);
            foreach($rows as &$r){$r['ts']=str_replace(' ','T',substr($r['ts_et'],0,16));unset($r['ts_et']);}
            return $rows;
        };
        jsonout(hr_context($_GET,$loader));
    }
    if(($_GET['resource']??'')!=='historical_report')throw new InvalidArgumentException('Unknown resource');
    jsonout(hr_report($_GET));
} catch(InvalidArgumentException $e){http_response_code(400);jsonout(['error'=>$e->getMessage()]);}
catch(Throwable $e){http_response_code(503);jsonout(['error'=>'Historical data unavailable. Check the selected dataset and private v3 snapshot.']);}
