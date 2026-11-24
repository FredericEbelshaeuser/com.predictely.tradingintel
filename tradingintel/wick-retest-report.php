<?php
require __DIR__.'/bootstrap.php';auth();require_once __DIR__.'/master-lib.php';
if(isset($_GET['data'])){
 try{
  $file=ml_private().'/master.sqlite';if(!is_file($file))throw new RuntimeException('Original master.sqlite missing');
  $db=new PDO('sqlite:'.$file,null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::SQLITE_ATTR_OPEN_FLAGS=>PDO::SQLITE_OPEN_READONLY]);
  if($_GET['data']==='chart'){
   $from=$_GET['from']??'';$to=$_GET['to']??'';
   foreach([$from,$to] as $v)if(!is_string($v)||!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/D',$v))throw new InvalidArgumentException('Invalid chart time');
   $q=$db->prepare('SELECT ts,o,h,l,c FROM candles WHERE ts>=? AND ts<=? ORDER BY ts,id LIMIT 2000');$q->execute([ml_time($from,-10),ml_time($to,5)]);jsonout(['bars'=>$q->fetchAll()]);
  }
  $a=$_GET['start']??'2022-12-26';$b=$_GET['end']??'2025-12-11';
  foreach([$a,$b] as $v)if(!is_string($v)||!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$v)||ml_dt($v)->format('Y-m-d')!==$v)throw new InvalidArgumentException('Invalid date');
  if($a>$b)throw new InvalidArgumentException('Start follows end');
  $cursor=['',0];
  if(!empty($_GET['cursor'])){
   $cursor=json_decode(base64_decode($_GET['cursor'],true)?:'null',true);
   if(!is_array($cursor)||count($cursor)!==2||!is_string($cursor[0])||!is_int($cursor[1]))throw new InvalidArgumentException('Invalid cursor');
  }
  $q=$db->prepare('SELECT id,ts,o,h,l,c FROM candles WHERE ts>=? AND ts<? AND (ts,id)>(?,CAST(? AS INTEGER)) ORDER BY ts,id LIMIT 10001');
  $q->execute([$a,ml_time($b.'T00:00',1440),$cursor[0],$cursor[1]]);$rows=$q->fetchAll();$more=count($rows)>10000;if($more)array_pop($rows);$last=end($rows);
  jsonout(['bars'=>$rows,'next_cursor'=>$more?base64_encode(json_encode([$last['ts'],(int)$last['id']])):null]);
 }catch(InvalidArgumentException $e){http_response_code(400);jsonout(['error'=>$e->getMessage()]);}
 catch(Throwable $e){error_log('Wick retest: '.$e->getMessage());http_response_code(503);jsonout(['error'=>'Unable to read original master.sqlite. Check private installation and PDO SQLite.']);}
}
?><!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width"><title>Strict wick retest strategy</title>
<style>body{background:#0e1723;color:#e5eef9;font:15px system-ui;margin:0}main{max-width:1450px;margin:auto;padding:24px}section{background:#192638;padding:18px;margin:18px 0;border-radius:8px}a{color:#73caff}form{display:flex;gap:12px;flex-wrap:wrap;align-items:end}label{display:flex;flex-direction:column;gap:4px;font-size:13px}input,button{color:inherit;background:#293e57;border:1px solid #60748c;padding:8px;border-radius:4px}input{width:115px}button{cursor:pointer;background:#295e95}table{border-collapse:collapse;width:100%;font-size:13px}th,td{text-align:left;padding:9px;border-bottom:1px solid #3b4a60}.scroll{overflow:auto}canvas{width:100%;touch-action:none}small{color:#b6c5d9}#status{font-size:18px;color:#8be1d0}</style>
<main><nav><a href="index.php">Chart</a> · <a href="master-report.php">Master report</a> · <a href="level-revisit-report.php">All swing level revisits</a></nav>
<h1>Strict HH / LL wick retest strategy</h1>
<p>SELL: HH green A, red B open and high strictly inside A’s upper wick; B closes at least one tick below A low.<br>BUY: LL red A, green B open and low strictly inside A’s lower wick; B closes at least one tick above A high.</p>
<section><form id="run">
<label>Start ET<input id="start" type="date" value="2022-12-26" required></label><label>End ET<input id="end" type="date" value="2025-12-11" required></label>
<label>Target points<input id="target" type="number" value="10" min=".25" step=".25" required></label>
<label>Setup life (bars)<input id="life" type="number" value="120" min="1" max="500" required></label>
<label>Trade hold (bars)<input id="hold" type="number" value="30" min="1" max="500" required></label>
<label>Min wick/body ratio<input id="wickRatio" type="number" value="0" min="0" step=".25" required></label>
<label>Contracts<input id="qty" type="number" value="1" min="1" step="1" required></label>
<label>USD / point<input id="point" type="number" value="20" min=".01" step=".01" required></label>
<label>Round-trip cost / contract<input id="cost" type="number" value="5" min="0" step=".01" required></label>
<button id="go">Run selected dates</button><button id="cancel" type="button" disabled>Cancel</button><button id="export" type="button" disabled>Download results JSON</button></form>
<p>First return: B open limit. Second return: B high for SELL / B low for BUY. The second order arms only after the first trade closes and a whole candle is away from the B open. If that second return happens while the first trade is still open, it is skipped. If it enters the B wick band but leaves without filling the extreme, no third-return entry is substituted.</p>
<small>One active setup and one position. Five bars confirm the pivot; a first return before confirmation is skipped. A is the pivot and B is its immediate next candle. The first swing of a continuous segment has no prior HH/LL comparator. Stops: BUY below A/B low by one tick; SELL above A/B high by one tick. No change to other reports.</small></section>
<p id="status">Choose dates and run. Data are fetched in small pages; the browser retains only the detector window and trade results.</p>
<section id="summary" hidden></section><section><div class="scroll"><table id="trades"></table></div><p><button id="prev">Previous trades</button> <button id="next">Next trades</button></p></section>
<section id="chartSection" hidden><h2>Trade chart</h2><p id="chartInfo"></p><canvas id="chart" width="1400" height="500"></canvas><small>Drag to pan; wheel to zoom. OPEN and WICK belong to the original B candle. SL remains outside the original pair.</small></section>
<section><h2>Execution assumptions</h2><p>Orders become available after confirmation close. Touch fills use the planned limit, or a better gap-open price. Target is fixed from the planned limit. If stop and target both touch, stop wins; on the entry bar the close must reach the target to prove a post-entry reaction. Pending orders can fill at a gap open before close-time cancellation. A boundary breach cancels future entries, not an already filled trade.</p>
<p>Time exits use the close of the configured holding bar. Open positions at the selected range’s end remain open and are excluded from closed-trade profit. Commission is charged per round trip per contract. No added slippage. TradingView uses its own broker emulator and Bar Magnifier, so ambiguous intrabar fills can differ from this conservative test.</p>
<p>Dates are Eastern wall time. New runs start with no earlier swing or order state. A shorter date window can therefore differ at its beginning from the full-history run. Results measure this specific strategy; they do not establish that a shape always wins.</p></section>
<script src="wick-retest-engine.js?v=1"></script><script src="wick-retest-ui.js?v=1"></script></main></html>
