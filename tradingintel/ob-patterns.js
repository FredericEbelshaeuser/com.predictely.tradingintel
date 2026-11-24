/* Descriptive OHLC geometry; no profitability/strength claims. NQ tick = .25. */
window.LabPatterns=(()=>{
const defaults={breakBasis:'wick',family:'any',volume:'any',size:'any',runMin:1,runMax:100,breakMin:1,breakMax:10000,rangeMin:0,rangeMax:10000,baseWick:'any',openBody:'any',openVsClose:'any',highRelation:'any',lowRelation:'any',bodySize:'any'};
const contiguous=(a,b,tf)=>Date.parse(b[0]+'Z')-Date.parse(a[0]+'Z')===tf*60000;
const metrics=b=>({body:Math.abs(b[4]-b[1]),range:b[2]-b[3],upper:b[2]-Math.max(b[1],b[4]),lower:Math.min(b[1],b[4])-b[3]});
const relation=(a,b)=>a>b+1e-9?'higher':a<b-1e-9?'lower':'equal';
function classify(bars,i,tf=1,config={}){const cfg={...defaults,...config},a=bars[i-1],b=bars[i];if(!a||!b||!contiguous(a,b,tf))return null;
 const side=a[4]<a[1]&&b[4]>b[1]?1:a[4]>a[1]&&b[4]<b[1]?-1:0;if(!side)return null;
 const edge=cfg.breakBasis==='body'?(side===1?Math.max(a[1],a[4]):Math.min(a[1],a[4])):(side===1?a[2]:a[3]);
 const breakTicks=side*(b[4]-edge)/.25;if(breakTicks<1-1e-9)return null;
 const gap=side===1?b[1]<=a[3]-.25:b[1]>=a[2]+.25;
 const sweep=side===1?b[3]<=a[3]-.25:b[2]>=a[2]+.25;
 const family=gap?'gap':sweep?'sweep':'direct';
 const name=family==='gap'?(side===1?'Gap-open reclaim':'Gap-open rejection'):family==='sweep'?(side===1?'Sweep reclaim':'Sweep rejection'):'Direct break';
 let run=0,runV=0;for(let j=i-1;j>=0&&run<100;j--){const r=bars[j];if((side===1?r[4]>=r[1]:r[4]<=r[1])||!contiguous(r,bars[j+1],tf))break;run++;runV+=r[5];}
 const base=metrics(a),confirm=metrics(b),ratio=a[5]>0?b[5]/a[5]:null;
 return {id:(side===1?'B':'S')+'-'+tf+'-'+b[0],side,family,name,engulf:Math.min(b[1],b[4])<=Math.min(a[1],a[4])&&Math.max(b[1],b[4])>=Math.max(a[1],a[4]),stamp:b[0],start:i,lo:a[3],hi:a[2],base,confirm,baseV:a[5],confirmV:b[5],sum:a[5]+b[5],ratio,run,runV,runMean:run?runV/run:null,runRatio:runV>0?b[5]/(runV/run):null,volume:ratio===null?'unknown':relation(b[5],a[5]),size:relation(confirm.range,base.range),bodySize:relation(confirm.body,base.body),breakTicks,openBody:b[1]>Math.max(a[1],a[4])?'above':b[1]<Math.min(a[1],a[4])?'below':'inside',openVsClose:relation(b[1],a[4]),highRelation:relation(b[2],a[2]),lowRelation:relation(b[3],a[3]),breakBasis:cfg.breakBasis,runCapped:run===100};
}
function matches(p,config={}){if(!p)return false;const c={...defaults,...config};return ['openBody','openVsClose','highRelation','lowRelation','bodySize'].every(k=>c[k]==='any'||p[k]===c[k])&&(c.family==='any'||p.family===c.family||c.family===(p.side===1?'buy-':'sell-')+p.family||(c.family==='engulf'&&p.engulf)||(c.family==='inside'&&p.openBody==='inside')||(c.family==='shallow'&&p.breakTicks<=2))&&(c.volume==='any'||p.volume===c.volume)&&(c.size==='any'||p.size===c.size)&&p.run>=+c.runMin&&p.run<=+c.runMax&&p.breakTicks>=+c.breakMin&&p.breakTicks<=+c.breakMax&&p.confirm.range>=+c.rangeMin&&p.confirm.range<=+c.rangeMax&&(c.baseWick==='any'||(c.baseWick==='none'?(p.side===1?p.base.lower:p.base.upper)<=1e-9:(p.side===1?p.base.lower:p.base.upper)>1e-9));}
const fmt=n=>n===null?'n/a':Number(n).toFixed(2);
function describe(p){return `${p.id} · ${p.name} · ${p.breakBasis} break ${fmt(p.breakTicks)} ticks · prior opposite run ${p.run}${p.runCapped?'+':''} · open ${p.openBody} base body / ${p.openVsClose} base close · high ${p.highRelation}, low ${p.lowRelation} · base→confirm range ${fmt(p.base.range)}→${fmt(p.confirm.range)}, body ${fmt(p.base.body)}→${fmt(p.confirm.body)}, upper wick ${fmt(p.base.upper)}→${fmt(p.confirm.upper)}, lower wick ${fmt(p.base.lower)}→${fmt(p.confirm.lower)} pts · volume ${p.baseV}→${p.confirmV} (${p.volume}, ×${fmt(p.ratio)}) · run ΣV ${p.runV}, mean ${fmt(p.runMean)}, confirm/mean ×${fmt(p.runRatio)} · known at confirming candle CLOSE`;}
return {defaults,classify,matches,describe,contiguous,metrics};
})();
