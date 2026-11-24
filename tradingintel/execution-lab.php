<?php
require_once __DIR__.'/execution-signals.php';
// Work in directional points: a higher value is favourable for either side.
function ti_walk($path,$p,$order,$i,$ts,$cfg){
 [$side,$base,$meta,$level,$offset]=$order;$exit=null;$reason='';$fill=false;
 $enter=function($px)use(&$p,&$fill,$side,$base,$i,$ts,$cfg,$offset){
 $e=$px+($offset>0?0:$cfg['slip']);$sl=$cfg['struct']?$side*$base:$e-$cfg['stop'];
 if($sl>=$e)return false;$p=['e'=>$e,'sl'=>$sl,'initial'=>$sl,'tp'=>$e+$cfg['tp'],'peak'=>$e,'armed'=>false,'i'=>$i,'time'=>$ts];$fill=true;return true;};
 $o=$path[0];if(!$p&&($offset==0||($offset>0?$o<=$level:$o>=$level))&&!$enter($o))return ['invalid',null,null,''];
 if($p){if($o<=$p['sl']){$exit=$o-$cfg['slip'];$reason=$p['armed']?'trailing stop gap':'stop gap';}
 elseif($o>=$p['tp']){$exit=$p['tp'];$reason='target';}
 elseif($i-$p['i']>=$cfg['hold']){$exit=$o-$cfg['slip'];$reason='time';}}
 $ratchet=function($price)use(&$p,$cfg){$p['peak']=max($p['peak'],$price);if($cfg['trail']&&$p['peak']-$p['e']>=$cfg['trigger']){$p['armed']=true;$p['sl']=max($p['sl'],$p['peak']-$cfg['give']);}};
 if($p&&$exit===null)$ratchet($o);
 for($k=1;$k<4&&$exit===null;$k++){$a=$path[$k-1];$b=$path[$k];
 if(!$p){if(!($offset>0?($a>=$level&&$b<=$level):($a<=$level&&$b>=$level)))continue;if(!$enter($level))return ['invalid',null,null,''];$a=$level;}
 if($b<=$p['sl']&&$b<=$a){$exit=min($a,$p['sl'])-$cfg['slip'];$reason=$p['armed']?'trailing stop':'stop';}
 elseif($b>=$p['tp']&&$b>=$a){$exit=$p['tp'];$reason='target';}else $ratchet($b);}
 return [$exit!==null?'closed':($p?'open':'waiting'),$p,$exit,$reason];}
function ti_replay($data,$signal,$sig,$cfg){
 $n=count($data);[$side,$base,$meta]=$sig;$first=$signal+1;$p=null;$amb=0;
 if($first>=$n)return ['status'=>'pending_end','until'=>$signal];$ref=$data[$first][1];$level=$ref*$side-$cfg['offset'];$order=[$side,$base,$meta,$level,$cfg['offset']];
 for($i=$first;$i<$n;$i++){[$ts,$o,$h,$l,$c]=$data[$i];
 if(!$p&&minute_gap($ts,$data[$i-1][0]))return ['status'=>'gap_cancel','until'=>$i,'amb'=>$amb];
 $pa=ti_walk([$side*$o,$side*$h,$side*$l,$side*$c],$p,$order,$i,$ts,$cfg);$pb=ti_walk([$side*$o,$side*$l,$side*$h,$side*$c],$p,$order,$i,$ts,$cfg);
 $score=fn($a)=>!$a[1]?0:($a[2]??($side*$c))-$a[1]['e'];$amb+=($pa!=$pb)?1:0;
 $r=$score($pa)<=$score($pb)?$pa:$pb;[$status,$p,$exit,$reason]=$r;
 if($status==='invalid')return ['status'=>'invalid_stop','until'=>$i,'amb'=>$amb];
 if($status==='closed'){$t=['time'=>$p['time'],'exit_time'=>$ts,'side'=>$side===1?'BUY':'SELL','entry'=>$side*$p['e'],'stop'=>$side*$p['initial'],'target'=>$side*$p['tp'],'exit'=>$side*$exit,'net_points'=>round($exit-$p['e']-$cfg['cost'],4),'reason'=>$reason,'signal_time'=>$data[$signal][0],'reference_open'=>$ref,'final_stop'=>$side*$p['sl'],'trail_armed'=>$p['armed'],'ambiguous_bars'=>$amb];if($meta){$t['pattern_name']=$meta['name'];$t['pattern_id']=$meta['id'];}return ['status'=>'closed','until'=>$i,'trade'=>$t,'amb'=>$amb];}
 if(!$p&&$i-$first+1>=$cfg['expiry'])return ['status'=>'expired','until'=>$i,'amb'=>$amb];}
 return ['status'=>$p?'open_end':'pending_end','until'=>$n-1,'position'=>$p,'amb'=>$amb];}
function backtest(array $data,array $cfg):array{
 unset($cfg['_ssmap']);if(($cfg['mode']??'')==='pairretest')$cfg['_ssmap']=ti_pair_study($data,$cfg);elseif(($cfg['mode']??'')==='swingstudy'||($cfg['ms_gate']??'0')==='1')$cfg['_ssmap']=ss_build($data,$cfg);
 $baseline=ti_original_backtest($data,$cfg);
 $trail=($cfg['trail']??'0')==='1';$offset=(float)($cfg['offset']??0);if(!$trail&&$offset==0)return $baseline;
 $c=['offset'=>$offset,'trail'=>$trail,'trigger'=>(float)($cfg['trigger']??2),'give'=>(float)($cfg['give']??1),'expiry'=>(int)($cfg['expiry']??10),'tp'=>(float)($cfg['target']??10),'stop'=>(float)($cfg['stop']??5),'struct'=>($cfg['stopmode']??'fixed')==='structural','hold'=>(int)($cfg['hold']??20),'slip'=>(float)($cfg['slippage']??.25),'cost'=>(float)($cfg['cost']??.25)];
 if(abs($offset)>1000||$c['expiry']<1||$c['expiry']>500||($trail&&($c['trigger']<=0||$c['give']<=0||$c['give']>$c['trigger'])))throw new RuntimeException('Choose a positive giveback no greater than activation, and a wait of 1–500 bars.');
 foreach(['offset','trigger','give'] as $k)if(!is_finite($c[$k])||abs($c[$k]*4-round($c[$k]*4))>1e-8)throw new RuntimeException('Use NQ quarter-point increments.');
 $signals=ti_signal_list($data,$cfg);$baselineStops=[];$timeIndex=array_flip(array_column($data,0));foreach($baseline['trades'] as $t)if(str_contains($t['reason'],'stop'))$baselineStops[$timeIndex[$t['time']]-1]=true;
 $audit=array_fill_keys(['profitable','loss','flat','expired','gap_cancel','invalid_stop','pending_end','open_end'],0);$entries=$audit;$until=-1;$busy=0;$eq=0;$peak=0;$dd=0;$amb=0;$trades=[];$open=null;$pending=false;
 foreach($signals as $i=>$sig){$available=$i>=$until;if(!$available)$busy++;if(!$available&&!isset($baselineStops[$i]))continue;$r=ti_replay($data,$i,$sig,$c);$status=$r['status'];$pnl=$r['trade']['net_points']??0;$bucket=$status==='closed'?($pnl>0?'profitable':($pnl<0?'loss':'flat')):$status;
 if(isset($baselineStops[$i]))$audit[$bucket]++;if(!$available)continue;$entries[$bucket]++;$until=$status==='open_end'||$status==='pending_end'?count($data):$r['until'];$amb+=$r['amb']??0;
 if($status==='closed'){$t=$r['trade'];$eq+=$pnl;$peak=max($peak,$eq);$dd=max($dd,$peak-$eq);$t['equity']=round($eq,4);$trades[]=$t;}elseif($status==='open_end')$open=$r['position'];elseif($status==='pending_end')$pending=true;}
 return array_merge($baseline,['detected'=>count($signals),'trades'=>$trades,'closed'=>count($trades),'wins'=>$entries['profitable'],'net_points'=>round($eq,2),'max_closed_drawdown'=>round($dd,2),'busy'=>$busy,'ambiguous'=>$amb,'open_position'=>$open,'unfilled_signal'=>$pending,'execution'=>['baseline_net'=>$baseline['net_points'],'baseline_closed'=>$baseline['closed'],'baseline_stops'=>count($baselineStops),'matched'=>$audit,'orders'=>$entries]]);}

