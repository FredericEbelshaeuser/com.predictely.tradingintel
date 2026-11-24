'use strict';
const $=id=>document.getElementById(id);
const money=v=>Number(v).toLocaleString('en-US',{style:'currency',currency:'USD'});
let meta,page=0,selected,cursor=null,generation=0;
let activeRun=new URLSearchParams(location.search).get('run')||'';
function params(resource,extra={}){return new URLSearchParams({data:1,resource,...(activeRun?{run:activeRun}:{}),start:$('start').value,end:$('end').value,...extra});}
async function api(resource,extra={}){const r=await fetch('master-report.php?'+params(resource,extra));const d=await r.json();if(!r.ok||d.error)throw Error(d.error||'Request failed');return d;}
function cell(row,value){const c=row.insertCell();c.textContent=value;return c;}
function head(table,names){table.replaceChildren();const r=table.insertRow();names.forEach(n=>{const c=document.createElement('th');c.textContent=n;r.append(c);});}
function action(parent,text,fn){const b=document.createElement('button');b.textContent=text;b.onclick=()=>Promise.resolve(fn()).catch(error);parent.append(b);}
function error(e){$('status').textContent=e.message;}
async function rankings(){
 const gen=++generation;$('status').textContent='Loading report…';$('detail').hidden=true;
 const d=await api('rankings',{page,min_fills:$('fills').value});
 if(gen!==generation)return;
 head($('rank'),['Swing sequence','Entry rule','Fills / eligible','BUY net','BUY wins','SELL net','SELL wins','']);
 d.rows.forEach(v=>{const r=$('rank').insertRow();[v.sequence,v.entry_rule,v.n+' / '+v.eligible,money(v.bnet),(100*v.bw/v.n).toFixed(1)+'%',money(v.snet),(100*v.sw/v.n).toFixed(1)+'%'].forEach(x=>cell(r,x));action(cell(r,''),'Inspect',()=>inspect(v));});
 $('status').textContent=d.rows.length?'Page '+(page+1):(meta.settings.family==='pair'?'This run contains candle-pair rules. Use Compare fill levels above.':'No filled scenarios for this range.');
 $('prev').disabled=page===0;$('next').disabled=d.next_page===null;
}
function plot(canvas,series){
 const c=canvas.getContext('2d'),w=canvas.width,h=canvas.height;c.clearRect(0,0,w,h);
 const values=series.flatMap(s=>s.values);if(!values.length)return;
 let low=Math.min(0,...values),high=Math.max(0,...values);if(low===high)high++;
 const y=v=>h-30-(v-low)/(high-low)*(h-60);
 c.fillStyle='#bbcddd';c.font='12px Arial';c.fillText(money(high),8,16);c.fillText(money(low),8,h-8);
 series.forEach(s=>{c.strokeStyle=s.color;c.beginPath();s.values.forEach((v,i)=>{const x=90+i/Math.max(1,s.values.length-1)*(w-110);i?c.lineTo(x,y(v)):c.moveTo(x,y(v));});c.stroke();});
}
function cohort(v){return {sid:v.sid,rid:v.rid,...(v.ob?{ob:v.ob,color:v.color}:{})};}
async function inspect(v){
 window.dispatchEvent(new Event('master-inspect'));window.MasterChart?.clear();selected=v;cursor=null;const gen=++generation;
 $('detail').hidden=false;$('title').textContent=v.sequence;$('recap').textContent=v.entry_rule+' · '+(v.ob?v.ob+' / '+v.color+' · ':v.occurrences+' patterns · ')+v.completed+' complete windows · '+v.eligible+' eligible · '+v.n+' closed trades per side';
 $('examples').replaceChildren();$('stats').replaceChildren();$('tradeTitle').textContent='Select a filled BUY or SELL example.';
 $('chart').getContext('2d').clearRect(0,0,1100,380);
 const d=await api('curve',cohort(v));if(gen!==generation)return;
 head($('stats'),['Side','Closed','Win / loss / flat','Win rate','Net points','Net USD','Closed drawdown']);
 for(const side of ['buy','sell']){const p=side[0],r=$('stats').insertRow();[side.toUpperCase(),v.n,[v[p+'w'],v[p+'l'],v.n-v[p+'w']-v[p+'l']].join(' / '),(100*v[p+'w']/v.n).toFixed(1)+'%',v[p+'net']/(meta.settings.point*meta.settings.qty),money(v[p+'net']),money(d.drawdown_usd[side])].forEach(x=>cell(r,x));}
 plot($('equity'),[{color:'#35d6ad',values:[0,...d.daily_equity.map(x=>x.buy)]},{color:'#ff8197',values:[0,...d.daily_equity.map(x=>x.sell)]}]);
 await examples(gen);$('detail').scrollIntoView({behavior:'smooth'});
}
async function examples(gen=generation){
 const v=selected,d=await api('examples',{...cohort(v),limit:20,...(cursor?{cursor}:{})});if(gen!==generation)return;
 cursor=d.next_cursor;$('more').disabled=!cursor;
 d.examples.forEach(e=>{const row=document.createElement('div');row.textContent=e.features.available_at_et+' ET · ';$('examples').append(row);const ref=e.features.level_reference;if(ref)row.append('candle '+ref.source_candle+' '+ref.color+' '+ref.field+' '+ref.source_price.toFixed(2)+' / tested '+ref.tested_price.toFixed(2)+' · ');const o=e.outcome;if(o.status!=='filled'){row.append(o.status.replaceAll('_',' '));return;}for(const side of ['buy','sell'])action(row,side.toUpperCase()+' '+money(o[side].net_usd)+' · '+o[side].reason,()=>trade(e,side));});
}
async function trade(e,side){
 window.HistoricalReplay?.select(new Date(Date.parse(e.features.available_at_et+'Z')-60000).toISOString().slice(0,16));
 await MasterChart.show(e,side);
}
$('filters').onsubmit=e=>{e.preventDefault();page=0;rankings().catch(error);};
$('all').onclick=()=>{ $('start').value=meta.first.slice(0,10);$('end').value=meta.last.slice(0,10);page=0;rankings().catch(error);};
$('prev').onclick=()=>{page--;rankings().catch(error);};$('next').onclick=()=>{page++;rankings().catch(error);};$('more').onclick=()=>examples().catch(error);
(async()=>{const d=await api('manifest');meta=d.snapshot;window.updateRunAssumptions?.(meta);$('coverage').textContent=meta.candles.toLocaleString()+' candles · '+meta.first+' — '+meta.last+' ET · '+meta.occurrences.toLocaleString()+' occurrences · '+meta.paired_fills.toLocaleString()+' pivot-rule paired fills'+(meta.pair_level_fills?' · '+meta.pair_level_fills.toLocaleString()+' candle-level paired fills':'');$('start').value=meta.first.slice(0,10);$('end').value=meta.last.slice(0,10);await rankings();})().catch(error);

