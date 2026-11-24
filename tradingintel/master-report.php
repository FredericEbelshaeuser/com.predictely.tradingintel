<?php
require __DIR__.'/bootstrap.php';
auth();
require __DIR__.'/master-lib.php';
if(isset($_GET['data'])){
    try {jsonout(ml_result($_GET));}
    catch(InvalidArgumentException $e){http_response_code(400);jsonout(['error'=>$e->getMessage()]);}
    catch(Throwable $e){http_response_code(503);jsonout(['error'=>'Install master.sqlite in your private folder and enable PHP PDO SQLite.']);}
}
?><!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width"><title>TradingIntel master report</title><link rel="stylesheet" href="master.css?v=research3">
<main><nav><a href="index.php">Chart / backtest</a> · <a href="ai-access.php">AI API access</a> · <a href="consistency-report.php">Swing-level consistency: compare all names, entry prices and stop sizes</a></nav>
<h1>Master swing report</h1><p id="coverage">Loading snapshot…</p>
<form id="filters"><label>Start date<input id="start" type="date" required></label><label>End date<input id="end" type="date" required></label><label>Minimum fills<input id="fills" type="number" min="1" value="1"></label><button>Load report</button><button type="button" id="all">Entire dataset</button></form>
<details id="runSettings"><summary>Backtest settings — create a new saved run</summary>
<form id="customRunForm" class="controls">
<label>Consecutive swings<select id="runLength"><option>2</option><option>3</option><option>4</option></select></label>
<label>Entry rules<select id="runFamily"><option value="all">Pivot and candle-pair levels</option><option value="pivot">Pivot levels only</option><option value="pair">Candle-pair levels only</option></select></label>
<label>SL points<input id="runStop" type="number" value="10" min=".25" max="1000" step=".25" required></label>
<label>TP points<input id="runTP" type="number" value="10" min=".25" max="1000" step=".25" required></label>
<label>Follow-up bars<input id="runHorizon" type="number" value="30" min="1" max="200" required></label>
<label>USD / point / contract<input id="runPoint" type="number" value="20" min=".01" max="10000" step=".01" required></label>
<label>Contracts<input id="runQty" type="number" value="1" min="1" max="100" required></label>
<label>Round-trip USD / contract<input id="runFee" type="number" value="0" min="0" max="1000" step=".01" required></label>
<button>Run selected dates with these settings</button></form>
<p>Runs use the complete source candles and confirmed swing pairs. Each swing adds 25 pivot rules and 48 candle-pair rules. Both BUY and SELL are tested. Swing detection stays at five-bar confirmation with opposite-colour-first pairing.</p>
<button id="runCancel" disabled>Pause run</button><button id="runResume">Resume saved run</button><button id="runPreset">Open supplied master snapshot</button>
<p id="runProgress" role="status">Long runs progress in saved batches. Keep this page open while running; resume after an interruption. Each saved run uses additional private storage.</p></details>
<p id="runAssumptions"></p>
<p>Results rank by best net USD, then win rate. Each occurrence is an independent trial. Date filters include patterns starting within the range and completed outcome windows ending within it. Swing classification uses earlier history.</p>
<section id="fillIntel">
<h2>Fill-price level intelligence</h2>
<p>Compare candle A (earlier) and candle B (later) in each confirmed swing pair. Filter their actual colours to compare the red low, green open, or any other OHLC level.</p>
<form id="levelFilters">
<label>Swing pair<select id="levelOB"><option value="*">All pairs</option><option>BUY OB</option><option>SELL OB</option><option>No OB</option></select></label>
<label>Candle colour<select id="levelColor"><option value="*">All colours</option><option value="R">Red</option><option value="G">Green</option><option value="D">Doji</option></select></label>
<label>Candle<select id="levelSource"><option value="*">A and B</option><option>A</option><option>B</option></select></label>
<label>Price field<select id="levelField"><option value="*">All OHLC levels</option><option value="open">Open</option><option value="high">High</option><option value="low">Low</option><option value="close">Close</option></select></label>
<label>Entry event<select id="levelKind"><option value="*">Return and retest</option><option value="return">Return after confirmation</option><option value="retest">Retest after swing break</option></select></label>
<label>Offset<select id="levelOffset"><option value="0">Exact price</option><option value="-1">−1 tick</option><option value="1">+1 tick</option><option value="*">All offsets</option></select></label>
<label>Group by<select id="levelGroup"><option value="level">Level across sequences</option><option value="sequence">Sequence and level</option></select></label>
<label>Rank by<select id="levelSort"><option value="fill_rate">Fill rate</option><option value="buy_win">BUY win rate</option><option value="sell_win">SELL win rate</option><option value="buy_net">BUY net USD</option><option value="sell_net">SELL net USD</option><option value="best_net">Best net USD</option></select></label>
<button>Compare fill levels</button></form>
<p>Uses the dates and minimum fills above. Fill rate = fills / eligible complete windows. Each level counts its own first qualifying touch. One occurrence can touch several levels. BUY/SELL outcomes use the same fill price and the existing SL/TP assumptions.</p>
<p id="levelStatus" role="status">Choose filters and click Compare fill levels.</p>
<div class="scroll"><table id="levelRank"></table></div>
<button id="levelPrev" disabled>Previous levels</button><button id="levelNext" disabled>Next levels</button>
</section>
<p id="status" role="status"></p><div class="scroll"><table id="rank"></table></div><p><button id="prev">Previous</button><button id="next">Next</button></p>
<section id="detail" hidden><h2 id="title"></h2><p id="recap"></p><div class="scroll"><table id="stats"></table></div><h3>Realized cumulative outcome</h3><p><span class="buy">BUY</span> · <span class="sell">SELL</span> · USD</p><canvas id="equity" width="1100" height="260"></canvas>
<h3>Individual occurrences</h3><div id="examples"></div><button id="more">More occurrences</button><h3>Trade chart</h3><p id="tradeTitle">Select a filled BUY or SELL example.</p><canvas id="chart" width="1100" height="380"></canvas></section></main><script>window.TI_CSRF=<?=json_encode($_SESSION['csrf'])?>;</script><script src="master-ui.js?v=research3"></script><script src="fill-levels-ui.js?v=research3"></script><script src="master-run-ui.js?v=3.1-corrected"></script><script src="entry-context-ui.js?v=3"></script><script src="master-chart.js?v=3"></script><script src="historical-core.js?v=3"></script><script src="historical-ui.js?v=3"></script><script src="swing-levels-ui.js?v=1"></script></html>

