(function(){
'use strict';
const el=id=>document.getElementById(id);
const master=!!el('fillIntel');
const panel=document.createElement('section');
panel.id='historicalPanel';
panel.innerHTML=`<h2>Historical replay and fills</h2>
<p>One pinned comparison at a time. The last 20 minutes set the comparison; the drawing replays the next 30 minutes from an earlier day. Times use Eastern wall time.</p>
<form id="historyForm" class="controls">
<label>Last closed candle ET<input id="historyAnchor" type="datetime-local" required></label>
<label>Source<select id="historySource"><option value="day">Yesterday, same time</option><option value="week">Last week, same weekday / time</option><option value="both">Yesterday and last week</option></select></label>
<label>Drawing<select id="historyMode"><option value="relative">Same movement, shifted to current price</option><option value="absolute">Exact historical prices</option><option value="both">Both modes</option></select></label>
<label><input type="checkbox" id="historyActual">Reveal actual outcome</label>
<label><input type="checkbox" id="historyLevels" checked>Draw source OHLC levels</label>
<button>Draw historical replay</button><button type="button" id="historyPin">Use chart centre</button></form>
<p id="historyStatus" role="status">Choose a closed candle. On the chart page, Shift-click a candle to pin it. On the master report, selecting a trade pins its sequence confirmation.</p>
<canvas id="historyCanvas" width="1100" height="380" style="height:auto"></canvas>
<div class="scroll" style="overflow:auto;max-height:none"><table id="historyContext"></table></div>
<details><summary>Historical OHLC fills at this comparison point</summary><p>These are the earlier anchor candle's four OHLC prices, either unchanged or shifted by the close-to-close difference. Reveal actual outcome to inspect later simulated fills. Each price is tested independently as BUY and SELL.</p><div class="scroll" style="overflow:auto;max-height:none"><table id="historyFills"></table></div></details>
<h3>When did the replay hold or fail?</h3>
<form id="historyAuditForm" class="controls">
<label>Maximum context error (pts)<input id="historyError" type="number" min="0" max="1000" step=".25" value="3"></label>
<label>Exact-mode anchor proximity (ticks)<input id="historyDistance" type="number" min="0" max="4000" value="1"></label>
<label>Session block ET<select id="historySession"><option value="*">All</option><option>Midnight</option><option>London</option><option>New York</option><option>Asia</option></select></label>
<button>Run historical reliability report</button></form>
<p>Qualifying context also requires the same preceding trend direction and a current/prior 20-minute range ratio between 0.5 and 2. These are research criteria. Similarity alone cannot establish that following a replay is safe.</p>
<p id="historyAuditStatus" role="status">The audit uses the master snapshot and the page's selected dates. Half-hour anchors, 30-minute outcomes, fixed 10-point SL/TP and zero costs.</p>
<div class="scroll" style="overflow:auto;max-height:none"><table id="historyAudit"></table></div>
<details><summary>Historical level-fill outcomes by criteria and period</summary><div class="scroll" style="overflow:auto;max-height:none"><table id="historyAuditFills"></table></div></details>`;
if(master)el('detail').after(panel);else el('chart').parentElement.after(panel);
el('historyPin').hidden=master;
let data=null,request=0,auditRequest=0;
const dollars=value=>value==null?'—':Number(value).toLocaleString('en-US',{style:'currency',currency:'USD'});
const number=value=>value==null?'—':Number(value).toFixed(2);
function table(id,headers,rows){
 const table=el(id);table.replaceChildren();
 const tr=table.insertRow();headers.forEach(text=>{const th=document.createElement('th');th.textContent=text;tr.append(th);});
 rows.forEach(row=>{const tr=table.insertRow();row.forEach(text=>tr.insertCell().textContent=text);});
}
async function fetchHistory(resource,extra={}){
 const q=new URLSearchParams({resource,...extra});
 let url;
 if(master){q.set('data','1');url='master-report.php?'+q;}
 else{q.set('dataset',localStorage.getItem('ti-dataset')||'0');url='historical-access.php?'+q;}
 const response=await fetch(url),result=await response.json();
 if(!response.ok||result.error)throw Error(result.error||'Historical request failed');
 return result;
}
function selected(){
 if(!data||data.windows.current[20]?.[0]!==data.anchor)return [];
 const result=[];
 for(const source of ['day','week']){
  if(el('historySource').value!=='both'&&el('historySource').value!==source)continue;
  const expected=new Date(Date.parse(data.anchor+'Z')-(source==='day'?1:7)*86400000).toISOString().slice(0,16);
  if(data.windows[source][20]?.[0]!==expected)continue;
  for(const mode of ['relative','absolute']){
   if(el('historyMode').value!=='both'&&el('historyMode').value!==mode)continue;
   const row=HistoricalCore.analyze(data.windows.current,data.windows[source],mode);
   if(row)result.push({...row,source,color:source==='day'?(mode==='relative'?'#36d8e8':'#e2bb4d'):(mode==='relative'?'#bd9aff':'#ff91bd')});
  }
 }
 return result;
}
function draw(){
 const rows=selected(),canvas=el('historyCanvas'),ctx=canvas.getContext('2d'),w=canvas.width,h=canvas.height;
 ctx.clearRect(0,0,w,h);
 if(!rows.length){el('historyStatus').textContent='No complete, continuous earlier window at this time. Choose another anchor or source.';table('historyContext',[],[]);table('historyFills',[],[]);return;}
 const actual=el('historyActual').checked,current=data.windows.current;
 const shown=current.slice(0,actual?51:21);
 const values=[...shown.map(b=>b[4]),...rows.flatMap(r=>r.path.map(p=>p.price))];
 if(el('historyLevels').checked)values.push(...rows[0].levels.map(l=>l.price));
 let low=Math.min(...values),high=Math.max(...values),padding=(high-low)*.1||1;low-=padding;high+=padding;
 const x=minute=>65+(minute+20)/50*(w-170),y=price=>h-45-(price-low)/(high-low)*(h-85);
 ctx.font='12px Arial';
 for(let i=0;i<5;i++){const price=low+(high-low)*i/4;ctx.strokeStyle='#33485d';ctx.beginPath();ctx.moveTo(40,y(price));ctx.lineTo(w-100,y(price));ctx.stroke();ctx.fillStyle='#bed0e4';ctx.fillText(price.toFixed(2),w-90,y(price));}
 function line(points,color,dash){
  ctx.strokeStyle=color;ctx.setLineDash(dash);ctx.beginPath();
  points.forEach((p,i)=>i?ctx.lineTo(x(p.minute),y(p.price)):ctx.moveTo(x(p.minute),y(p.price)));ctx.stroke();ctx.setLineDash([]);
 }
 for(const r of rows)line(r.path,r.color,r.mode==='relative'?[7,4]:[2,5]);
 line(shown.map((b,i)=>({minute:i-20,price:b[4]})),'#ffffff',[]);
 ctx.strokeStyle='#aebfd3';ctx.setLineDash([3,4]);ctx.beginPath();ctx.moveTo(x(0),20);ctx.lineTo(x(0),h-35);ctx.stroke();ctx.setLineDash([]);
 ctx.fillStyle='#dce7f4';ctx.fillText('Anchor / last observed close',x(0)+5,16);
 for(const minute of [-20,-10,0,10,20,30])ctx.fillText((minute>0?'+':'')+minute+' min',x(minute)-15,h-12);
 if(el('historyLevels').checked)for(const l of rows[0].levels){
  ctx.strokeStyle=rows[0].color;ctx.setLineDash([2,7]);ctx.beginPath();ctx.moveTo(x(0),y(l.price));ctx.lineTo(x(30),y(l.price));ctx.stroke();ctx.setLineDash([]);
 }
 const threshold=Number(el('historyError').value),distance=Number(el('historyDistance').value);
 el('historyStatus').textContent=data.anchor+' ET · white = observed current closes'+(actual?' including later actual outcome':' through anchor only')+' · source levels use the first listed replay. Missing calendar-day windows are omitted.';
 table('historyContext',['Source','Mode','Earlier anchor','Context error pts','Anchor distance pts','Range ratio','Volume ratio','Trend matches','Criteria','Projected change','Actual change','Path error pts'],
 rows.map(r=>[r.source==='day'?'Yesterday':'Last week',r.mode,r.source_anchor,number(r.context_error),number(r.anchor_distance),number(r.range_ratio),number(r.volume_ratio),r.trend_match?'Yes':'No',HistoricalCore.qualifies(r,threshold,distance)?'Qualifies':'Outside criteria',number(r.predicted_change),actual?number(r.actual_change):'Hidden',actual?number(r.path_error):'Hidden']));
 for(let i=0;i<rows.length;i++)el('historyContext').rows[i+1].cells[0].style.color=rows[i].color;
 table('historyFills',['Source / mode','Field','Original price','Tested price','Fill','BUY outcome','SELL outcome'],
 rows.flatMap(r=>r.levels.map(l=>[r.source+' / '+r.mode,l.field,number(l.source_price),number(l.price),!actual?'Hidden':!r.complete?'Incomplete window':l.filled?'Filled':'Unfilled',actual?dollars(l.buy?.net_usd):'Hidden',actual?dollars(l.sell?.net_usd):'Hidden'])));
}
async function select(stamp){
 const id=++request;el('historyAnchor').value=stamp;el('historyStatus').textContent='Loading earlier calendar windows…';
 try{const result=await fetchHistory('historical_context',{anchor:stamp});if(id!==request)return;data=result;draw();}
 catch(e){if(id===request)el('historyStatus').textContent=e.message;}
}
function interval(rate,n){
 if(!n||rate==null)return '—';
 const p=rate/100,z=1.96,den=1+z*z/n,mid=(p+z*z/(2*n))/den,half=z*Math.sqrt(p*(1-p)/n+z*z/(4*n*n))/den;
 return ((mid-half)*100).toFixed(1)+'–'+((mid+half)*100).toFixed(1)+'%';
}
el('historyAuditForm').onsubmit=async event=>{
 event.preventDefault();const id=++auditRequest,start=el('start').value,end=el('end').value;el('historyAuditStatus').textContent='Comparing historical criteria and subsequent outcomes…';
 try{
  const result=await fetchHistory('historical_report',{start:el('start').value,end:el('end').value,error:el('historyError').value,distance_ticks:el('historyDistance').value,session:el('historySession').value});
  if(id!==auditRequest)return;
  if(result.needs_snapshot){el('historyAuditStatus').textContent=result.message;return;}
  el('historyAuditStatus').textContent=start+' — '+end+' ET. '+result.method+' Split date '+result.settings.split_date+'. Later rows help check whether the relationship persisted. Sample sizes below 30 are flagged. Win intervals use a binomial approximation and can understate uncertainty when outcomes are correlated. Session blocks are Midnight 00:00–03:00, London 03:00–09:30, New York 09:30–18:00, Asia 18:00–24:00 ET. These associations do not establish a cause.';
  table('historyAudit',['Source','Mode','Criteria','Period','Comparisons','Direction agreement','Path error pts','Mean volume ratio','Follow trades','Win rate','Win 95% interval','Net USD'],
   result.rows.map(r=>[r.source,r.mode,r.qualifies?'Qualifies':'Outside',r.period,r.n+(r.n<30?' · small sample':''),number(r.direction_agreement)+'%',number(r.path_error),number(r.volume_ratio),r.trades,number(r.win_rate)+'%',interval(r.win_rate,r.trades),dollars(r.net_usd)]));
  table('historyAuditFills',['Source','Mode','Criteria','Period','Field','Fills / eligible','Fill rate','BUY win','BUY net','SELL win','SELL net'],
   result.levels.map(r=>[r.source,r.mode,r.qualifies?'Qualifies':'Outside',r.period,r.field,r.filled+' / '+r.eligible,number(r.fill_rate)+'%',number(r.buy_win)+'%',dollars(r.buy_net),number(r.sell_win)+'%',dollars(r.sell_net)]));
 }catch(e){if(id===auditRequest)el('historyAuditStatus').textContent=e.message;}
};
function clearAudit(){
 auditRequest++;el('historyAudit').replaceChildren();el('historyAuditFills').replaceChildren();
 el('historyAuditStatus').textContent='Dates or criteria changed. Run the historical reliability report to update these results.';
}
for(const id of ['start','end','historyError','historyDistance','historySession'])el(id).addEventListener('change',clearAudit);
el('all')?.addEventListener('click',clearAudit);
el('historyForm').onsubmit=event=>{event.preventDefault();select(el('historyAnchor').value);};
for(const id of ['historySource','historyMode','historyActual','historyLevels','historyError','historyDistance'])el(id).addEventListener('change',()=>{if(data)draw();});
window.HistoricalReplay={select};
if(!master){
 el('historyPin').onclick=()=>{const visible=candles.slice(offset,offset+count),bar=visible[Math.floor(visible.length/2)];if(bar){const tf=window.LabLoadedTF||1;select(new Date(Date.parse(bar[0]+'Z')+(tf-1)*60000).toISOString().slice(0,16));}};
 el('chart').addEventListener('click',event=>{
  if(!event.shiftKey)return;
  const rect=el('chart').getBoundingClientRect(),visible=candles.slice(offset,offset+count);
  const index=Math.floor((event.clientX-rect.left)/(rect.width-80)*visible.length),bar=visible[index];
  if(bar){const tf=window.LabLoadedTF||1;select(new Date(Date.parse(bar[0]+'Z')+(tf-1)*60000).toISOString().slice(0,16));panel.scrollIntoView({behavior:'smooth'});}
 });
}
})();
