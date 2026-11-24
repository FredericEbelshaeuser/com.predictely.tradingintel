(function(){
'use strict';
const canvas=document.getElementById('chart');
let bars=[],example=null,side='buy',events=[],from=0,size=100,shift=0,drag=null,generation=0;
let earliestDay='',latestDay='',extending=false;
const controls=document.createElement('div');
controls.className='controls';
controls.innerHTML='<button id="mcEarlier">Load earlier day</button><button id="mcLater">Load later day</button><button id="mcReset">Reset to trade</button><label><input type="checkbox" id="mcOB" checked>Show pre-entry OB context</label><span>Drag to pan time and price · wheel to zoom · double-click to reset</span>';
canvas.before(controls);
const footer=document.createElement('p');canvas.after(footer);
const contextTable=document.createElement('table');footer.after(contextTable);
const byId=id=>document.getElementById(id);
const dayShift=(stamp,days)=>new Date(Date.parse(stamp.slice(0,10)+'T12:00Z')+days*86400000).toISOString().slice(0,10);
async function fetchBars(start,end){
 let cursor=null,result=[];
 do{
  const data=await api('candles',{start,end,limit:5000,...(cursor?{cursor}:{})});
  result.push(...data.candles);cursor=data.next_cursor;
 }while(cursor);
 return result;
}
function reset(){
 if(!example)return;
 const i=bars.findIndex(b=>b.ts>=example.features.legs[0].pivot);
 const j=bars.findIndex(b=>b.ts>=example.outcome[side].exit_bar_et);
 size=Math.min(bars.length,Math.max(100,j-i+60));from=Math.max(0,i-30);shift=0;draw();
}
function draw(){
 if(!example||!bars.length)return;
 from=Math.max(0,Math.min(from,Math.max(0,bars.length-size)));
 const view=bars.slice(Math.floor(from),Math.floor(from)+size),c=canvas.getContext('2d'),w=canvas.width,h=canvas.height;
 if(!view.length)return;
 c.clearRect(0,0,w,h);
 const out=example.outcome,t=out[side],tradeVisible=out.entry_bar_et<=view.at(-1).ts&&t.exit_bar_et>=view[0].ts;
 let low=Math.min(...view.map(b=>b.l)),high=Math.max(...view.map(b=>b.h));
 if(tradeVisible){low=Math.min(low,t.stop,t.target);high=Math.max(high,t.stop,t.target);}
 const pad=(high-low)*.1||1;low-=pad;high+=pad;low+=shift;high+=shift;
 canvas.priceSpan=high-low;
 const x=i=>45+(i+.5)/view.length*(w-145),y=p=>h-35-(p-low)/(high-low)*(h-65),bw=Math.max(1,(w-145)/view.length*.65);
 c.font='11px Arial';
 for(let n=0;n<5;n++){const p=low+(high-low)*n/4;c.strokeStyle='#30445b';c.beginPath();c.moveTo(35,y(p));c.lineTo(w-90,y(p));c.stroke();c.fillStyle='#bcd0e5';c.fillText(p.toFixed(2),w-85,y(p));}
 view.forEach((b,i)=>{c.strokeStyle=c.fillStyle=b.c>=b.o?'#35d6ad':'#ff8197';c.beginPath();c.moveTo(x(i),y(b.h));c.lineTo(x(i),y(b.l));c.stroke();c.fillRect(x(i)-bw/2,Math.min(y(b.o),y(b.c)),bw,Math.max(1,Math.abs(y(b.o)-y(b.c))));});
 if(tradeVisible){
  const entry=Math.max(0,view.findIndex(b=>b.ts>=out.entry_bar_et));
  let exit=view.findIndex(b=>b.ts>=t.exit_bar_et);if(exit<0)exit=view.length-1;
  for(const [label,p,color]of [['Entry',out.entry,'#ffe082'],['SL',t.stop,'#ff8197'],['TP',t.target,'#35d6ad'],['Exit',t.exit,'#ffffff']]){
   c.strokeStyle=c.fillStyle=color;c.setLineDash([5,4]);c.beginPath();c.moveTo(x(entry),y(p));c.lineTo(x(exit)+10,y(p));c.stroke();c.fillText(label+' '+p,x(exit)+13,y(p)-3);
  }
  c.setLineDash([]);c.fillStyle='#ffe082';c.fillText('▼ fill',x(entry)-12,y(out.entry)-14);
 }
 if(byId('mcOB').checked)for(const event of events){
  const a=view.findIndex(b=>b.ts===event.A.ts),b=view.findIndex(b=>b.ts===event.B.ts);
  if(a<0&&b<0)continue;
  c.strokeStyle=c.fillStyle=event.side==='BUY OB'?'#55e6ab':'#ffafc1';
  const top=y(Math.max(event.A.h,event.B.h)),bottom=y(Math.min(event.A.l,event.B.l));
  c.strokeRect(x(Math.max(0,a))-bw,top-5,x(Math.max(0,b))-x(Math.max(0,a))+bw*2,bottom-top+10);
  c.fillText(event.side,x(Math.max(0,a))-bw,top-10);
 }
 const reference=example.features.level_reference;
 if(reference){
  const pair=example.features.legs[reference.swing-1].candles;
  for(const [role,source]of Object.entries(pair)){
   const i=view.findIndex(b=>b.ts===source.time_et);if(i<0)continue;
   c.strokeStyle=c.fillStyle='#ffe082';c.strokeRect(x(i)-bw/2-3,y(source.high)-4,bw+6,y(source.low)-y(source.high)+8);c.fillText(role+' '+source.color,x(i)-10,y(source.high)-17);
  }
 }
 c.fillStyle='#bccddd';
 const step=Math.max(1,Math.ceil(view.length/7));
 for(let i=0;i<view.length;i+=step)c.fillText(view[i].ts.slice(5).replace('T',' '),x(i)-35,h-10);
 footer.textContent=view[0].ts+' — '+view.at(-1).ts+' ET · '+bars.length.toLocaleString()+' loaded candles';
}
async function show(e,s){
 const id=++generation;example=e;side=s;events=[];bars=[];
 document.getElementById('tradeTitle').textContent='Loading interactive trade context…';
 const start=dayShift(e.features.legs[0].pivot,-1),end=dayShift(e.outcome[s].exit_bar_et,1);
 const [loaded,context]=await Promise.all([fetchBars(start,end),api('entry_context_details',{entry:e.outcome.entry_bar_et})]);
 if(id!==generation)return;
 bars=loaded;events=context.events;earliestDay=start;latestDay=end;
 const out=e.outcome,t=out[s],reference=e.features.level_reference;
 const recent=events.map(v=>v.side).join(' → ')||'No confirmed OB in prior 20 minutes';
 document.getElementById('tradeTitle').textContent=s.toUpperCase()+' what-if · entry '+out.entry_bar_et+' ET · '+money(t.net_usd)+' · '+t.reason+' · pre-entry context '+recent+(reference?' · candle '+reference.source_candle+' '+reference.color+' '+reference.field+' '+reference.tested_price:'');
 contextTable.replaceChildren();
 const tr=contextTable.insertRow();for(const name of ['Confirmed pre-entry OB','Candle','Time ET','Open','High','Low','Close']){const th=document.createElement('th');th.textContent=name;tr.append(th);}
 for(const event of events)for(const role of ['A','B']){const b=event[role],tr=contextTable.insertRow();[event.side,role,b.ts,b.o,b.h,b.l,b.c].forEach(v=>tr.insertCell().textContent=v);}
 reset();
}
async function extend(direction){
 if(!bars.length||extending)return;const id=generation,oldFirst=bars[Math.floor(from)]?.ts;
 const day=dayShift(direction<0?earliestDay:latestDay,direction);
 extending=true;let more;
 try{more=await fetchBars(day,day);}finally{extending=false;}
 if(id!==generation)return;
 if(direction<0)earliestDay=day;else latestDay=day;
 const merged=new Map([...bars,...more].map(b=>[b.id,b]));
 bars=[...merged.values()].sort((a,b)=>a.ts.localeCompare(b.ts)||a.id-b.id);
 from=Math.max(0,bars.findIndex(b=>b.ts===oldFirst));draw();
 if(!more.length)footer.textContent+=' · no candles on '+day;
}
canvas.style.touchAction='none';
canvas.addEventListener('pointerdown',event=>{if(!bars.length)return;drag={x:event.clientX,y:event.clientY,from,shift,span:canvas.priceSpan||1};canvas.setPointerCapture(event.pointerId);});
canvas.addEventListener('pointermove',event=>{
 if(!drag)return;const rect=canvas.getBoundingClientRect();
 from=drag.from-(event.clientX-drag.x)/rect.width*size;
 shift=drag.shift+(event.clientY-drag.y)/rect.height*drag.span;draw();
});
canvas.addEventListener('pointerup',()=>drag=null);canvas.addEventListener('pointercancel',()=>drag=null);
canvas.addEventListener('wheel',event=>{if(!bars.length)return;event.preventDefault();const ratio=(event.clientX-canvas.getBoundingClientRect().left)/canvas.clientWidth,anchor=from+ratio*size;size=Math.max(20,Math.min(1000,bars.length,Math.round(size*(event.deltaY>0?1.2:.8))));from=anchor-ratio*size;draw();},{passive:false});
canvas.addEventListener('dblclick',reset);
byId('mcEarlier').onclick=()=>extend(-1).catch(error);byId('mcLater').onclick=()=>extend(1).catch(error);byId('mcReset').onclick=reset;byId('mcOB').onchange=draw;
window.MasterChart={show,clear(){generation++;example=null;bars=[];events=[];contextTable.replaceChildren();}};
})();
