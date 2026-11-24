<?php
function ti_group_step(array $bars,int $i,?array &$g): ?array {
$g=ti_next_wicks($bars,$i,$g);return $g;}
function ti_next_wicks($bars,$i,$g){
if($i===0||!!minute_gap($bars[$i][0],$bars[$i-1][0]))return null;
$b=$bars[$i];$a=$bars[$i-1];$c=$i>=2&&!minute_gap($bars[$i-1][0],$bars[$i-1-1][0])?$bars[$i-2]:null;
$ob=$b[4]>$b[1]&&$b[4]>=$a[2]+.25?1:($b[4]<$b[1]&&$b[4]<=$a[3]-.25?-1:0);
$vol=$b[5]>$a[5]?'higher':($b[5]<$a[5]?'lower':'equal');
$fresh=$c?($b[3]>=$c[2]+.25?1:($b[2]<=$c[3]-.25?-1:0)):0;
$seed=$g?$g['seed']:-1;$prior=$g?$g['prior']:-1;$sv=$g?$g['sv']:'unknown';$pv=$g?$g['pv']:'unknown';
$w=$g?$g['w']:null;$tap=0;$tn1=0;$tn3=0;$tv='unknown';
if($w&&$i>$w['born']){if(($w['side']===1&&$b[4]<$w['lo'])||($w['side']===-1&&$b[4]>$w['hi']))$w=null;
else if($b[3]<=$w['hi']&&$b[2]>=$w['lo']&&($w['side']===1?($b[3]<$w['hi']||$a[3]>$w['hi']):($b[2]>$w['lo']||$a[2]<$w['lo']))&&($w['side']===1?$b[4]>$w['hi']:$b[4]<$w['lo'])){$tap=$w['side'];$tn1=$w['n1'];$tn3=$w['n3'];$tv=$w['v'];$w=null;}}
$first=$i;$bridge=$i;$born=$i;$n1=1;$n3=0;$side=$ob;$hi=$b[2];$lo=$b[3];$ext=0;$v=$sv;$phase=0;$raised=false;
if($ob&&!$fresh){$phase=1;$prior=$seed;$pv=$sv;$seed=$i;$sv=$vol;$v=$vol;if($g&&$g['phase']===3&&!$fresh&&$c&&max($c[3],$b[3])<=min($c[2],$b[2]))$first=max($g['born'],$i-2);$n1=$i-$first+1;}
else if($fresh){$phase=3;$side=$fresh;$first=$i-2;$hi=$c[2];$lo=$c[3];$bridge=$i-1;
$origin=$seed<=$first?$seed:$prior;$v=$seed<=$first?$sv:$pv;$floor=$origin>=0&&$origin<$first?$origin+1:max(0,$origin);
while($first>$floor&&$i-2-$first<99){$j=$first-1;$q=$bars[$j];$r=$bars[$first];if(!!minute_gap($bars[$first][0],$bars[$first-1][0])||max($q[3],$r[3])>min($q[2],$r[2])||($first+1<=$i-2&&max($q[3],$bars[$first+1][3])>min($q[2],$bars[$first+1][2]))||($side===1?$q[2]>$b[3]-.25:$q[3]<$b[2]+.25))break;$hi=max($hi,$q[2]);$lo=min($lo,$q[3]);$first--;}
$n1=$i-1-$first;$n3=1;$w=['born'=>$i,'side'=>$side,'lo'=>$side===1?$hi:$b[2],'hi'=>$side===1?$b[3]:$lo,'n1'=>$n1,'n3'=>$n3,'v'=>$v];}
else if($g&&$g['phase']===3&&$c&&max($b[3],$a[3])<=min($b[2],$a[2])&&($g['n3']===1||max($b[3],$c[3])<=min($b[2],$c[2]))&&($g['side']===1?$b[3]>$g['hi']:$b[2]<$g['lo'])){$phase=3;$side=$g['side'];$first=$g['first'];$bridge=$g['bridge'];$born=$g['born'];$hi=$g['hi'];$lo=$g['lo'];$n1=$g['n1'];$n3=$g['n3']+1;$v=$g['v'];$raised=$g['raised'];if($w)$w['n3']=$n3;}
else if($g&&$g['phase']===1&&max($a[3],$b[3])<=min($a[2],$b[2])&&(!$c||$g['n1']===1||max($c[3],$b[3])<=min($c[2],$b[2]))){$phase=1;$side=$g['side'];$first=$g['first'];$n1=$g['n1']+1;}
if($phase===3&&!$raised&&($side===1?$b[4]>$bars[$bridge][2]:$b[4]<$bars[$bridge][3])){$ext=-$side;$raised=true;}
return ['seed'=>$seed,'prior'=>$prior,'sv'=>$sv,'pv'=>$pv,'w'=>$w,'tap'=>$tap,'tn1'=>$tn1,'tn3'=>$tn3,'tv'=>$tv,'first'=>$first,'bridge'=>$bridge,'born'=>$born,'n1'=>$n1,'n3'=>$n3,'side'=>$side,'hi'=>$hi,'lo'=>$lo,'ext'=>$ext,'v'=>$v,'phase'=>$phase,'raised'=>$raised,'ob'=>$ob,'vol'=>$vol];
}
function ti_group_signal($g,$cfg,$n1,$n3){if(!$g)return 0;$kind=$cfg['cgMode']??'count';$which=$cfg['cgVol']??'any';$volume=$kind==='tap'?$g['tv']:($kind==='ob'?$g['vol']:$g['v']);if($which!=='any'&&$which!==$volume)return 0;if($kind==='ob')return $g['ob'];if($kind==='tap')return $g['tn1']===$n1&&$g['tn3']===$n3?$g['tap']:0;if($g['phase']!==3||$g['n1']!==$n1||$g['n3']!==$n3)return 0;return $kind==='reversal'?$g['ext']:$g['side'];}
