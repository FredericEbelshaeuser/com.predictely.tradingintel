(function(root){
'use strict';
const context=20,horizon=30,tick=.25;
const sign=x=>x>=tick?1:x<=-tick?-1:0;
function continuous(bars){
 return bars.length>context && bars.every((b,i)=>!i||Date.parse(b[0]+'Z')-Date.parse(bars[i-1][0]+'Z')===60000);
}
function trade(bars,start,level,side,intrabar){
 const stop=level-side*10,target=level+side*10;
 for(let i=start;i<bars.length;i++){
  const b=bars[i];let exit=null,reason='';
  if(i>start&&side*(b[1]-stop)<=0){exit=b[1];reason='stop gap';}
  else if(i>start&&side*(b[1]-target)>=0){exit=target;reason='target';}
  if(exit===null){
   const stopped=side===1?b[3]<=stop:b[2]>=stop,hit=side===1?b[2]>=target:b[3]<=target;
   if(stopped){exit=stop;reason=hit?'ambiguous stop first':'stop';}
   else if(hit&&(!intrabar||i!==start||side*(b[4]-target)>=0)){exit=target;reason='target';}
   else if(i===bars.length-1){exit=b[4];reason='window close';}
  }
  if(exit!==null)return {entry:level,entry_et:bars[start][0],stop,target,exit,exit_et:b[0],net_usd:side*(exit-level)*20,reason};
 }
 return null;
}
function analyze(current,prior,mode){
 if(!continuous(current)||!continuous(prior)||prior.length!==51)return null;
 if(prior[50][0]>=current[context][0])return null;
 const now=current[context][4],old=prior[context][4],shift=mode==='relative'?now-old:0;
 const range=b=>Math.max(...b.slice(0,21).map(x=>x[2]))-Math.min(...b.slice(0,21).map(x=>x[3]));
 const priorRange=range(prior),currentRange=range(current);
 const rangeRatio=priorRange?currentRange/priorRange:null;
 const volume=b=>b.slice(0,21).reduce((a,x)=>a+x[5],0);
 const oldVolume=volume(prior);
 const error=current.slice(0,21).reduce((a,b,i)=>a+Math.abs(b[4]-(prior[i][4]+shift)),0)/21;
 const sameTrend=sign(now-current[0][4])===sign(old-prior[0][4]);
 const predicted=prior[50][4]+shift-now,predictedSide=sign(predicted);
 const complete=current.length===51;
 const actual=complete?current[50][4]-now:null;
 const result={mode,source_anchor:prior[20][0],anchor:current[20][0],anchor_price:now,
  context_error:error,anchor_distance:Math.abs(now-old),range_ratio:rangeRatio,
  volume_ratio:oldVolume?volume(current)/oldVolume:null,trend_match:Number(sameTrend),
  predicted_change:predicted,predicted_side:predictedSide,complete,
  path:prior.map((b,i)=>({minute:i-context,price:b[4]+shift})),
  actual_change:actual,agreement:complete?Number(sign(actual)===predictedSide):null,
  path_error:complete?current.slice(21).reduce((a,b,i)=>a+Math.abs(b[4]-(prior[i+21][4]+shift)),0)/30:null,
  follow:complete&&predictedSide?trade(current,21,current[21][1],predictedSide,false):null,
  levels:[]};
 for(const [field,k] of [['open',1],['high',2],['low',3],['close',4]]){
  const price=prior[20][k]+shift;
  const level={field,source_price:prior[20][k],price,buy:null,sell:null,filled:false};
  if(complete){
   for(let i=21;i<51;i++){
    if(current[i-1][4]!==price&&current[i][3]<=price&&current[i][2]>=price){
     level.filled=true;level.buy=trade(current,i,price,1,true);level.sell=trade(current,i,price,-1,true);break;
    }
   }
  }
  result.levels.push(level);
 }
 return result;
}
function qualifies(row,error=3,distanceTicks=1){
 return row.context_error<=error&&row.range_ratio!==null&&row.range_ratio>=.5&&row.range_ratio<=2&&
  !!row.trend_match&&(row.mode!=='absolute'||row.anchor_distance<=distanceTicks*tick);
}
const api={analyze,qualifies,continuous};
if(typeof module!=='undefined')module.exports=api;
root.HistoricalCore=api;
})(typeof window==='undefined'?globalThis:window);
