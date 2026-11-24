<?php
declare(strict_types=1);

function mr_number(array $q,string $key,float $min,float $max,bool $integer=false): float {
    $value=$q[$key]??null;
    if(!is_numeric($value)||!is_finite((float)$value)||$value<$min||$value>$max||($integer&&floor((float)$value)!=(float)$value))throw new InvalidArgumentException('Invalid '.$key);
    return (float)$value;
}
function mr_path(string $id): string {
    if(!preg_match('/^[a-f0-9]{32}$/D',$id))throw new InvalidArgumentException('Invalid run ID');
    return ml_private().'/runs/'.$id.'.sqlite';
}
function mr_open(string $id): PDO {
    $path=mr_path($id);
    if(!is_file($path))throw new InvalidArgumentException('Run not found');
    return new PDO('sqlite:'.$path,null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
}
function mr_chain(int $length): string {
    $sql=' FROM swing_edges e1';
    for($i=2;$i<$length;$i++)$sql.=' JOIN swing_edges e'.$i.' ON e'.$i.'.akey=e'.($i-1).'.bkey';
    return $sql;
}
function mr_rules(int $length,string $family): array {
    $rules=[];
    if($family!=='pair'){
        for($j=0;$j<$length;$j++){
            $rules[]=['j'=>$j,'kind'=>'break','name'=>'#'.($j+1).' break → next open'];
            foreach(['return','retest'] as $kind)foreach(['open'=>1,'high'=>2,'low'=>3,'close'=>4] as $field=>$k)foreach([-1,0,1] as $off){
                $rules[]=['j'=>$j,'kind'=>$kind,'k'=>$k,'off'=>$off,'name'=>'#'.($j+1).' '.$kind.' · pivot '.$field.' '.$off.' ticks'];
            }
        }
    }
    if($family!=='pivot'){
        for($j=0;$j<$length;$j++)foreach(['A','B'] as $source)foreach(['return','retest'] as $kind)foreach(['open'=>1,'high'=>2,'low'=>3,'close'=>4] as $field=>$k)foreach([-1,0,1] as $off){
            $rules[]=['j'=>$j,'sourceCandle'=>$source,'kind'=>$kind,'field'=>$field,'k'=>$k,'off'=>$off,'name'=>'#'.($j+1).' '.$kind.' · candle '.$source.' '.$field.' '.$off.' ticks'];
        }
    }
    return $rules;
}
function mr_create(array $q): array {
    [$start,$end]=ml_range($q);
    $length=(int)mr_number($q,'length',2,4,true);
    $family=fl_choice($q,'family',['all','pivot','pair'],'all');
    $settings=['length'=>$length,'family'=>$family,'stop'=>mr_number($q,'stop',.25,1000),'tp'=>mr_number($q,'tp',.25,1000),
        'horizon'=>(int)mr_number($q,'horizon',1,200,true),'point'=>mr_number($q,'point',.01,10000),
        'qty'=>(int)mr_number($q,'qty',1,100,true),'fee'=>mr_number($q,'fee',0,1000),'tick'=>.25,'tf'=>1,'span'=>5,'skip'=>0,'basis'=>'close','anchor'=>'auto','ties'=>'strict'];
    if(fmod($settings['stop']*4,1)||fmod($settings['tp']*4,1))throw new InvalidArgumentException('SL and TP must use quarter-point increments');
    $total=(int)ml_rows('SELECT count(*) n'.mr_chain($length).' WHERE e1.first_day>=? AND e'.($length-1).'.confirm<?',[$start,ml_time($end.'T00:00',1440)])[0]['n'];
    $id=bin2hex(random_bytes(16));$folder=ml_private().'/runs';
    if(!is_dir($folder)&&!mkdir($folder,0700,true))throw new RuntimeException('Cannot create runs folder');
    $db=new PDO('sqlite:'.mr_path($id),null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    $db->exec("CREATE TABLE meta(k TEXT PRIMARY KEY,v TEXT);CREATE TABLE state(id INTEGER PRIMARY KEY,status TEXT,cursor INTEGER,processed INTEGER,total INTEGER,start TEXT,end TEXT);
        CREATE TABLE seq(sid INTEGER PRIMARY KEY,name TEXT UNIQUE);CREATE TABLE rules(rid INTEGER PRIMARY KEY,name TEXT,spec TEXT);
        CREATE TABLE occ(id INTEGER PRIMARY KEY,sid INTEGER,first_day TEXT,confirm TEXT,end_day TEXT,complete INTEGER,features TEXT,labels BLOB,entry_context BLOB);
        CREATE INDEX occ_date ON occ(first_day,id);CREATE INDEX occ_seq ON occ(sid,id);
        CREATE TABLE roll(first_day TEXT,end_day TEXT,sid INTEGER,rid INTEGER,eligible INTEGER,n INTEGER,bnet REAL,bw INTEGER,bl INTEGER,snet REAL,sw INTEGER,sl INTEGER);
        CREATE INDEX roll_dates ON roll(first_day,end_day);
        CREATE TABLE totals(sid INTEGER,rid INTEGER,eligible INTEGER,n INTEGER,bnet REAL,bw INTEGER,bl INTEGER,snet REAL,sw INTEGER,sl INTEGER,PRIMARY KEY(sid,rid));
        CREATE TABLE level_roll(first_day TEXT,end_day TEXT,sid INTEGER,rid INTEGER,ob TEXT,color TEXT,completed INTEGER,eligible INTEGER,n INTEGER,bnet REAL,bw INTEGER,bl INTEGER,snet REAL,sw INTEGER,sl INTEGER);
        CREATE INDEX level_dates ON level_roll(first_day,end_day);
        CREATE TABLE level_totals(sid INTEGER,rid INTEGER,ob TEXT,color TEXT,completed INTEGER,eligible INTEGER,n INTEGER,bnet REAL,bw INTEGER,bl INTEGER,snet REAL,sw INTEGER,sl INTEGER,PRIMARY KEY(sid,rid,ob,color));");
    $rules=mr_rules($length,$family);
    $insert=$db->prepare('INSERT INTO rules VALUES(?,?,?)');
    foreach($rules as $rid=>$rule)$insert->execute([$rid,$rule['name'],json_encode($rule)]);
    $meta=ml_meta();unset($meta['historical']);
    $meta=array_merge($meta,['settings'=>$settings,'source_snapshot_id'=>$meta['snapshot_id'],'snapshot_id'=>$id,'run_id'=>$id,'first'=>$start.'T00:00','last'=>$end.'T23:59',
        'candles'=>(int)ml_rows('SELECT count(*) n FROM candles WHERE ts>=? AND ts<?',[$start,ml_time($end.'T00:00',1440)])[0]['n'],
        'rule_count'=>count($rules),'sequences'=>0,'occurrences'=>0,'complete'=>0,'paired_fills'=>0,'pair_level_fills'=>0,
        'pair_level_rules'=>$family==='pivot'?0:$length*48]);
    $insert=$db->prepare('INSERT INTO meta VALUES(?,?)');
    foreach($meta as $key=>$value)$insert->execute([$key,json_encode($value)]);
    $db->prepare('INSERT INTO state VALUES(1,?,?,?,?,?,?)')->execute(['running',0,0,$total,$start,$end]);
    chmod(mr_path($id),0600);
    return ['run'=>$id,'status'=>'running','processed'=>0,'total'=>$total,'settings'=>$settings];
}
function mr_fill(array $bars,int $at,float $price,int $side,array $settings,bool $intrabar): array {
    $stop=$price-$side*$settings['stop'];$target=$price+$side*$settings['tp'];$last=count($bars)-1;
    for($i=$at;$i<=$last;$i++){
        $b=$bars[$i];$exit=null;$reason=2;
        if($i>$at&&$side*($b['o']-$stop)<=0){$exit=$b['o'];$reason=4;}
        elseif($i>$at&&$side*($b['o']-$target)>=0){$exit=$target;$reason=1;}
        if($exit===null){
            $stopped=$side===1?$b['l']<=$stop:$b['h']>=$stop;
            $hit=$side===1?$b['h']>=$target:$b['l']<=$target;
            if($stopped){$exit=$stop;$reason=$hit?3:0;}
            elseif($hit&&(!$intrabar||$i!==$at||$side*($b['c']-$target)>=0)){$exit=$target;$reason=1;}
            elseif($i===$last)$exit=$b['c'];
        }
        if($exit!==null)return ['out'=>$i,'price'=>(float)$exit,'reason'=>$reason,'net'=>($side*($exit-$price)*$settings['point']-$settings['fee'])*$settings['qty']];
    }
    throw new RuntimeException('Missing exit');
}
function mr_context(array $bars,int $confirm,int $horizon): string {
    $events=[];$flags=str_repeat("\0",$horizon+1);
    foreach($bars as $i=>$b){
        if($i>$confirm){
            $cutoff=ml_time($b['ts'],-20);$recent=array_values(array_filter($events,fn($event)=>$event['ts']>=$cutoff));
            $n=count($recent);$flags[$i-$confirm]=chr(($n?$recent[$n-1]['side']:0)|(($n>1?$recent[$n-2]['side']:0)<<2));
        }
        if(!$i)continue;$a=$bars[$i-1];
        if(ml_time($a['ts'],1)!==$b['ts'])continue;
        $side=$a['c']<$a['o']&&$b['c']>$b['o']&&$b['c']>=$a['h']+.25?1:
            ($a['c']>$a['o']&&$b['c']<$b['o']&&$b['c']<=$a['l']-.25?2:0);
        if($side)$events[]=['ts'=>$b['ts'],'side'=>$side];
    }
    return $flags;
}
function mr_step(string $id): array {
    $GLOBALS['mr_stage']='Opening saved run';
    $db=mr_open($id);$db->exec('PRAGMA busy_timeout=10000;BEGIN IMMEDIATE');
    try{
        $GLOBALS['mr_stage']='Reading run state';
        $state=$db->query('SELECT * FROM state')->fetch();
        if(!$state)throw new InvalidArgumentException('Saved run is incomplete. Create a new run.');
        if($state['status']==='complete'){$db->exec('COMMIT');return ['run'=>$id,...$state];}
        if($state['status']==='cancelled'){$db->exec('COMMIT');return ['run'=>$id,...$state];}
        $meta=[];foreach($db->query('SELECT * FROM meta') as $m)$meta[$m['k']]=json_decode($m['v'],true);
        $settings=$meta['settings'];$length=$settings['length'];
        if(isset($meta['source_snapshot_id'])&&$meta['source_snapshot_id']!==ml_meta()['snapshot_id'])throw new InvalidArgumentException('Source snapshot changed. Start a new run.');
        $columns='e1.id,e1.features';
        for($i=2;$i<$length;$i++)$columns.=',e'.$i.'.features AS extra'.$i;
        $GLOBALS['mr_stage']='Loading swing paths';
        $paths=ml_rows('SELECT '.$columns.mr_chain($length).' WHERE e1.id>? AND e1.first_day>=? AND e'.($length-1).'.confirm<? ORDER BY e1.id LIMIT 25',
            [$state['cursor'],$state['start'],ml_time($state['end'].'T00:00',1440)]);
        $rules=[];foreach($db->query('SELECT * FROM rules ORDER BY rid') as $r)$rules[(int)$r['rid']]=json_decode($r['spec'],true);
        $save=$db->prepare('INSERT INTO occ VALUES(?,?,?,?,?,?,?,?,?)');
        $seqInsert=$db->prepare('INSERT OR IGNORE INTO seq(name) VALUES(?)');
        $seqFind=$db->prepare('SELECT sid FROM seq WHERE name=?');
        $normal=[];$levels=[];$started=microtime(true);$processed=0;
        $GLOBALS['mr_stage']='Simulating entry rules';
        foreach($paths as $path){
            $legs=json_decode($path['features'],true);
            for($i=2;$i<$length;$i++)$legs[]=json_decode($path['extra'.$i],true)[1];
            $name=implode(' → ',array_column($legs,'key'));$seqInsert->execute([$name]);$seqFind->execute([$name]);$sid=(int)$seqFind->fetchColumn();
            $confirm=$legs[$length-1]['confirmed_bar'];$through=ml_time($confirm,$settings['horizon']);
            $first=substr($legs[0]['pivot'],0,10);
            $bars=ml_rows('SELECT ts,o,h,l,c,v FROM candles WHERE ts>=? AND ts<=? ORDER BY ts,id',
                [min(ml_time($legs[0]['pivot'],-1),ml_time($confirm,-21)),min($through,$state['end'].'T23:59')]);
            $indices=array_flip(array_column($bars,'ts'));$at=$indices[$confirm];
            $complete=count($bars)>$at+$settings['horizon']&&$bars[$at+$settings['horizon']]['ts']===$through;
            for($i=$at+1;$complete&&$i<count($bars);$i++)if(ml_time($bars[$i-1]['ts'],1)!==$bars[$i]['ts'])$complete=false;
            $raw=str_repeat("\0",count($rules)*20);$flags=str_repeat("\0",$settings['horizon']+1);
            $last=substr($bars[count($bars)-1]['ts'],0,10);
            if($complete){
                $meta['complete']++;$flags=mr_context($bars,$at,$settings['horizon']);
                $breaks=[];
                foreach($legs as $leg){
                    $side=in_array($leg['label'],['H','HH','LH','EH'],true)?1:-1;$br=null;
                    for($i=$at+1;$i<count($bars);$i++)if($side*($bars[$i-1]['c']-$leg['price'])<=0&&$side*($bars[$i]['c']-$leg['price'])>=.25){$br=$i;break;}
                    $breaks[]=$br;
                }
                foreach($rules as $rid=>$rule){
                    $leg=$legs[$rule['j']];$pair=isset($rule['sourceCandle']);
                    $key=implode('|',[$first,$last,$sid,$rid,...($pair?[$leg['ob'],$leg['candles'][$rule['sourceCandle']]['color']]:[])]);
                    if($pair){$levels[$key]??=[$first,$last,$sid,$rid,$leg['ob'],$leg['candles'][$rule['sourceCandle']]['color'],0,0,0,0,0,0,0,0,0];$levels[$key][6]++;}
                    $start=$at+1;
                    if($rule['kind']!=='return'){
                        $br=$breaks[$rule['j']];if($br===null||$br>=count($bars)-1)continue;$start=$br+1;
                    }
                    if(!$pair)$normal[$key]??=[$first,$last,$sid,$rid,0,0,0,0,0,0,0,0];
                    if($pair)$levels[$key][7]++;else $normal[$key][4]++;
                    $pos=$rid*20;$raw[$pos]=chr(1);
                    $price=$rule['kind']==='break'?$bars[$start]['o']:
                        (($pair?$leg['candles'][$rule['sourceCandle']][$rule['field']]:$leg['ohlcv'][$rule['k']-1])+$rule['off']*.25);
                    if($rule['kind']!=='break'){
                        while($start<count($bars)&&!($bars[$start-1]['c']!=$price&&$bars[$start]['l']<=$price&&$bars[$start]['h']>=$price))$start++;
                        if($start===count($bars))continue;
                    }
                    $raw[$pos]=chr(2);$raw[$pos+1]=chr($start-$at);
                    $raw=substr_replace($raw,pack('V',(int)round($price*4)),$pos+2,4);
                    if($pair){$levels[$key][8]++;$meta['pair_level_fills']++;}else{$normal[$key][5]++;$meta['paired_fills']++;}
                    foreach([1,-1] as $j=>$side){
                        $trade=mr_fill($bars,$start,(float)$price,$side,$settings,$rule['kind']!=='break');
                        $slot=$pos+6+$j*6;$raw[$slot]=chr($trade['out']-$at);$raw[$slot+1]=chr($trade['reason']);
                        $raw=substr_replace($raw,pack('V',(int)round($trade['price']*4)),$slot+2,4);
                        if($pair){$levels[$key][9+$j*3]+=$trade['net'];$levels[$key][10+$j*3]+=($trade['net']>0);$levels[$key][11+$j*3]+=($trade['net']<0);}
                        else{$normal[$key][6+$j*3]+=$trade['net'];$normal[$key][7+$j*3]+=($trade['net']>0);$normal[$key][8+$j*3]+=($trade['net']<0);}
                    }
                }
            }
            $save->execute([$path['id'],$sid,$first,$confirm,$last,(int)$complete,json_encode($legs),$complete?gzdeflate($raw,1):null,$flags]);
            $meta['occurrences']++;$processed++;$state['cursor']=$path['id'];
            if(microtime(true)-$started>2)break;
        }
        $GLOBALS['mr_stage']='Saving batch results';
        foreach([['roll','totals',$normal,2,['sid','rid'],['eligible','n','bnet','bw','bl','snet','sw','sl']],
            ['level_roll','level_totals',$levels,2,['sid','rid','ob','color'],['completed','eligible','n','bnet','bw','bl','snet','sw','sl']]] as [$table,$total,$rows,$drop,$keys,$values]){
            $insert=$db->prepare('INSERT INTO '.$table.' VALUES('.implode(',',array_fill(0,$drop+count($keys)+count($values),'?')).')');
            // Seed a zero row, then add this batch within the same write transaction.
            // This also supports SQLite versions predating UPSERT.
            $ensure=$db->prepare('INSERT OR IGNORE INTO '.$total.' VALUES('.implode(',',array_fill(0,count($keys)+count($values),'?')).')');
            $add=$db->prepare('UPDATE '.$total.' SET '.implode(',',array_map(fn($v)=>$v.'='.$v.'+?',$values)).
                ' WHERE '.implode(' AND ',array_map(fn($key)=>$key.'=?',$keys)));
            foreach($rows as $row){
                $insert->execute($row);
                $identity=array_slice($row,$drop,count($keys));
                $amounts=array_slice($row,$drop+count($keys));
                $ensure->execute([...$identity,...array_fill(0,count($values),0)]);
                $add->execute([...$amounts,...$identity]);
            }
        }
        $state['processed']+=$processed;$done=$state['processed']>=$state['total']||!$paths;
        $state['status']=$done?'complete':'running';
        $db->prepare('UPDATE state SET status=?,cursor=?,processed=?')->execute([$state['status'],$state['cursor'],$state['processed']]);
        $meta['sequences']=(int)$db->query('SELECT count(*) FROM seq')->fetchColumn();
        $update=$db->prepare('UPDATE meta SET v=? WHERE k=?');
        foreach(['sequences','occurrences','complete','paired_fills','pair_level_fills'] as $key)$update->execute([json_encode($meta[$key]),$key]);
        $GLOBALS['mr_stage']='Committing saved batch';
        $db->exec('COMMIT');return ['run'=>$id,...$state];
    }catch(Throwable $e){
        // BEGIN IMMEDIATE was issued as SQL; use SQL to finish it on every PDO version.
        try{$db->exec('ROLLBACK');}catch(Throwable $rollback){error_log('Master run rollback: '.$rollback->getMessage());}
        throw $e;
    }
}
