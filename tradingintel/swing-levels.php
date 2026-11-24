<?php
// Independent, on-demand inventory. No sequence filter and no precomputed trade labels required.
function sv_scan(array $bars,string $day,int $horizon,int $reaction,int $offset): array {
    $swings=[];$lastH=$lastL=null;$N=count($bars);
    for($i=0;$i<$N;$i++){
        if($i&&ml_time($bars[$i-1]['ts'],1)!==$bars[$i]['ts'])$lastH=$lastL=null;
        if($i<10)continue;
        $continuous=true;for($k=$i-9;$k<=$i;$k++)if(ml_time($bars[$k-1]['ts'],1)!==$bars[$k]['ts']){$continuous=false;break;}
        if(!$continuous)continue;
        $p=$i-5;
        foreach([1,-1] as $side){
            $field=$side===1?'h':'l';$price=(float)$bars[$p][$field];$ok=true;
            for($k=$i-10;$k<=$i;$k++)if($k!==$p&&($side===1?$bars[$k][$field]>=$price:$bars[$k][$field]<=$price)){$ok=false;break;}
            if(!$ok)continue;
            $last=$side===1?$lastH:$lastL;
            $label=$last===null?($side===1?'H':'L'):($price===$last?($side===1?'EH':'EL'):($side===1?($price>$last?'HH':'LH'):($price<$last?'LL':'HL')));
            if($side===1)$lastH=$price;else $lastL=$price;
            if(substr($bars[$i]['ts'],0,10)!==$day)continue;
            $l=$bars[$p-1];$r=$bars[$p+1];$v=$bars[$p];
            $lo=($l['c']-$l['o'])*($v['c']-$v['o'])<0;$ro=($r['c']-$r['o'])*($v['c']-$v['o'])<0;
            $left=$lo!==$ro?$lo:($side===1?$l['h']>$r['h']:$l['l']<$r['l']);
            $a=$bars[$left?$p-1:$p];$bb=$bars[$left?$p:$p+1];
            $ink=fn($x)=>$x['c']>$x['o']?'G':($x['c']<$x['o']?'R':'D');
            $shape=$side===1?($bb['h']>$a['h']?'higher probe':($bb['h']==$a['h']?'equal high':($bb['h']>max($a['o'],$a['c'])?'upper-wick retest':'below upper wick'))):($bb['l']<$a['l']?'lower probe':($bb['l']==$a['l']?'equal low':($bb['l']<min($a['o'],$a['c'])?'lower-wick retest':'above lower wick')));
            $ob=$a['c']<$a['o']&&$bb['c']>$bb['o']&&$bb['c']>=$a['h']+.25?'BUY OB':($a['c']>$a['o']&&$bb['c']<$bb['o']&&$bb['c']<=$a['l']-.25?'SELL OB':'No OB');
            $end=$i;while($end<$i+$horizon&&$end+1<$N&&ml_time($bars[$end]['ts'],1)===$bars[$end+1]['ts'])$end++;
            $levels=[];
            foreach(['A'=>$a,'B'=>$bb] as $source=>$candle)foreach(['open'=>'o','high'=>'h','low'=>'l','close'=>'c'] as $name=>$f){
                $level=(float)$candle[$f]+$offset*.25;$hit=null;$visits=0;$prevInside=false;
                for($j=$i+1;$j<=$end;$j++){
                    $inside=$bars[$j]['l']<=$level&&$bars[$j]['h']>=$level;
                    if($inside&&!$prevInside&&(float)$bars[$j-1]['c']!==$level){$visits++;if($hit===null)$hit=$j;}
                    $prevInside=$inside;
                }
                $react=null;
                if($hit!==null){
                    $re=min($end,$hit+$reaction);$up=$down=0;
                    for($j=$hit+1;$j<=$re;$j++){$up=max($up,$bars[$j]['h']-$level);$down=max($down,$level-$bars[$j]['l']);}
                    $react=['bars'=>$re-$hit,'complete'=>$re-$hit===$reaction,'max_up_points'=>$up,'max_down_points'=>$down,'close_delta_points'=>(float)$bars[$re]['c']-$level,'touch_close_delta_points'=>(float)$bars[$hit]['c']-$level];
                }
                $levels[]=['source'=>$source,'color'=>$ink($candle),'field'=>$name,'source_bar_et'=>$candle['ts'],'source_price'=>(float)$candle[$f],'offset_ticks'=>$offset,'price'=>$level,'first_visit_et'=>$hit===null?null:$bars[$hit]['ts'],'bars_to_visit'=>$hit===null?null:$hit-$i,'visit_episodes'=>$visits,'reaction'=>$react];
            }
            $swings[]=['id'=>$v['ts'].'|'.$label,'label'=>$label,'pair'=>$ink($a).$ink($bb),'shape'=>$shape,'ob'=>$ob,'pivot_et'=>$v['ts'],'confirmation_bar_et'=>$bars[$i]['ts'],'available_at_et'=>ml_time($bars[$i]['ts'],1),'price'=>$price,'observation_bars'=>$end-$i,'complete_observation'=>$end-$i===$horizon,'observed_through_et'=>$bars[$end]['ts'],'candles'=>['A'=>$a,'B'=>$bb],'levels'=>$levels];
        }
    }
    return $swings;
}
function sv_result(array $q,?callable $loader=null):array {
    $day=$q['day']??$q['start']??substr(ml_meta()['first'],0,10);
    if(!is_string($day)||!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$day)||ml_dt($day)->format('Y-m-d')!==$day)throw new InvalidArgumentException('Invalid day');
    $h=max(1,ml_uint($q,'horizon',120,240));$r=max(1,ml_uint($q,'reaction',10,60));
    $off=$q['offset']??0;if(!in_array((string)$off,['-1','0','1'],true))throw new InvalidArgumentException('Offset must be -1, 0 or 1');
    $from=ml_dt($day)->modify('-1 day')->format('Y-m-d').'T00:00';$to=ml_time($day.'T00:00',1440+$h);
    $bars=$loader?$loader($from,$to):ml_rows('SELECT ts,o,h,l,c,v FROM candles WHERE ts>=? AND ts<=? ORDER BY ts,id LIMIT 4001',[$from,$to]);
    if(count($bars)>4000)throw new InvalidArgumentException('Too many candles or duplicate timestamps');
    foreach($bars as &$v)foreach(['o','h','l','c','v'] as $f)$v[$f]=(float)$v[$f];unset($v);
    return ['resource'=>'swing_levels','source'=>$loader?'selected imported dataset':'master source candles (independent of saved-run rules)','day'=>$day,'next_day'=>ml_dt($day)->modify('+1 day')->format('Y-m-d'),'horizon'=>$h,'reaction_bars'=>$r,'offset_ticks'=>(int)$off,'definitions'=>['strict 5 candles each side; opposite colour first; A/B chronological','Levels become available at confirmation close; visits start on following candle','A visit spans the exact tested price and previous close differs. A new episode requires an intervening candle not spanning it.','Reaction uses bars AFTER first touch, bounded by observation end. Distances are not profits or causal evidence.','Equal-price OHLC references stay separate; do not sum their visit counts. Missing minutes end observation.','One day of warmup; initial H/L means no earlier same-side pivot within uninterrupted warmup.'], 'swings'=>sv_scan($bars,$day,$h,$r,(int)$off),'bars'=>array_values(array_filter($bars,fn($v)=>$v['ts']>=ml_time($day.'T00:00',-20)))];
}
