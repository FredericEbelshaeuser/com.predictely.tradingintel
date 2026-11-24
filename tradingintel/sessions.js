// Session conventions: Tokyo 09:00, London 08:00, New York 00:00 and 09:30.
// Convert named local times through IANA zones; never assume a fixed ET offset.
window.LabSessions=(()=>{
 const defs=[['Tokyo open','Asia/Tokyo',9,0,'#e8b86c'],['NY midnight','America/New_York',0,0,'#b2a0f8'],['London open','Europe/London',8,0,'#50bfe4'],['NY open','America/New_York',9,30,'#ed7f9a']];
 const fmts={};
 function parts(ms,zone){fmts[zone]??=new Intl.DateTimeFormat('en-CA',{timeZone:zone,year:'numeric',month:'2-digit',day:'2-digit',hour:'2-digit',minute:'2-digit',hourCycle:'h23'});return Object.fromEntries(fmts[zone].formatToParts(ms).filter(p=>p.type!=='literal').map(p=>[p.type,+p.value]));}
 function localUTC(date,h,m,zone){let target=Date.parse(date+'T00:00:00Z')+(h*60+m)*60000,x=target;for(let i=0;i<4;i++){let p=parts(x,zone),seen=Date.UTC(p.year,p.month-1,p.day,p.hour,p.minute);x+=target-seen;}return x;}
 function dayString(ms){return new Date(ms).toISOString().slice(0,10);}
 const cache=new Map();
 function forDay(date){if(cache.has(date))return cache.get(date);let result=[];for(let [name,zone,h,m,color] of defs){for(let delta=-1;delta<=1;delta++){let local=dayString(Date.parse(date+'T00:00:00Z')+delta*86400000),utc=localUTC(local,h,m,zone),p=parts(utc,'America/New_York'),et=`${p.year}-${String(p.month).padStart(2,'0')}-${String(p.day).padStart(2,'0')}`;if(et===date)result.push({name,color,minute:p.hour*60+p.minute,time:`${et}T${String(p.hour).padStart(2,'0')}:${String(p.minute).padStart(2,'0')}`});}}cache.set(date,result);return result;}
 return {forDay};
})();
