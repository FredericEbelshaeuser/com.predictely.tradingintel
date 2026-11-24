<?php
require_once __DIR__.'/ob-patterns.php';require_once __DIR__.'/wick-groups.php';require_once __DIR__.'/swing-study.php';require_once __DIR__.'/pair-study.php';
function aggregate_bars(array $data,int $tf): array {
 if(!in_array($tf,[1,2,3,5,10,15,30,60,240]))throw new RuntimeException('Invalid timeframe');
 $out=[];$key=null;foreach($data as $r){$date=new DateTimeImmutable($r[0],new DateTimeZone('UTC'));$minute=(int)$date->format('H')*60+(int)$date->format('i');$bucket=intdiv($minute,$tf)*$tf;$stamp=$date->format('Y-m-d').'T'.sprintf('%02d:%02d',intdiv($bucket,60),$bucket%60);
 if($stamp!==$key){$out[]=[$stamp,...array_slice($r,1)];$key=$stamp;}else{$i=count($out)-1;$out[$i][2]=max($out[$i][2],$r[2]);$out[$i][3]=min($out[$i][3],$r[3]);$out[$i][4]=$r[4];$out[$i][5]+=$r[5];}}
 return $out;
}
function minute_gap($a,$b): bool{return (new DateTimeImmutable($a,new DateTimeZone('UTC')))->getTimestamp()-(new DateTimeImmutable($b,new DateTimeZone('UTC')))->getTimestamp()!==60;}
function pattern(array $d,int $i,string $mode,float $ratio): int {
 if($i<1||minute_gap($d[$i][0],$d[$i-1][0]))return 0;
 $b=$d[$i-1];$r=$d[$i];$side=0;
 if($b[4]<$b[1]&&$r[4]>$r[1]&&$r[4]>=$b[2]+.25)$side=1;
 if($b[4]>$b[1]&&$r[4]<$r[1]&&$r[4]<=$b[3]-.25)$side=-1;
 if(!$side||$b[5]<=0||$r[5]/$b[5]<=$ratio)return 0;
 if($mode==='strong')return $side;
 if($i<2||minute_gap($b[0],$d[$i-2][0]))return 0;$a=$d[$i-2];
 if($b[5]>=$a[5])return 0;
 $weakBuy=$a[4]<$a[1]&&$b[4]>$b[1]&&$b[4]>=$a[2]+.25;
 $weakSell=$a[4]>$a[1]&&$b[4]<$b[1]&&$b[4]<=$a[3]-.25;
 return ($side===1&&$weakSell)||($side===-1&&$weakBuy)?$side:0;
}
require_once __DIR__.'/execution-lab.php';
function ti_original_backtest(array $data,array $cfg): array {
 $stop=(float)($cfg['stop']??5);$target=(float)($cfg['target']??10);$ratio=(float)($cfg['ratio']??1);$hold=(int)($cfg['hold']??20);$cost=(float)($cfg['cost']??.25);$slip=(float)($cfg['slippage']??.25);$mode=$cfg['mode']??'strong';$stopmode=$cfg['stopmode']??'fixed';
 if(!($stop>0&&$stop<=1000&&$target>0&&$target<=1000&&$ratio>=0&&$ratio<=100&&$hold>=1&&$hold<=500&&$cost>=0&&$cost<=100&&$slip>=0&&$slip<=100)||!in_array($mode,['strong','weakflip','obtypes','cgroups','swingstudy','pairretest'])||!in_array($stopmode,['fixed','structural']))throw new RuntimeException('Invalid backtest parameters');
 $group=null;$n1=(int)($cfg['n1']??3);$n3=(int)($cfg['n3']??4);if($mode==='cgroups'&&($n1<1||$n1>100||$n3<1||$n3>100))throw new RuntimeException('Group counts must be 1–100');$obCfg=$mode==='obtypes'?ob_options($cfg):null;
 $trades=[];$position=null;$pending=null;$detected=0;$busy=0;$equity=0;$peak=0;$dd=0;$ambiguous=0;$gaps=0;
 foreach($data as $i=>$r){[$ts,$o,$h,$l,$c,$v]=$r;$gap=$i>0&&minute_gap($ts,$data[$i-1][0]);if($gap){$gaps++;$pending=null;}
 if($pending&&!$position){[$side,$base]=$pending;$entry=$o+$side*$slip;$sl=$stopmode==='fixed'?$entry-$side*$stop:$base;if($side*($entry-$sl)>0)$position=['side'=>$side,'entry'=>$entry,'sl'=>$sl,'tp'=>$entry+$side*$target,'start'=>$i,'time'=>$ts,'pattern'=>$pending[2]??null];$pending=null;}
 if($position){$p=$position;$s=$p['side'];$exit=null;$reason='';
 if($s*($o-$p['sl'])<=0){$exit=$o-$s*$slip;$reason='stop gap';}
 elseif($s*($o-$p['tp'])>=0){$exit=$p['tp'];$reason='target';}
 elseif($i-$p['start']>=$hold){$exit=$o-$s*$slip;$reason='time';}
 else{$hitSL=$s===1?$l<=$p['sl']:$h>=$p['sl'];$hitTP=$s===1?$h>=$p['tp']:$l<=$p['tp'];if($hitSL){$exit=$p['sl']-$s*$slip;$reason=$hitTP?'both touched: stop first':'stop';if($hitTP)$ambiguous++;}elseif($hitTP){$exit=$p['tp'];$reason='target';}}
 if($exit!==null){$pnl=$s*($exit-$p['entry'])-$cost;$equity+=$pnl;$peak=max($peak,$equity);$dd=max($dd,$peak-$equity);$trades[]=['time'=>$p['time'],'exit_time'=>$ts,'side'=>$s===1?'BUY':'SELL','entry'=>$p['entry'],'stop'=>$p['sl'],'target'=>$p['tp'],'exit'=>$exit,'net_points'=>round($pnl,4),'equity'=>round($equity,4),'reason'=>$reason];if(in_array($mode,['obtypes','cgroups','swingstudy','pairretest'],true)||isset($cfg['_ssmap'])){$k=count($trades)-1;$meta=$p['pattern'];$trades[$k]['pattern_id']=$meta['id'];$trades[$k]['pattern_name']=$meta['name'];$trades[$k]['volume_ratio']=$meta['ratio'];$trades[$k]['prior_run']=$meta['run'];$trades[$k]['confirmation_range']=$meta['confirmRange'];}$position=null;}}
 $classification=$mode==='obtypes'?ob_classify($data,$i,$obCfg):null;$side=$mode==='cgroups'?0:($mode==='obtypes'?(ob_matches($classification,$obCfg)?$classification['side']:0):pattern($data,$i,$mode,$ratio));
 if($mode==='cgroups'){$g=ti_group_step($data,$i,$group);$side=ti_group_signal($g,$cfg,$n1,$n3);if($side)$classification=['id'=>'FVG-'.$ts,'name'=>($cfg['cgMode']??'count').' / '.($g['vol']??'unknown').' V / C1×'.$n1.' / C2 / C3×'.$n3,'ratio'=>null,'run'=>$n1,'confirmRange'=>$h-$l];}
 if(isset($cfg['_ssmap']))ss_gate($cfg['_ssmap'],$i,$mode,$side,$classification);
 if($side){$detected++;if($position)$busy++;else $pending=[$side,$side===1?min($data[$i-1][3],$l)-.25:max($data[$i-1][2],$h)+.25,$classification];}}
 if($mode!=='obtypes'&&$position)unset($position['pattern']);
 return ['trades'=>$trades,'detected'=>$detected,'busy'=>$busy,'closed'=>count($trades),'wins'=>count(array_filter($trades,fn($t)=>$t['net_points']>0)),'net_points'=>round($equity,2),'max_closed_drawdown'=>round($dd,2),'ambiguous'=>$ambiguous,'gaps'=>$gaps,'open_position'=>$position,'unfilled_signal'=>(bool)$pending];
}
