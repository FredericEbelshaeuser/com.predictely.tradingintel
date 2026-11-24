<?php
require __DIR__.'/bootstrap.php';
auth();
require __DIR__.'/master-lib.php';
$message='';
if($_SERVER['REQUEST_METHOD']==='POST'){
    csrf();
    $token=($_POST['action']??'')==='rotate'?bin2hex(random_bytes(32)):'';
    $file=ml_private().'/master-api-key.sha256';
    if(file_put_contents($file,$token?hash('sha256',$token):'',LOCK_EX)===false)throw new RuntimeException('Cannot save API key');
    chmod($file,0600);
    $message=$token?'Copy your token now. It is shown once. '.$token:'Access revoked.';
}
?><!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width"><title>TradingIntel AI API</title><link rel="stylesheet" href="master.css?v=research3">
<main><nav><a href="index.php">Chart / backtest</a> · <a href="master-report.php">Master report</a></nav>
<h1>AI research API</h1>
<p>Read access to the complete candle snapshot, swing features, entry rules and paired BUY / SELL outcomes.</p>
<?php if($message): ?><p class="card"><?=htmlspecialchars($message)?></p><?php endif ?>
<form method="post"><input type="hidden" name="csrf" value="<?=htmlspecialchars($_SESSION['csrf'])?>"><button name="action" value="rotate">Create / replace access token</button><button name="action" value="revoke">Revoke access</button></form>
<p>Send your token in the Authorization header over HTTPS. Replacing the token immediately revokes the previous token.</p>
<pre>GET research-api.php?resource=manifest
Authorization: Bearer YOUR_TOKEN</pre>
<table><tr><th>Resource</th><th>Parameters</th><th>Returns</th></tr>
<tr><td>manifest</td><td>None</td><td>Coverage, assumptions, engine version, 146 entry rules</td></tr>
<tr><td>candles</td><td>start, end, limit (up to 5000), cursor</td><td>OHLCV and VWAP, next_cursor</td></tr>
<tr><td>rankings</td><td>start, end, min_occ, min_fills, page (starts at 0), limit (up to 100)</td><td>All sequence / rule rankings, next_page</td></tr>
<tr><td>examples</td><td>start, end, sid, rid, ob, color, limit (up to 500), cursor</td><td>Available features and paired outcomes, next_cursor</td></tr>
<tr><td>fill_levels</td><td>start, end, ob (* / BUY OB / SELL OB / No OB), color (* / R / G / D), source (* / A / B), field (* / open / high / low / close), kind (* / return / retest), offset (* / -1 / 0 / 1), group (level / sequence), sort (fill_rate / buy_win / sell_win / buy_net / sell_net / best_net), min_fills, sid, page, limit</td><td>Filled / eligible, unfilled, ineligible, fill rate, BUY/SELL win rate, net USD, average per fill</td></tr>
<tr><td>curve</td><td>start, end, sid, rid, ob, color</td><td>Daily realized equity and closed-trade drawdown</td></tr></table>
<p>Dates use YYYY-MM-DD, inclusive. Omit dates for the full dataset. Omit sid and rid to retrieve every example. Follow next_cursor until null, preserving other parameters. Read the JSON data field.</p>
<p>Timestamps preserve the source’s Eastern wall time. Features become available at the confirmation candle’s close. Outcome fields contain future information and belong in training targets. Use chronological train / validation / test periods with a gap covering the 30-bar outcome window.</p>
<h2>Saved runs and historical research</h2>
<p>Create custom runs from Backtest settings on master-report.php. Copy the run ID from its URL and append &amp;run=RUN_ID to manifest, rankings, fill_levels, examples, curve or entry_context_report. Each run's manifest lists its actual settings and rule IDs. The bearer API reads completed runs. Creating, pausing and resuming runs uses your signed-in master report.</p>
<table><tr><th>Resource</th><th>Parameters</th><th>Data</th></tr>
<tr><td>historical_context</td><td>anchor=YYYY-MM-DDTHH:MM</td><td>Current, previous-calendar-day and previous-week 1-minute windows. Twenty minutes of context and thirty minutes of future outcomes. Current rows after the anchor contain future outcomes. Source windows are earlier historical data.</td></tr>
<tr><td>historical_report</td><td>start, end, error (default 3 points), distance_ticks (default 1), session (*, Midnight, London, New York, Asia)</td><td>Criteria groups, earlier/later periods, direction agreement, path error, follow-replay trades and OHLC fill results. Uses the supplied fixed 30-minute / 10-point-risk audit independently of custom run settings.</td></tr>
<tr><td>entry_context_report</td><td>start, end, sid, rid, ob, color, run</td><td>Always BUY/SELL versus following the latest confirmed OB or latest two agreeing OBs; results grouped by prior OB directions.</td></tr>
<tr><td>entry_context_details</td><td>entry=YYYY-MM-DDTHH:MM, run</td><td>Both candles' OHLCV for the latest two confirmed wick-break orderblocks in the twenty minutes before entry.</td></tr></table>
<p>Filled examples include outcome.entry_context with its own available_at_et timestamp. This entry-time context can become known later than the sequence's initial confirmation. Use each feature's availability time when training.</p>
<p>The supplied snapshot’s pair-level rules use rid 50–145. Custom runs use the IDs in their own manifest. Use the sid, rid, ob and color from a fill_levels row with examples or curve to inspect exactly that cohort. sid=0 combines sequences. examples.features.level_reference gives the source candle time, colour, OHLC field, original price, offset and tested price. Both chronological candles appear in features.legs[].candles. Outcomes provide the actual simulated fill price and time. Returns start after confirmation; retests start after a new break of the selected swing level. Several level tests may fill on the same occurrence. The legacy rankings resource retains its original 50 pivot rules.</p>
<p>The supplied snapshot contains two consecutive swings, 146 entry rules, 10-point SL / TP, 30-bar follow-up, $20 per point, one contract and zero costs. Saved runs carry the settings you chose. Occurrences are independent simulations. The API exposes the saved dataset and results; model training is performed by your chosen client.</p>
<h2>Independent swing-level visits</h2><p><code>research-api.php?resource=swing_levels&amp;day=2025-10-27&amp;horizon=120&amp;reaction=10&amp;offset=0</code> uses the existing bearer token. One day of confirmations per request; use next_day to paginate. horizon is 1-240 minutes; reaction is 1-60; offset is -1, 0 or +1 tick. Returns every confirmed swing, both source candles, all eight OHLC references, first later visit, visit episodes, post-touch up/down excursions, and completeness flags. Observations are independent of the saved-run strategy settings and are not trade profits. Source levels are known at available_at_et; visit/reaction fields are future outcomes and must not be training inputs for that same time.</p><h2>Full-dataset consistency audit</h2><p>Resources: <code>consistency_manifest</code>, <code>consistency_rankings</code>, <code>consistency_example</code>. Rankings accept scenario, side, source, field, visit (first/repeat), stop, context_kind (OB type/OB size/Prior FVG/Displacement/Combined), min_n, min_origins, min_win, recent_min, stable (1 requires profitable earlier and 2025 samples), sort (recent/win/net/sample), page and limit. Example accepts id and example (0-2) for OB-type rows. Uses private consistency.sqlite; independent of custom runs. Results are counterfactual trials with a $5 cost sensitivity, not a one-position portfolio or future guarantee.</p></main></html>

