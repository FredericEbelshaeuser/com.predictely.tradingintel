<?php
require __DIR__.'/bootstrap.php';auth();session_write_close();
try{
 $id=(int)($_GET['dataset']??0);if(!$id)$id=(int)stmt("SELECT id FROM ti_datasets WHERE status='ready' ORDER BY id DESC LIMIT 1")->fetchColumn();
 if(!stmt("SELECT id FROM ti_datasets WHERE id=? AND status='ready'",[$id])->fetchColumn())throw new RuntimeException('Select a ready dataset');
 $tz=new DateTimeZone('UTC');$dates=[];foreach(['start','end'] as $k){$v=$_GET[$k]??'';$d=DateTimeImmutable::createFromFormat('!Y-m-d',$v,$tz);if(!$d||$d->format('Y-m-d')!==$v)throw new RuntimeException('Invalid date');$dates[]=$d;}[$a,$b]=$dates;if($a>$b||$a->diff($b)->days>31)throw new RuntimeException('Maximum 32 calendar dates');
 $next=$b->modify('+1 day');$periods=[];$memo=[];
 if(!empty($_GET['periods']))for($d=$a;$d<$next;$d=$d->modify('+1 day')){
 $day=$d->format('Y-m-d');$week=$d->modify('monday this week');$month=$d->modify('first day of this month');$quarter=$d->setDate((int)$d->format('Y'),1+3*intdiv((int)$d->format('n')-1,3),1);
 foreach(['day'=>[$d->modify('-1 day'),$d],'week'=>[$week->modify('-7 days'),$week],'month'=>[$month->modify('-1 month'),$month],'quarter'=>[$quarter->modify('-3 months'),$quarter]] as $k=>[$start,$end]){
 $key=$start->format('Y-m-d').'/'.$end->format('Y-m-d');if(!array_key_exists($key,$memo)){$pair=stmt('SELECT MAX(h),MIN(l) FROM ti_candles WHERE dataset_id=? AND ts_et>=? AND ts_et<?',[$id,$start->format('Y-m-d'),$end->format('Y-m-d')])->fetch(PDO::FETCH_NUM);$memo[$key]=$pair[0]===null?null:[(float)$pair[0],(float)$pair[1],((float)$pair[0]+(float)$pair[1])/2];}$periods[$day][$k]=$memo[$key];}}
 $zones=[];$tfs=array_values(array_intersect([5,10,15,60,240,1440],array_map('intval',explode(',',$_GET['tf']??''))));
 if($tfs){$streams=[];$prior=[];$active=[];
 $finish=function($tf,$r)use(&$prior,&$zones,&$active){$p=$prior[$tf]??null;$at=$r['t']+$tf*60;
 foreach($active[$tf]??[] as $key=>$z){if($z['s']===1?$r['c']<$z['lo']:$r['c']>$z['hi']){$zones[$key]['end']=$at;unset($active[$tf][$key]);}}
 if($p && ($tf===1440||$r['t']-$p['t']===$tf*60)){$side=$p['c']<$p['o']&&$r['c']>$r['o']&&$r['c']>=$p['h']+.25?1:($p['c']>$p['o']&&$r['c']<$r['o']&&$r['c']<=$p['l']-.25?-1:0);if($side){$z=['tf'=>$tf,'s'=>$side,'lo'=>$p['l'],'hi'=>$p['h'],'at'=>$at,'end'=>null];$idx=count($zones);$zones[]=$z;$active[$tf][$idx]=$z;}}$prior[$tf]=$r;};
 $q=stmt('SELECT ts_et,o,h,l,c FROM ti_candles WHERE dataset_id=? AND ts_et>=? AND ts_et<? ORDER BY ts_et,source_row',[$id,$a->modify('-35 days')->format('Y-m-d'),$next->format('Y-m-d')]);$last=0;
 while($row=$q->fetch(PDO::FETCH_NUM)){[$ts,$o,$h,$l,$c]=$row;$t=strtotime($ts.' UTC');$last=$t;foreach($tfs as $tf){$bucket=intdiv($t,$tf*60)*$tf*60;if(!isset($streams[$tf])||$streams[$tf]['t']!==$bucket){if(isset($streams[$tf]))$finish($tf,$streams[$tf]);$streams[$tf]=['t'=>$bucket,'o'=>(float)$o,'h'=>(float)$h,'l'=>(float)$l,'c'=>(float)$c];}else{$streams[$tf]['h']=max($streams[$tf]['h'],(float)$h);$streams[$tf]['l']=min($streams[$tf]['l'],(float)$l);$streams[$tf]['c']=(float)$c;}}}
 foreach($streams as $tf=>$r)if($r['t']+$tf*60<=$last+60)$finish($tf,$r);
 $zones=array_values(array_filter($zones,fn($z)=>$z['end']===null||$z['end']>=$a->getTimestamp()));}
 jsonout(['periods'=>$periods,'zones'=>$zones]);
}catch(Throwable $e){http_response_code(400);jsonout(['error'=>$e instanceof PDOException?'Context data unavailable':$e->getMessage()]);}

