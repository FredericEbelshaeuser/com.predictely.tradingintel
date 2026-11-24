(function(root){
'use strict';
const defaults={target:10,life:120,hold:30,qty:1,point:20,cost:5,wickRatio:0,both:true};
function geometry(a,b,side,ratio=0){
 if(side===-1)return a.c>a.o&&b.c<b.o&&b.o>a.c&&b.h<a.h&&b.c<=a.l-.25&&(a.h-a.c)>0&&(a.h-a.c)>=ratio*(a.c-a.o);
 return a.c<a.o&&b.c>b.o&&b.o<a.c&&b.l>a.l&&b.c>=a.h+.25&&(a.c-a.l)>0&&(a.c-a.l)>=ratio*(a.o-a.c);
}
class Engine{
 constructor(settings={}){this.s={...defaults,...settings};this.buf=[];this.lastH=null;this.lastL=null;this.i=-1;this.setup=null;this.trade=null;this.trades=[];this.detected=0;this.accepted=0;this.early=0;this.busy=0;this.missedSecond=0;this.last=null;}
 feed(b){
  this.i++;const i=this.i,s=this.s,gap=this.last!==null&&Date.parse(b.ts+'Z')-Date.parse(this.last+'Z')!==60000;
  // Resting orders can fill on the gap open before a close-time cancellation.
  let z=this.setup;
  if(z&&z.pending&&!this.trade){
   const entry=z.stage===1?z.open:z.extreme;
   if(z.side===1?b.l<=entry:b.h>=entry){
    const fill=z.side===1?Math.min(b.o,entry):Math.max(b.o,entry);
    this.trade={setup:z,stage:z.stage,planned:entry,entry:fill,entryTime:b.ts,born:i,stop:z.stop,tp:entry+z.side*s.target};
    z.pending=false;
   }
  }
  if(this.trade){
   const t=this.trade,side=t.setup.side;
   let exit=null,reason='';
   if(i>t.born&&side*(b.o-t.stop)<=0){exit=b.o;reason='stop gap';}
   else if(i===t.born&&side*(t.entry-t.stop)<=0){exit=t.entry;reason='entry beyond stop';}
   else if(i>t.born&&side*(b.o-t.tp)>=0){exit=t.tp;reason='target';}
   else{
    const sl=side===1?b.l<=t.stop:b.h>=t.stop,tp=side===1?b.h>=t.tp:b.l<=t.tp;
    if(sl){exit=t.stop;reason=tp?'ambiguous stop first':'stop';}
    else if(tp&&(i>t.born||side*(b.c-t.tp)>=0)){exit=t.tp;reason='target';}
    else if(i-t.born>=s.hold-1){exit=b.c;reason='time close';}
   }
   if(exit!==null){
    this.trades.push({side:side===1?'BUY':'SELL',stage:t.stage,entry:t.entry,planned:t.planned,entryTime:t.entryTime,exit,exitTime:b.ts,reason,stop:t.stop,tp:t.tp,points:side*(exit-t.entry),net:(side*(exit-t.entry)*s.point-s.cost)*s.qty,a:t.setup.a,b:t.setup.b,confirmed:t.setup.confirmed});
    this.trade=null;
    if(t.stage===2)z.retired=true;
   }
  }
  if(z){
   const broken=z.side===1?b.l<z.low:b.h>z.high;
   if(gap||broken||i>=z.expires) {z.pending=false;z.retired=true;}
   if(!z.retired){
    const away=z.side===-1?b.h<z.open:b.l>z.open;
    const touchesBand=z.side===-1?b.h>=z.open:b.l<=z.open;
    const firstFilled=z.stage===1&&!z.pending;
    if(firstFilled){
     if(z.away&&touchesBand){z.retired=true;this.missedSecond++;}
     else if(away){z.away=true;if(!this.trade){z.stage=2;z.pending=true;z.secondVisit=false;}}
    }else if(z.stage===2&&z.pending){
     if(touchesBand)z.secondVisit=true;
     if(z.secondVisit&&away){z.pending=false;z.retired=true;this.missedSecond++;}
    }
   }
   if(z.retired&&!this.trade)this.setup=null;
  }
  if(gap){this.buf=[];this.lastH=null;this.lastL=null;}
  this.last=b.ts;this.buf.push(b);if(this.buf.length>11)this.buf.shift();
  if(this.buf.length!==11)return;
  const a=this.buf[5],c=this.buf[6],top=this.buf.every((x,k)=>k===5||a.h>x.h),bottom=this.buf.every((x,k)=>k===5||a.l<x.l);
  for(const side of [-1,1]){
   const pivot=side===-1?top:bottom;if(!pivot)continue;
   const prior=side===-1?this.lastH:this.lastL,relative=prior!==null&&(side===-1?a.h>prior:a.l<prior);
   if(side===-1)this.lastH=a.h;else this.lastL=a.l;
   if(!relative||!geometry(a,c,side,s.wickRatio))continue;
   this.detected++;
   // A's immediately following candle B is explicitly the reference candle.
   const early=this.buf.slice(7).some(x=>side===-1?x.h>=c.o:x.l<=c.o);
   if(early){this.early++;continue;}
   if(this.setup||this.trade){this.busy++;continue;}
   this.accepted++;
   this.setup={side,a:{...a},b:{...c},open:c.o,extreme:side===-1?c.h:c.l,low:Math.min(a.l,c.l),high:Math.max(a.h,c.h),stop:side===-1?Math.max(a.h,c.h)+.25:Math.min(a.l,c.l)-.25,confirmed:b.ts,expires:i+s.life,stage:1,pending:true,retired:false,away:false,secondVisit:false};
  }
 }
 result(){
  let equity=0,peak=0,dd=0;for(const t of this.trades){equity+=t.net;peak=Math.max(peak,equity);dd=Math.max(dd,peak-equity);}
  return {settings:this.s,detected:this.detected,accepted:this.accepted,early:this.early,busy:this.busy,missedSecond:this.missedSecond,closed:this.trades.length,wins:this.trades.filter(t=>t.net>0).length,net:equity,drawdown:dd,open:this.trade?{entry:this.trade.entry,entryTime:this.trade.entryTime,stop:this.trade.stop,tp:this.trade.tp}:null,pending:!!this.setup?.pending,trades:this.trades};
 }
}
const api={Engine,geometry,defaults};if(typeof module!=='undefined'&&module.exports)module.exports=api;else root.WickRetest=api;
})(globalThis);
