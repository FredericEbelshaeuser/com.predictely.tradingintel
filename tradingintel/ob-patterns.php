<?php
function ob_options(array $q): array {
 $d=['breakBasis'=>'wick','family'=>'any','volume'=>'any','size'=>'any','runMin'=>1,'runMax'=>100,'breakMin'=>1,'breakMax'=>10000,'rangeMin'=>0,'rangeMax'=>10000,'baseWick'=>'any','openBody'=>'any','openVsClose'=>'any','highRelation'=>'any','lowRelation'=>'any','bodySize'=>'any'];
 foreach($d as $k=>$v)if(isset($q['ob_'.$k])){if(is_numeric($v)&&!is_numeric($q['ob_'.$k]))throw new RuntimeException('Invalid numeric OB filter');$d[$k]=is_numeric($v)?(float)$q['ob_'.$k]:(string)$q['ob_'.$k];}
 foreach(['breakBasis'=>['wick','body'],'family'=>['any','direct','sweep','gap','buy-sweep','sell-sweep','buy-gap','sell-gap','engulf','inside','shallow'],'volume'=>['any','lower','equal','higher'],'size'=>['any','lower','equal','higher'],'baseWick'=>['any','none','present'],'openBody'=>['any','above','inside','below'],'openVsClose'=>['any','higher','equal','lower'],'highRelation'=>['any','higher','equal','lower'],'lowRelation'=>['any','higher','equal','lower'],'bodySize'=>['any','higher','equal','lower']] as $k=>$values)if(!in_array($d[$k],$values,true))throw new RuntimeException('Invalid OB filter '.$k);
 foreach(['run','break','range'] as $k)if(!is_finite($d[$k.'Min'])||!is_finite($d[$k.'Max'])||$d[$k.'Min']<($k==='range'?0:1)||$d[$k.'Max']>($k==='run'?100:10000)||$d[$k.'Min']>$d[$k.'Max'])throw new RuntimeException('Invalid min/max OB '.$k.' filter');
 return $d;
}
function ob_relation(float $a,float $b): string{return $a>$b+1e-9?'higher':($a<$b-1e-9?'lower':'equal');}
function ob_classify(array $bars,int $i,array $cfg): ?array {
 if($i<1||minute_gap($bars[$i][0],$bars[$i-1][0]))return null;$a=$bars[$i-1];$b=$bars[$i];
 $side=$a[4]<$a[1]&&$b[4]>$b[1]?1:($a[4]>$a[1]&&$b[4]<$b[1]?-1:0);if(!$side)return null;
 $edge=$cfg['breakBasis']==='body'?($side===1?max($a[1],$a[4]):min($a[1],$a[4])):($side===1?$a[2]:$a[3]);$ticks=$side*($b[4]-$edge)/.25;if($ticks<1-1e-9)return null;
 $gap=$side===1?$b[1]<=$a[3]-.25:$b[1]>=$a[2]+.25;$sweep=$side===1?$b[3]<=$a[3]-.25:$b[2]>=$a[2]+.25;$family=$gap?'gap':($sweep?'sweep':'direct');
 $name=$family==='gap'?($side===1?'Gap-open reclaim':'Gap-open rejection'):($family==='sweep'?($side===1?'Sweep reclaim':'Sweep rejection'):'Direct break');
 $run=0;$runV=0;for($j=$i-1;$j>=0&&$run<100;$j--){$r=$bars[$j];if(($side===1?$r[4]>=$r[1]:$r[4]<=$r[1])||minute_gap($bars[$j+1][0],$r[0]))break;$run++;$runV+=$r[5];}
 return ['id'=>($side===1?'B':'S').'-1-'.$b[0],'engulf'=>min($b[1],$b[4])<=min($a[1],$a[4])&&max($b[1],$b[4])>=max($a[1],$a[4]),'openBody'=>$b[1]>max($a[1],$a[4])?'above':($b[1]<min($a[1],$a[4])?'below':'inside'),'openVsClose'=>ob_relation($b[1],$a[4]),'highRelation'=>ob_relation($b[2],$a[2]),'lowRelation'=>ob_relation($b[3],$a[3]),'bodySize'=>ob_relation(abs($b[4]-$b[1]),abs($a[4]-$a[1])),'side'=>$side,'family'=>$family,'name'=>$name,'run'=>$run,'runV'=>$runV,'ratio'=>$a[5]>0?$b[5]/$a[5]:null,'volume'=>$a[5]>0?ob_relation($b[5],$a[5]):'unknown','size'=>ob_relation($b[2]-$b[3],$a[2]-$a[3]),'breakTicks'=>$ticks,'confirmRange'=>$b[2]-$b[3],'baseRange'=>$a[2]-$a[3],'baseWick'=>$side===1?min($a[1],$a[4])-$a[3]:$a[2]-max($a[1],$a[4])];
}
function ob_matches(?array $p,array $c): bool {
 if(!$p)return false;foreach(['openBody','openVsClose','highRelation','lowRelation','bodySize'] as $k)if($c[$k]!=='any'&&$p[$k]!==$c[$k])return false;
 return ($c['family']==='any'||$p['family']===$c['family']||$c['family']===(($p['side']===1?'buy-':'sell-').$p['family'])||($c['family']==='engulf'&&$p['engulf'])||($c['family']==='inside'&&$p['openBody']==='inside')||($c['family']==='shallow'&&$p['breakTicks']<=2))&&($c['volume']==='any'||$p['volume']===$c['volume'])&&($c['size']==='any'||$p['size']===$c['size'])&&$p['run']>=$c['runMin']&&$p['run']<=$c['runMax']&&$p['breakTicks']>=$c['breakMin']&&$p['breakTicks']<=$c['breakMax']&&$p['confirmRange']>=$c['rangeMin']&&$p['confirmRange']<=$c['rangeMax']&&($c['baseWick']==='any'||($c['baseWick']==='none'?$p['baseWick']<=1e-9:$p['baseWick']>1e-9));
}
