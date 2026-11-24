<?php
require __DIR__.'/bootstrap.php';auth();
try{
 $post=$_SERVER['REQUEST_METHOD']==='POST';if($post)csrf();$id=(int)($_GET['dataset']??0);if(!$id)$id=(int)stmt("SELECT id FROM ti_datasets WHERE status='ready' ORDER BY id DESC LIMIT 1")->fetchColumn();if(!stmt("SELECT id FROM ti_datasets WHERE id=? AND status='ready'",[$id])->fetchColumn())throw new RuntimeException('Select a dataset first');
 if($post){$body=file_get_contents('php://input',false,null,0,65537);if(strlen($body)>65536)throw new RuntimeException('Search too large');$p=json_decode($body,true,512,JSON_THROW_ON_ERROR);$name=trim($p['name']??'');if(!$name||strlen($name)>150||!is_array($p['controls']??null))throw new RuntimeException('Choose a name up to 150 bytes');stmt('INSERT INTO ti_saved_research(dataset_id,name,controls_json) VALUES(?,?,?) ON DUPLICATE KEY UPDATE controls_json=VALUES(controls_json)',[$id,$name,json_encode($p['controls'],JSON_THROW_ON_ERROR)]);}
 jsonout(['items'=>stmt('SELECT name,controls_json FROM ti_saved_research WHERE dataset_id=? ORDER BY name',[$id])->fetchAll(PDO::FETCH_ASSOC)]);
}catch(Throwable $e){http_response_code(400);jsonout(['error'=>$e instanceof PDOException?'Saved searches unavailable. Import saved-research.sql and check table privileges.':$e->getMessage()]);}

