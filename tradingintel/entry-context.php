<?php
function ec_labels(int $flag): array {
    $names=['None','BUY OB','SELL OB'];
    $a=$flag&3;$b=($flag>>2)&3;
    return ['latest'=>$names[$a]??'None','previous'=>$names[$b]??'None','two_agree'=>$a>0&&$a===$b];
}
function ec_report(array $q): array {
    if(!isset(ml_meta()['entry_context']))throw new InvalidArgumentException('Upload the v3 snapshot for pre-entry context research');
    [$a,$b]=ml_range($q);
    $sid=ml_uint($q,'sid',0,10000000);
    $rid=ml_uint($q,'rid',0,count(ml_rules())-1);
    [$extra,$arguments]=fl_where($q,$rid);
    $where='first_day>=? AND end_day<=? AND complete=1';
    $args=[$a,$b];
    if($sid){$where.=' AND sid=?';$args[]=$sid;}
    $s=ml_db()->prepare("SELECT o.* FROM occ o WHERE $where $extra ORDER BY id");
    $s->execute([...$args,...$arguments]);
    $groups=[];$policies=[];
    foreach(['Always BUY','Always SELL','Follow latest OB','Follow latest two agreeing OBs'] as $name)$policies[$name]=['trades'=>0,'wins'=>0,'net_usd'=>0];
    $fills=0;
    while($row=$s->fetch()){
        $raw=ml_raw($row);$trade=ml_decode($row,$raw,$rid);
        if($trade['status']!=='filled')continue;
        $fills++;
        $at=ord($raw[$rid*20+1]);
        $flag=isset($row['entry_context'][$at])?ord($row['entry_context'][$at]):0;
        $context=ec_labels($flag);$latest=$flag&3;
        $key=$context['previous'].' → '.$context['latest'];
        $groups[$key]??=['context'=>$key,'n'=>0,'buy_wins'=>0,'sell_wins'=>0,'buy_net'=>0,'sell_net'=>0];
        $groups[$key]['n']++;
        foreach(['buy','sell'] as $side){$groups[$key][$side.'_wins']+=($trade[$side]['net_usd']>0);$groups[$key][$side.'_net']+=$trade[$side]['net_usd'];}
        $choices=['Always BUY'=>'buy','Always SELL'=>'sell'];
        if($latest)$choices['Follow latest OB']=$latest===1?'buy':'sell';
        if($context['two_agree'])$choices['Follow latest two agreeing OBs']=$latest===1?'buy':'sell';
        foreach($choices as $name=>$side){
            $policies[$name]['trades']++;$policies[$name]['wins']+=($trade[$side]['net_usd']>0);$policies[$name]['net_usd']+=$trade[$side]['net_usd'];
        }
    }
    $out=[];
    foreach($policies as $name=>$stats)$out[]=['policy'=>$name,...$stats,'skipped'=>$fills-$stats['trades']];
    return ['fills'=>$fills,'groups'=>array_values($groups),'policies'=>$out,
        'method'=>'Last two confirmed wick-break orderblocks in the 20 minutes strictly before the entry bar. Independent simulations; BUY and SELL share each tested price.'];
}
function ec_details(array $q): array {
    $stamp=$q['entry']??'';
    $date=is_string($stamp)?DateTimeImmutable::createFromFormat('!Y-m-d\TH:i',$stamp,new DateTimeZone('UTC')):false;
    if(!$date||$date->format('Y-m-d\TH:i')!==$stamp)throw new InvalidArgumentException('Invalid entry timestamp');
    $bars=ml_rows('SELECT ts,o,h,l,c,v FROM candles WHERE ts>=? AND ts<? ORDER BY ts,id',[$date->modify('-21 minutes')->format('Y-m-d\TH:i'),$stamp]);
    $events=[];
    for($i=1;$i<count($bars);$i++){
        $a=$bars[$i-1];$b=$bars[$i];
        if(ml_time($a['ts'],1)!==$b['ts'])continue;
        $side=$a['c']<$a['o']&&$b['c']>$b['o']&&$b['c']>=$a['h']+.25?'BUY OB':
            ($a['c']>$a['o']&&$b['c']<$b['o']&&$b['c']<=$a['l']-.25?'SELL OB':null);
        if($side)$events[]=['side'=>$side,'confirmed_at_et'=>ml_time($b['ts'],1),'A'=>$a,'B'=>$b];
    }
    return ['events'=>array_slice($events,-2),'as_of_et'=>$stamp];
}
