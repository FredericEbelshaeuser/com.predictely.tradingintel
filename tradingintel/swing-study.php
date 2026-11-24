<?php
// Strict pivot, chronological A/B pair, and closed-bar confluence.
function ss_fields($r){return ['O'=>$r[1],'H'=>$r[2],'L'=>$r[3],'C'=>$r[4],'M'=>($r[1]+$r[4])/2];}
function ss_geometry($a,$b,$label){
 $a=ss_fields($a);$b=ss_fields($b);$ink=fn($v)=>$v['C']>$v['O']?'G':($v['C']<$v['O']?'R':'D');
 $pair=$ink($a).$ink($b);$rows=[];foreach(['O','H','L','C'] as $k){$s='';foreach($a as $v)$s.=$b[$k]>$v?'+':($b[$k]<$v?'-':'=');$rows[]=$s;}
 $high=in_array($label,['HH','LH','EH','H'],true);
 $shape=$high?($b['H']>$a['H']?'higher probe':($b['H']==$a['H']?'equal high':($b['H']>max($a['O'],$a['C'])?'upper-wick retest':'below upper wick'))):($b['L']<$a['L']?'lower probe':($b['L']==$a['L']?'equal low':($b['L']<min($a['O'],$a['C'])?'lower-wick retest':'above lower wick')));
 return ['label'=>$label,'pair'=>$pair,'shape'=>$shape,'signature'=>$label.':'.$pair.':'.implode('/',$rows),'a'=>$a,'b'=>$b];}
function ss_matches($g,$q){
 if(!empty($q['ms_exact'])&&$g['signature']!==$q['ms_exact'])return false;
 foreach(['label','pair','shape','ob'] as $k){$v=$q['ms_'.$k]??'*';if($v!=='*'&&$v!==$g[$k])return false;}
 for($n=1;$n<=3;$n++){$op=$q['ms_op'.$n]??'*';if($op==='*')continue;$b=$g['b'][$q['ms_b'.$n]??'C']??null;$a=$g['a'][$q['ms_a'.$n]??'C']??null;if($a===null||$b===null||!match($op){'<' => $b<$a,'='=>$b==$a,'>'=>$b>$a, default=>false})return false;}return true;}
function ss_build($data,$cfg,&$pivots=null){
 $n=(int)($cfg['ms_span']??5);$ttl=(int)($cfg['ms_age']??30);if($n<1||$n>10||$ttl<1||$ttl>500)throw new RuntimeException('Swing span 1–10; chain window 1–500 bars.');
 $order=$cfg['ms_order']??'swing';$stages=match($order){'swing'=>[],'ob'=>['OB'],'fvg'=>['FVG'],'chain'=>['OB','FVG'],default=>throw new RuntimeException('Invalid structure sequence')};
 $obcfg=ob_options($cfg);$last=[1=>null,-1=>null];$chains=[];$run=0;$out=[];$fade=($cfg['ms_bias']??'fade')==='fade';
 foreach($data as $i=>$r){if(!$i||minute_gap($r[0],$data[$i-1][0])){$run=0;$last=[1=>null,-1=>null];$chains=[];}$run++;$events=[];
 if($run>=2*$n+1){$j=$i-$n;foreach([1,-1] as $which){$f=$which===1?2:3;$val=$data[$j][$f];$pivot=true;for($k=$j-$n;$k<=$i;$k++)if($k!==$j&&($which*($data[$k][$f]-$val)>0||($data[$k][$f]==$val&&!((($cfg['ms_ties']??'strict')==='first'&&$k>$j)||(($cfg['ms_ties']??'strict')==='last'&&$k<$j))))){$pivot=false;break;}if(!$pivot)continue;
 $old=$last[$which];$label=$which===1?($old===null?'H':($val>$old?'HH':($val<$old?'LH':'EH'))):($old===null?'L':($val<$old?'LL':($val>$old?'HL':'EL')));$last[$which]=$val;
 $mode=$cfg['ms_anchor']??'auto';$l=$data[$j-1];$p=$data[$j];$rgt=$data[$j+1];$dir=$p[4]-$p[1];$left=($l[4]-$l[1])*$dir<0;$right=($rgt[4]-$rgt[1])*$dir<0;$prefer=$left!==$right?$left:($which===1?$l[2]>$rgt[2]:$l[3]<$rgt[3]);$at=$j-(($mode==='last'||($mode==='auto'&&$prefer))?1:0);$g=ss_geometry($data[$at],$data[$at+1],$label);$a=$data[$at];$b=$data[$at+1];$g['ob']=$a[4]<$a[1]&&$b[4]>$b[1]&&$b[4]>=$a[2]+.25?'BUY OB':($a[4]>$a[1]&&$b[4]<$b[1]&&$b[4]<=$a[3]-.25?'SELL OB':'No OB');if($pivots!==null){$pivots[$i][]=['ob'=>$g['ob'],'pivot'=>$j,'at'=>$i,'which'=>$which,'price'=>$val,'label'=>$label,'pair'=>$g['pair'],'shape'=>$g['shape']];continue;}if(!ss_matches($g,$cfg))continue;
 $s=$fade?-$which:$which;if(($cfg['ms_side']??'both')!== 'both'&&($cfg['ms_side']==='buy'?1:-1)!==$s)continue;
 $chains[$s]=['born'=>$i,'stage'=>0,'at'=>$i,'trace'=>$label.' '.$g['pair'].' '.$g['shape'].' pivot '.$data[$j][0].' confirmed '.$r[0],'sig'=>$g['signature']];if(!$stages)$events[$s]=true;
 }}
 foreach($chains as $s=>&$c){if($i-$c['born']>$ttl){unset($chains[$s]);continue;}if($c['stage']>=count($stages)||$i<=$c['at'])continue;$stage=$stages[$c['stage']];
 if($stage==='OB'){$ob=ob_classify($data,$i,$obcfg);$ok=$ob&&$ob['side']==$s&&ob_matches($ob,$obcfg);}else{$ok=$run>=3&&($s===1?$r[3]>=$data[$i-2][2]+.25:$r[2]<=$data[$i-2][3]-.25);}
 if($ok){$c['stage']++;$c['at']=$i;$c['trace'].=' → '.$stage.' '.$r[0];if($c['stage']===count($stages))$events[$s]=true;}}unset($c);
 foreach($chains as $s=>$c)if($c['stage']===count($stages))$out[$i][$s]=['event'=>isset($events[$s]),'id'=>$c['sig'],'name'=>$c['trace'],'ratio'=>null,'run'=>null,'confirmRange'=>$r[2]-$r[3]];
 }return $out;}
function ss_gate($map,$i,$mode,&$side,&$classification){
 $rows=$map[$i]??[];if(in_array($mode,['swingstudy','pairretest'],true)){$events=array_filter($rows,fn($r)=>$r['event']);$side=count($events)===1?(int)array_key_first($events):0;if($side)$classification=$events[$side];}
 elseif($side){if(!isset($rows[$side]))$side=0;else{$classification??=['id'=>'signal','name'=>$mode,'ratio'=>null,'run'=>null,'confirmRange'=>null];$classification['name'].=' | '.$rows[$side]['name'];}}}

