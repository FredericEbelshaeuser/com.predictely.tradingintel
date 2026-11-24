<?php
function cr_db():PDO{static $db;if(!$db){$file=ml_private().'/consistency.sqlite';if(!is_file($file))throw new RuntimeException('Install consistency.sqlite in the existing private folder');$db=new PDO('sqlite:'.$file,null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::SQLITE_ATTR_OPEN_FLAGS=>PDO::SQLITE_OPEN_READONLY]);$db->exec('PRAGMA query_only=ON');}return $db;}
function cr_samples(array $r):array{
 static $checked=false,$samples=null;
 if(!$checked){
  $checked=true;$file=ml_private().'/consistency-samples.sqlite';
  if(is_file($file)){
   $candidate=new PDO('sqlite:'.$file,null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::SQLITE_ATTR_OPEN_FLAGS=>PDO::SQLITE_OPEN_READONLY]);
   $expected=$candidate->query("SELECT v FROM meta WHERE k='base_manifest_sha256'")->fetchColumn();
   $actual=hash('sha256',cr_db()->query("SELECT v FROM meta WHERE k='manifest'")->fetchColumn());
   if(!hash_equals($actual,(string)$expected))throw new InvalidArgumentException('Chart sample file does not match this research database; install the matching sample patch');
   $samples=$candidate;
  }
 }
 if($samples){$st=$samples->prepare('SELECT example FROM samples WHERE row_id=? ORDER BY ordinal');$st->execute([$r['id']]);$out=[];foreach($st as $row)$out[]=json_decode($row['example'],true);if($out)return $out;}
 return is_array($r['examples'])?$r['examples']:(json_decode($r['examples'],true)?:[]);
}
function cr_fix_visit_returns(string $code):string{
 $code=str_replace("\r\n","\n",$code);
 $start=strpos($code,"f_visits(bool gap)=>");
 if($start===false)throw new RuntimeException('Missing visit function in indicator template');
 $lines=explode("\n",substr($code,$start));$limit=count($lines);
 for($i=1;$i<count($lines);$i++)if(trim($lines[$i])!==''&&!ctype_space($lines[$i][0])&&substr($lines[$i],0,2)!=='//'){$limit=$i;break;}
 $block=implode("\n",array_slice($lines,0,$limit));
 $block=preg_replace('/^ +0 \/\/ (?:Explicit integer result for this branch\.|Same result type as the expiry branch\.|Stable return type; callers use only the side effects\.|Stable visit branch result\.|Stable visit function result\.)\n?/m','',$block);
 foreach(['line.set_color(o.ray,color.new(color.gray,70))','o.wasInside:=inside'] as $anchor){
  $pattern='/^( +)'.preg_quote($anchor,'/').'$/m';
  $block=preg_replace_callback($pattern,fn($m)=>$m[0]."\n".$m[1]."0 // Stable visit branch result.",$block,1,$count);
  if($count!==1)throw new RuntimeException('Unrecognized visit function in template');
 }
 $block=rtrim($block)."\n    0 // Stable visit function result.\n\n";
 return substr($code,0,$start).$block.implode("\n",array_slice($lines,$limit));
}
function cr_result(array $q):array{
 $db=cr_db();$m=json_decode($db->query("SELECT v FROM meta WHERE k='manifest'")->fetchColumn(),true);
 if(($q['resource']??'')==='consistency_manifest')return $m;
 if(($q['resource']??'')==='consistency_example'){
  $id=ml_uint($q,'id',0,10000000);$s=$db->prepare('SELECT * FROM results WHERE id=?');$s->execute([$id]);$r=$s->fetch();if(!$r)throw new InvalidArgumentException('Unknown row');$examples=cr_samples($r);$at=ml_uint($q,'example',0,2);if(!isset($examples[$at]))throw new InvalidArgumentException('Install matching consistency-samples.sqlite in the private folder to view this rule');$e=$examples[$at];$a=ml_time($e['pivot'],-15);$b=ml_time($e['exit'],10);return ['rule'=>$r,'example'=>$e,'bars'=>ml_rows('SELECT ts,o,h,l,c FROM candles WHERE ts>=? AND ts<=? ORDER BY ts,id LIMIT 300',[$a,$b])];
 }
 $min=ml_uint($q,'min_n',100,10000000);$orig=ml_uint($q,'min_origins',50,10000000);$win=ml_uint($q,'min_win',0,100);$recent=ml_uint($q,'recent_min',30,10000000);$page=ml_uint($q,'page',0,1000000);$limit=max(1,ml_uint($q,'limit',50,100));$args=[$min,$orig,$win];$where='n>=? AND origins>=? AND w*100.0/n>=CAST(? AS REAL)';
 foreach(['scenario','side','source','field','visit','context_kind'] as $key)if(isset($q[$key])&&$q[$key]!=='*'){$where.=" AND $key=?";$args[]=$q[$key];}
 if(isset($q['stop'])&&$q['stop']!=='*'){$where.=' AND stop=?';$args[]=(float)$q['stop'];}
 if(($q['stable']??'1')==='1'){$where.=' AND train_n>=? AND recent_n>=? AND train_net>0 AND recent_net>0';$args[]=$recent;$args[]=$recent;}
 $sort=['recent'=>'recent_w*1.0/max(1,recent_n) DESC,net DESC','win'=>'w*1.0/n DESC,n DESC','net'=>'net DESC','sample'=>'origins DESC,n DESC'][$q['sort']??'recent']??'net DESC';
 $s=$db->prepare("SELECT * FROM results WHERE $where ORDER BY $sort LIMIT ".($limit+1).' OFFSET '.($page*$limit));$s->execute($args);$rows=$s->fetchAll();$more=count($rows)>$limit;if($more)array_pop($rows);foreach($rows as &$r){$r['years']=json_decode($r['years'],true);$r['examples']=cr_samples($r);$p=$r['w']/$r['n'];$z=1.96;$r['win_rate']=100*$p;$r['wilson_lower']=100*(($p+$z*$z/(2*$r['n'])-$z*sqrt(($p*(1-$p)+$z*$z/(4*$r['n']))/$r['n']))/(1+$z*$z/$r['n']));}return ['rows'=>$rows,'next_page'=>$more?$page+1:null,'settings'=>$m['settings'],'note'=>'Independent overlapping OHLC-touch trials; cost is a $5 sensitivity assumption, not an actual broker quote. 2025 comparison is not pristine unseen validation.'];
}

function cr_indicator(array $r):string{
 [$label,$pair,$shape]=explode(' ',$r['scenario'],3);$parts=explode(' | ',$r['context']);$values=['lab'=>$label,'pair'=>$pair,'shape'=>$shape,'src'=>$r['source'],'field'=>$r['field'],'direction'=>$r['side'],'visits'=>$r['visit'],'obFilter'=>$parts[0]];
 if($r['context_kind']==='OB size')$values['sizeFilter']=$parts[1];
 if($r['context_kind']==='Prior FVG')$values['fvgFilter']=$parts[1];
 if($r['context_kind']==='Displacement')$values['dispFilter']=$parts[1];
 if($r['context_kind']==='Combined'){$values['sizeFilter']=$parts[1];$values['fvgFilter']=$parts[2];$values['dispFilter']=$parts[3];}
 $code=cr_fix_visit_returns(file_get_contents(__DIR__.'/structural-indicator-template.pine'));
 foreach($values as $name=>$value){$pattern='/string '.preg_quote($name,'/').'=input\.string\("[^"\r\n]*"/';$replacement='string '.$name.'=input.string('.json_encode($value,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);$code=preg_replace_callback($pattern,fn($m)=>$replacement,$code,1,$count);if($count!==1)throw new RuntimeException('Template input missing');}
 $code=preg_replace('/int ticks=input\.int\(-?\d+/', 'int ticks=input.int('.(int)$r['offset'],$code,1);
 $code=str_replace("//@version=6", "//@version=6\n// Research row ".$r['id']."; historical trials ".$r['n']."; not guaranteed future performance.", $code);
 return $code;
}
