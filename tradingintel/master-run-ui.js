(()=>{
let pending=localStorage.getItem('ti-master-run')||'',running=false;
window.updateRunAssumptions=metadata=>{
 const s=metadata.settings;
 document.getElementById('runAssumptions').textContent=s.length+' consecutive swings · '+metadata.rule_count+' entry rules · BUY and SELL · '+s.stop+'-point SL / '+s.tp+'-point TP · '+s.horizon+'-bar follow-up · $'+s.point+' / point · '+s.qty+' contract(s) · $'+s.fee+' round-trip / contract';
 for(const [id,key]of [['runLength','length'],['runStop','stop'],['runTP','tp'],['runHorizon','horizon'],['runPoint','point'],['runQty','qty'],['runFee','fee']])document.getElementById(id).value=s[key];
 document.getElementById('runFamily').value=s.family||'all';
};
if(typeof meta!=='undefined'&&meta)window.updateRunAssumptions(meta);
async function request(action,extra={}){
 const response=await fetch('master-run.php',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':window.TI_CSRF},body:JSON.stringify({action,run:pending,...extra})});
 const body=await response.text();let data;
 try{data=JSON.parse(body);}catch(e){throw Error('Server returned HTTP '+response.status+' without a JSON result while '+action+'. Check for a hosting timeout or PHP error.');}
 if(!response.ok||data.error)throw Error(data.error||'Run request failed (HTTP '+response.status+')');return data;
}
function status(text){document.getElementById('runProgress').textContent=text;}
async function continueRun(){
 running=true;document.getElementById('runCancel').disabled=false;
 try{
  while(running){
   const data=await request('step');
   status(data.processed.toLocaleString()+' / '+data.total.toLocaleString()+' patterns · '+data.status+' · run '+pending);
   if(data.status==='complete'){running=false;location.href='master-report.php?run='+pending;return;}
   if(data.status==='cancelled'){running=false;break;}
   await new Promise(resolve=>setTimeout(resolve,50));
  }
 }catch(e){running=false;status(e.message+' Resume saved run to retry the next batch.');}
 document.getElementById('runCancel').disabled=true;
}
document.getElementById('customRunForm').onsubmit=async event=>{
 event.preventDefault();if(running)return;
 if(!document.getElementById('filters').reportValidity())return;
 running=true;
 try{
  const q={start:document.getElementById('start').value,end:document.getElementById('end').value,family:document.getElementById('runFamily').value};
  for(const [id,key]of [['runLength','length'],['runStop','stop'],['runTP','tp'],['runHorizon','horizon'],['runPoint','point'],['runQty','qty'],['runFee','fee']])q[key]=Number(document.getElementById(id).value);
  status('Creating saved backtest…');const data=await request('start',q);pending=data.run;localStorage.setItem('ti-master-run',pending);await continueRun();
 }catch(e){running=false;status(e.message);}
};
document.getElementById('runCancel').onclick=async()=>{running=false;try{await request('cancel');status('Run paused. Use Resume saved run to continue.');}catch(e){status(e.message);}};
document.getElementById('runResume').onclick=async()=>{if(running)return;if(!pending){status('No saved run in this browser.');return;}try{await request('resume');await continueRun();}catch(e){status(e.message);}};
document.getElementById('runPreset').onclick=()=>location.href='master-report.php';
})();
