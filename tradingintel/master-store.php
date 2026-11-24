<?php
function ml_select_run(array $q): void {
    $run=$q['run']??'';
    if(!is_string($run)||($run!==''&&!preg_match('/^[a-f0-9]{32}$/D',$run)))throw new InvalidArgumentException('Invalid run ID');
    if(in_array($q['resource']??'',['historical_context','historical_report'],true))$run='';
    $GLOBALS['ml_selected_run']=$run;
}
function ml_db(): PDO {
    static $connections=[];
    $run=$GLOBALS['ml_selected_run']??'';
    if(isset($connections[$run]))return $connections[$run];
    $file=ml_private().($run?'/runs/'.$run.'.sqlite':'/master.sqlite');
    if(!is_file($file))throw new RuntimeException('Install the snapshot or choose a completed run');
    $db=new PDO('sqlite:'.$file,null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::SQLITE_ATTR_OPEN_FLAGS=>PDO::SQLITE_OPEN_READONLY]);
    if($run){
        if($db->query('SELECT status FROM state')->fetchColumn()!=='complete')throw new InvalidArgumentException('Run is not complete yet');
        $base=ml_private().'/master.sqlite';
        if(!is_file($base))throw new RuntimeException('Base candle snapshot missing');
        $db->exec('ATTACH DATABASE '.$db->quote($base).' AS source');
        $db->exec('CREATE TEMP VIEW candles AS SELECT * FROM source.candles');
    }
    $db->exec('PRAGMA query_only=ON');
    return $connections[$run]=$db;
}
function ml_meta(): array {
    static $cache=[];
    $run=$GLOBALS['ml_selected_run']??'';
    if(isset($cache[$run]))return $cache[$run];
    $meta=[];foreach(ml_rows('SELECT * FROM meta') as $row)$meta[$row['k']]=json_decode($row['v'],true);
    return $cache[$run]=$meta;
}
function ml_rules(): array {
    static $cache=[];
    $run=$GLOBALS['ml_selected_run']??'';
    if(isset($cache[$run]))return $cache[$run];
    $rules=[];
    foreach(ml_rows('SELECT * FROM rules ORDER BY rid') as $row){$row['spec']=json_decode($row['spec'],true);$rules[(int)$row['rid']]=$row;}
    return $cache[$run]=$rules;
}
