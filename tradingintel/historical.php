<?php
declare(strict_types=1);
function hr_context(array $q, ?callable $loader=null): array {
    $stamp=$q['anchor']??'';
    if(!is_string($stamp))throw new InvalidArgumentException('Choose an anchor candle');
    $date=DateTimeImmutable::createFromFormat('!Y-m-d\TH:i',$stamp,new DateTimeZone('UTC'));
    if(!$date||$date->format('Y-m-d\TH:i')!==$stamp)throw new InvalidArgumentException('Invalid anchor timestamp');
    $loader??=fn($a,$b)=>ml_rows('SELECT ts,o,h,l,c,v FROM candles WHERE ts>=? AND ts<=? ORDER BY ts,id',[$a,$b]);
    $windows=[];
    foreach(['current'=>0,'day'=>1,'week'=>7] as $name=>$days){
        $anchor=$date->modify('-'.$days.' days');
        $rows=$loader($anchor->modify('-20 minutes')->format('Y-m-d\TH:i'),$anchor->modify('+30 minutes')->format('Y-m-d\TH:i'));
        $windows[$name]=array_map(fn($r)=>[$r['ts'],(float)$r['o'],(float)$r['h'],(float)$r['l'],(float)$r['c'],(float)$r['v']],$rows);
    }
    return ['anchor'=>$stamp,'current_known_through_et'=>$stamp,'future_current_rows_are_outcomes'=>true,'windows'=>$windows,'context'=>20,'horizon'=>30,
        'clock'=>'Eastern wall time; previous calendar day and seven calendar days earlier. Missing windows are excluded.'];
}

function hr_report(array $q): array {
    $meta=ml_meta();
    if(!isset($meta['historical']))return ['needs_snapshot'=>true,'message'=>'Upload the historical v3 snapshot.','rows'=>[],'levels'=>[]];
    [$start,$end]=ml_range($q);
    $error=$q['error']??3;
    if(!is_numeric($error)||!is_finite((float)$error)||$error<0||$error>1000)throw new InvalidArgumentException('Invalid context error');
    $ticks=ml_uint($q,'distance_ticks',1,4000);
    $session=fl_choice($q,'session',['*','Midnight','London','New York','Asia'],'*');
    $where='h.anchor>=? AND h.anchor<?';
    $args=[$start,ml_time($end.'T00:00',1440)];
    if($session!=='*'){$where.=' AND h.session=?';$args[]=$session;}
    $gate="CASE WHEN h.context_error<=CAST(? AS REAL) AND h.range_ratio BETWEEN 0.5 AND 2
        AND h.trend_match=1 AND (h.mode='relative' OR h.anchor_distance<=CAST(? AS REAL)) THEN 1 ELSE 0 END";
    $period="CASE WHEN h.anchor<? THEN 'earlier' ELSE 'later' END";
    $prefix=[$error,$ticks*.25,$meta['historical']['split_date']];
    $rows=ml_rows("SELECT h.source,h.mode,$gate AS qualifies,$period AS period,
        count(*) AS n,100.0*avg(h.agreement) AS direction_agreement,
        avg(h.path_error) AS path_error,avg(h.context_error) AS context_error,
        avg(h.volume_ratio) AS volume_ratio,count(h.follow_net) AS trades,
        100.0*avg(h.follow_win) AS win_rate,sum(h.follow_net) AS net_usd
        FROM historical h WHERE $where GROUP BY h.source,h.mode,qualifies,period
        ORDER BY h.source,h.mode,qualifies DESC,period",array_merge($prefix,$args));
    $levels=ml_rows("SELECT h.source,h.mode,$gate AS qualifies,$period AS period,l.field,
        count(*) AS eligible,sum(l.filled) AS filled,100.0*avg(l.filled) AS fill_rate,
        sum(l.bnet) AS buy_net,100.0*avg(l.bwin) AS buy_win,
        sum(l.snet) AS sell_net,100.0*avg(l.swin) AS sell_win
        FROM historical h JOIN historical_levels l ON l.hid=h.id WHERE $where
        GROUP BY h.source,h.mode,qualifies,period,l.field
        ORDER BY h.source,h.mode,qualifies DESC,period,l.field",array_merge($prefix,$args));
    return ['rows'=>$rows,'levels'=>$levels,'settings'=>$meta['historical'],
        'criteria'=>['maximum_context_error_points'=>(float)$error,'absolute_anchor_distance_ticks'=>$ticks,
            'range_ratio'=>[.5,2],'same_context_trend'=>true],
        'coverage'=>[$meta['first'],$meta['last']],
        'method'=>'20-bar observed context; 30-bar forward replay; anchors every half hour. Earlier/later split is fixed across date filters. Complete continuous windows only. Follow-replay trade enters next open in projected endpoint direction, SL/TP 10 points, $20 per point, zero costs. Level trials use first later touch.'];
}
