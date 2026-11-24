<?php
declare(strict_types=1);
require_once __DIR__.'/master-lib.php';
function lr_db():PDO{
 static $db;
 if(!$db){$file=ml_private().'/level-revisit.sqlite';if(!is_file($file))throw new RuntimeException('Install level-revisit.sqlite in the private folder');
 $db=new PDO('sqlite:'.$file,null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::SQLITE_ATTR_OPEN_FLAGS=>PDO::SQLITE_OPEN_READONLY]);$db->exec('PRAGMA query_only=ON');}
 return $db;
}
function lr_meta():array{return json_decode(lr_db()->query("SELECT v FROM meta WHERE k='manifest'")->fetchColumn(),true,512,JSON_THROW_ON_ERROR);}
function lr_row(int $id):array{$s=lr_db()->prepare('SELECT * FROM results WHERE id=?');$s->execute([$id]);$r=$s->fetch();if(!$r)throw new InvalidArgumentException('Unknown rule');return lr_decode($r);}
function lr_decode(array $r):array{foreach(['stats','years','examples'] as $k)$r[$k]=json_decode($r[$k],true,512,JSON_THROW_ON_ERROR);return $r;}
function lr_target(array $q):int{$t=ml_uint($q,'target',2,10);$k=array_search($t,[1,2,5,10],true);if($k===false)throw new InvalidArgumentException('Target must be 1, 2, 5 or 10 points');return $k;}
function lr_result(array $q):array{
 $resource=$q['resource']??'rankings';$db=lr_db();
 if($resource==='manifest')return lr_meta();
 if($resource==='rule')return lr_row(ml_uint($q,'id',0,10000000));
 if($resource==='example'){
  $r=lr_row(ml_uint($q,'id',0,10000000));$i=ml_uint($q,'example',0,2);if(!isset($r['examples'][$i]))throw new InvalidArgumentException('Example unavailable');$e=$r['examples'][$i];
  $file=ml_private().'/master.sqlite';if(!is_file($file))throw new RuntimeException('Chart source master.sqlite missing');
  $source=new PDO('sqlite:'.$file,null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::SQLITE_ATTR_OPEN_FLAGS=>PDO::SQLITE_OPEN_READONLY]);
  $s=$source->prepare('SELECT ts,o,h,l,c FROM candles WHERE ts>=? AND ts<=? ORDER BY ts,id LIMIT 350');$s->execute([ml_time($e['a'][0],-10),ml_time($e['end'],5)]);
  return ['rule'=>$r,'example'=>$e,'bars'=>$s->fetchAll()];
 }
 if($resource!=='rankings')throw new InvalidArgumentException('Unknown resource');
 $k=lr_target($q);$n=max(1,ml_uint($q,'min_n',30,10000000));$orig=max(1,ml_uint($q,'min_origins',20,10000000));$limit=max(1,ml_uint($q,'limit',25,100));$page=ml_uint($q,'page',0,1000000);
 $where='n>=? AND origins>=?';$args=[$n,$orig];
 foreach(['scenario','ob','source','color','field','side','visit','boundary'] as $field)if(isset($q[$field])&&$q[$field]!==''&&$q[$field]!=='*'){$where.=" AND $field=?";$args[]=$q[$field];}
 if(isset($q['offset'])&&$q['offset']!=='*'){if(!in_array((string)$q['offset'],['-1','0','1'],true))throw new InvalidArgumentException('Invalid tick offset');$where.=' AND offset=?';$args[]=(int)$q['offset'];}
 if(($q['geometry_mode']??'detailed')==='pooled')$where.=" AND geometry='Any'";else $where.=" AND geometry<>'Any'";
 $metric="json_extract(stats,'$[$k].contained')*1.0/n";$sort=$q['sort']??'contained';
 if($sort==='net')$metric="json_extract(stats,'$[$k].net')";
 elseif($sort==='win')$metric="json_extract(stats,'$[$k].w')*1.0/n";
 elseif($sort==='sample')$metric='origins';
 elseif($sort!=='contained')throw new InvalidArgumentException('Invalid sort');
 $s=$db->prepare("SELECT * FROM results WHERE $where ORDER BY $metric DESC,n DESC,id LIMIT ".($limit+1).' OFFSET '.($page*$limit));$s->execute($args);$rows=$s->fetchAll();$more=count($rows)>$limit;if($more)array_pop($rows);
 return ['rows'=>array_map('lr_decode',$rows),'next_page'=>$more?$page+1:null,'target'=>[1,2,5,10][$k],'target_index'=>$k,'note'=>'Contained reaction and hypothetical trade profit are separate outcomes. Each row is an overlapping alternative, not portfolio P&L.'];
}
function lr_indicator(array $r,int $target):string{
 $template=file_get_contents(__DIR__.'/level-revisit-indicator-template.pine');
 [$lab,$pair,$shape]=explode(' ',$r['scenario'],3);
 $values=['lab'=>$lab,'pair'=>$pair,'shape'=>$shape,'obFilter'=>$r['ob'],'src'=>$r['source'],'field'=>$r['field'],'direction'=>$r['side'],'visits'=>$r['visit'],'geometryFilter'=>$r['geometry'],'boundaryMode'=>$r['boundary']];
 foreach($values as $name=>$value){$pattern='/string '.preg_quote($name,'/').'=input\.string\("[^"\r\n]*"/';$replacement='string '.$name.'=input.string('.json_encode($value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);$template=preg_replace_callback($pattern,fn($m)=>$replacement,$template,1,$count);if($count!==1)throw new RuntimeException('Missing indicator input');}
 $template=preg_replace('/int ticks=input\.int\(-?\d+/','int ticks=input.int('.(int)$r['offset'],$template,1);
 $template=preg_replace('/float target=input\.float\([\d.]+/','float target=input.float('.$target,$template,1);
 return str_replace('//@version=6',"//@version=6\n// Boundary revisit rule ".$r['id']."; ".$r['n']." historical trials; not a guarantee.",$template);
}
