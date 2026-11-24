<?php
// Closed-bar cluster detector. Each C3 group owns its bars until overlap ends.
function ti_group_step(array $bars,int $i,?array &$g): ?array {
 $b=$bars[$i];if($i===0||minute_gap($b[0],$bars[$i-1][0])){$g=null;return null;}
 $a=$bars[$i-1];$fresh=$i>=2&&!minute_gap($a[0],$bars[$i-2][0])&&($b[3]>=$bars[$i-2][2]+.25||$b[2]<=$bars[$i-2][3]-.25);if(!$fresh && $g && max($b[3],$a[3])<=min($b[2],$a[2]) && ($g['side']===1?$b[3]>$g['hi']:$b[2]<$g['lo'])){$g['n3']++;return $g;}
 $g=null;if($i<2||minute_gap($a[0],$bars[$i-2][0]))return null;$c=$bars[$i-2];
 $side=$b[3]>=$c[2]+.25?1:($b[2]<=$c[3]-.25?-1:0);if(!$side)return null;
 $lo=$c[3];$hi=$c[2];$j=$i-2;
 while($j>0 && $i-2-$j<99){$q=$bars[$j-1];$r=$bars[$j];if(minute_gap($r[0],$q[0])||max($q[3],$r[3])>min($q[2],$r[2])||($side===1?$q[2]>$b[3]-.25:$q[3]<$b[2]+.25))break;$lo=min($lo,$q[3]);$hi=max($hi,$q[2]);$j--;}
 return $g=['side'=>$side,'first'=>$j,'bridge'=>$i-1,'born'=>$i,'n1'=>$i-1-$j,'n3'=>1,'lo'=>$lo,'hi'=>$hi];
}
