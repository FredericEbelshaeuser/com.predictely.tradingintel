<?php
require __DIR__.'/bootstrap.php';auth();require __DIR__.'/level-revisit-lib.php';
header('Cache-Control: no-store');
try{$r=lr_row(ml_uint($_GET,'id',0,10000000));$k=lr_target($_GET);$code=lr_indicator($r,[1,2,5,10][$k]);header('Content-Type: text/plain; charset=utf-8');header('Content-Disposition: attachment; filename="TradingIntel_Level_Revisit_'.$r['id'].'.pine"');echo $code;}
catch(InvalidArgumentException $e){http_response_code(400);echo $e->getMessage();}
catch(Throwable $e){error_log('Level revisit export: '.$e->getMessage());http_response_code(503);echo 'Export unavailable; check new template and private database installation.';}
