<?php
function ti_signal_list($data,$cfg){
 $mode=$cfg['mode']??'strong';$ratio=(float)($cfg['ratio']??1);$obCfg=$mode==='obtypes'?ob_options($cfg):null;
 $group=null;$n1=(int)($cfg['n1']??3);$n3=(int)($cfg['n3']??4);$out=[];
 foreach($data as $i=>$r){[$ts,$o,$h,$l,$c,$v]=$r;
 $classification=$mode==='obtypes'?ob_classify($data,$i,$obCfg):null;$side=$mode==='cgroups'?0:($mode==='obtypes'?(ob_matches($classification,$obCfg)?$classification['side']:0):pattern($data,$i,$mode,$ratio));
 if($mode==='cgroups'){$g=ti_group_step($data,$i,$group);$side=ti_group_signal($g,$cfg,$n1,$n3);if($side)$classification=['id'=>'FVG-'.$ts,'name'=>($cfg['cgMode']??'count').' / '.($g['vol']??'unknown').' V / C1×'.$n1.' / C2 / C3×'.$n3,'ratio'=>null,'run'=>$n1,'confirmRange'=>$h-$l];}
 if(isset($cfg['_ssmap']))ss_gate($cfg['_ssmap'],$i,$mode,$side,$classification);
 if($side)$out[$i]=[$side,$side===1?min($data[$i-1][3],$l)-.25:max($data[$i-1][2],$h)+.25,$classification];}
 return $out;
}
