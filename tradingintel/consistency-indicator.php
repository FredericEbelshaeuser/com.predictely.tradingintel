<?php
require __DIR__.'/bootstrap.php';auth();require __DIR__.'/master-lib.php';
try{
 $id=ml_uint($_GET,'id',0,10000000);$st=cr_db()->prepare('SELECT * FROM results WHERE id=?');$st->execute([$id]);$r=$st->fetch();if(!$r)throw new InvalidArgumentException('Unknown research row');
 $code=cr_indicator($r);
 header('Cache-Control: no-store, max-age=0');header('Content-Type: text/plain; charset=utf-8');header('Content-Disposition: attachment; filename="TradingIntel_structural_corrected_rule_'.$id.'.pine"');echo $code;
}catch(InvalidArgumentException $e){http_response_code(400);echo $e->getMessage();}catch(Throwable $e){http_response_code(503);echo 'Indicator export unavailable; check installation.';}
