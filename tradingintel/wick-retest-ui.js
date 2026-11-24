(()=>{
const $=id=>document.getElementById(id),fmt=n=>Number(n).toLocaleString('en-US',{maximumFractionDigits:2});
let result=null,stopped=false,page=0,runId=0,bars=[],trade=null,offset=0,count=100,drag=null,chartId=0;
function table(el,heads,rows){el.replaceChildren();const h=el.insertRow();heads.forEach(v=>{const t=document.createElement('th');t.textContent=v;h.append(t);});for(const row of rows){const tr=el.insertRow();for(const v of row){const td=tr.insertCell();if(v instanceof Node)td.append(v);else td.textContent=v;}}}
async function get(q,signal){const r=await fetch('wick-retest-report.php?'+new URLSearchParams(q),{signal});const d=await r.json();if(!r.ok||d.error)throw Error(d.error||'Request failed');return d;}
let controller=null;
$('run').onsubmit=async e=>{
 e.preventDefault();const id=++runId;stopped=false;result=null;page=0;$('export').disabled=true;$('go').disabled=true;$('cancel').disabled=false;$('summary').hidden=true;$('chartSection').hidden=true;$('trades').replaceChildren();++chartId;
 controller=new AbortController();
 try{
 const settings=Object.fromEntries(['target','life','hold','wickRatio','qty','point','cost'].map(k=>[k,Number($(k).value)]));
 if(settings.target*4!==Math.round(settings.target*4))throw Error('Target must use 0.25-point increments');
 const engine=new WickRetest.Engine(settings);let cursor=null,n=0;
 do{const d=await get({data:'candles',start:$('start').value,end:$('end').value,...(cursor?{cursor}:{})},controller.signal);if(stopped)return;for(const b of d.bars)engine.feed(b);n+=d.bars.length;cursor=d.next_cursor;$('status').textContent=fmt(n)+' candles processed · '+engine.trades.length+' closed trades';await new Promise(r=>setTimeout(r,0));}while(cursor);
 result={...engine.result(),start:$('start').value,end:$('end').value,candles:n};$('status').textContent=fmt(n)+' candles · '+result.detected+' strict shapes · '+result.accepted+' accepted setups · '+result.closed+' closed trades · '+(result.closed?(100*result.wins/result.closed).toFixed(1):'0')+'% wins · $'+fmt(result.net)+' net · $'+fmt(result.drawdown)+' drawdown';
 const host=$('summary');host.hidden=false;host.replaceChildren();const p=document.createElement('p');p.textContent='Skipped: '+result.early+' already returned before confirmation; '+result.busy+' while another setup was active. Missed second entries: '+result.missedSecond+'. '+(result.open?'An open trade remains: '+result.open.entryTime+' entry '+result.open.entry+'.':'No open trade.')+(result.pending?' One pending entry at range end.':'');host.append(p);
 const t=document.createElement('table');host.append(t);table(t,['Side / entry','Closed','Wins','Net USD'],['BUY','SELL'].flatMap(side=>[1,2].map(stage=>{const a=result.trades.filter(t=>t.side===side&&t.stage===stage);return [side+' / '+(stage===1?'first OPEN':'second WICK'),a.length,a.filter(t=>t.net>0).length,'$'+fmt(a.reduce((s,t)=>s+t.net,0))];})));
 $('export').disabled=false;render();
 }catch(e){$('status').textContent=e.name==='AbortError'?'Run cancelled; no partial result reported.':e.message;}
 finally{if(id===runId){$('go').disabled=false;$('cancel').disabled=true;}}
};
$('cancel').onclick=()=>{stopped=true;controller?.abort();$('status').textContent='Run cancelled; no partial result reported.';};
function render(){if(!result)return;const a=result.trades.slice(page*25,page*25+25);table($('trades'),['Entry ET','Side','Return','Entry','SL','TP','Exit ET','Reason','Net USD','Chart'],a.map(t=>{const b=document.createElement('button');b.textContent='Inspect';b.onclick=()=>show(t);return [t.entryTime,t.side,t.stage===1?'OPEN':'WICK',t.entry,t.stop,t.tp,t.exitTime,t.reason,'$'+fmt(t.net),b];}));$('prev').disabled=page===0;$('next').disabled=(page+1)*25>=result.trades.length;}
$('prev').onclick=()=>{if(page>0){page--;render();}};$('next').onclick=()=>{if(result&&(page+1)*25<result.trades.length){page++;render();}};
$('export').onclick=()=>{const blob=new Blob([JSON.stringify(result,null,2)],{type:'application/json'}),url=URL.createObjectURL(blob),a=document.createElement('a');a.href=url;a.download='strict-wick-retest-results.json';a.click();setTimeout(()=>URL.revokeObjectURL(url),1000);};
async function show(t){const id=++chartId;$('chartSection').hidden=false;$('chartInfo').textContent='Loading candles…';try{const d=await get({data:'chart',from:t.a.ts,to:t.exitTime});if(id!==chartId)return;trade=t;bars=d.bars;offset=0;count=Math.min(120,bars.length);$('chartInfo').textContent=t.side+' '+(t.stage===1?'first OPEN':'second WICK')+' · '+t.entryTime+' · '+t.reason+' · $'+fmt(t.net)+' · confirmed '+t.confirmed;paint();$('chartSection').scrollIntoView({behavior:'smooth'});}catch(e){if(id===chartId)$('chartInfo').textContent=e.message;}}
const cv=$('chart'),ctx=cv.getContext('2d');
function paint(){ctx.fillStyle='#102031';ctx.fillRect(0,0,1400,500);if(!trade||!bars.length)return;offset=Math.max(0,Math.min(offset,Math.max(0,bars.length-count)));const a=bars.slice(offset,offset+count),t=trade,lo=Math.min(t.stop,t.tp,...a.map(b=>b.l))-2,hi=Math.max(t.stop,t.tp,...a.map(b=>b.h))+2,y=v=>25+(hi-v)/(hi-lo)*390,x=i=>20+(i+.5)*1120/a.length;ctx.font='12px Arial';
a.forEach((b,i)=>{ctx.fillStyle=ctx.strokeStyle=b.c>=b.o?'#24caa1':'#f2768e';const xx=x(i),w=Math.max(2,750/a.length);ctx.beginPath();ctx.moveTo(xx,y(b.h));ctx.lineTo(xx,y(b.l));ctx.stroke();ctx.fillRect(xx-w/2,Math.min(y(b.o),y(b.c)),w,Math.max(1,Math.abs(y(b.o)-y(b.c))));const names=[[t.a.ts,'A'],[t.b.ts,'B'],[t.entryTime,'ENTRY'],[t.exitTime,'EXIT']].filter(([ts])=>ts===b.ts).map(([,n])=>n).join('/');if(names){ctx.fillStyle='white';ctx.fillText(names,xx-8,y(b.h)-10);}if(i%Math.max(1,Math.ceil(a.length/10))===0){ctx.fillStyle='white';ctx.fillText(b.ts.slice(11),xx,450);}});
const ls=[[t.b.o,'B OPEN','#ffdf6c'],[t.side==='SELL'?t.b.h:t.b.l,'B WICK','#73caff'],[t.stop,'SL','#fa7894'],[t.tp,'TP','#59dba9'],[t.entry,'FILL','#ffffff']].sort((a,b)=>b[0]-a[0]);let prior=20;
for(const [v,label,color]of ls){const yy=y(v),ly=Math.max(yy,prior+18);prior=ly;ctx.strokeStyle=ctx.fillStyle=color;ctx.setLineDash([5,4]);ctx.beginPath();ctx.moveTo(20,yy);ctx.lineTo(1140,yy);ctx.stroke();ctx.setLineDash([]);ctx.beginPath();ctx.moveTo(1140,yy);ctx.lineTo(1160,ly);ctx.stroke();ctx.fillText(label+' '+fmt(v),1165,ly+4);}
ctx.fillStyle='white';ctx.fillText('Original A '+t.a.ts+' / B '+t.b.ts+' ET',20,485);}
cv.onpointerdown=e=>{drag={x:e.clientX,offset};cv.setPointerCapture(e.pointerId);};cv.onpointermove=e=>{if(drag){offset=Math.round(drag.offset-(e.clientX-drag.x)*count/cv.clientWidth);paint();}};cv.onpointerup=cv.onpointercancel=()=>drag=null;cv.onwheel=e=>{e.preventDefault();count=Math.max(12,Math.min(bars.length,Math.round(count*(e.deltaY>0?1.2:.8))));paint();};
})();
