<?php
function ti_pair_study($data,$cfg,&$audit=null){
 $piv=[];$dummy=ss_build($data,['ms_span'=>$cfg['ms_span']??5,'ms_anchor'=>$cfg['ms_anchor']??'auto','ms_ties'=>$cfg['ms_ties']??'strict'],$piv);unset($dummy);
 $age=(int)($cfg['pr_age']??30);$between=(int)($cfg['pr_between']??100);$tol=(int)($cfg['pr_tol']??0);if($age<1||$age>500||$between<1||$between>500||$tol<0||$tol>100)throw new RuntimeException('Invalid pair window/tolerance');
 $skip=(int)($cfg['pr_skip']??0);if($skip<0||$skip>2)throw new RuntimeException('Maximum skipped pivots must be 0–2');$history=[];$watch=null;$out=[];$flags=[];foreach($data as $i=>$r){if($i&&minute_gap($r[0],$data[$i-1][0])){$history=[];$watch=null;}
 if($watch!==null){$ageNow=$i-$watch['at'];$s=$watch['side'];$level=$watch['price'];
 if($ageNow>$age){$watch=null;}elseif($r[3]<=$level+$tol*.25&&$r[2]>=$level-$tol*.25&&$s*($r[4]-$level)>0){$w=$watch;$w['hit']=$i;$audit!==null&&array_push($audit,$w);$out[$i][$s]=['event'=>true,'id'=>'pair-'.$data[$watch['at']][0],'name'=>$w['name'].' → retest '.$r[0].' @ '.$level,'ratio'=>null,'run'=>null,'confirmRange'=>$r[2]-$r[3]];$watch=null;}}
 if(!isset($piv[$i]))continue;$items=$piv[$i];if(count($items)!==1){$history=[];$watch=null;continue;}$now=$items[0];$watch=null;
 foreach(array_reverse($history) as $skipped=>$last)if($i-$last['at']<=$between){$ok=true;foreach([1=>$last,2=>$now] as $k=>$v)foreach(['label','pair','shape','ob'] as $f){$default=[1=>['label'=>'LH','pair'=>'GR','shape'=>'upper-wick retest'],2=>['label'=>'LL','pair'=>'RG','shape'=>'lower-wick retest']];$want=$cfg['pr_'.$k.$f]??($default[$k][$f]??'*');if($want!=='*'&&$want!==$v[$f])$ok=false;}
 if($ok){$watch=['skipped'=>$skipped,'pivot1'=>$last['pivot'],'pivot2'=>$now['pivot'],'price1'=>$last['price'],'at'=>$i,'first'=>$last['at'],'side'=>-$now['which'],'price'=>$now['price'],'name'=>$last['label'].' '.$last['pair'].' '.$last['shape'].' → '.$now['label'].' '.$now['pair'].' '.$now['shape']];if($audit!==null)$audit[]=$watch;break;}}
 $history[]=$now;if(count($history)>$skip+1)array_shift($history);
 }return $out;
}
