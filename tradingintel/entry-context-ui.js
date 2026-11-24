(()=>{
const host=document.createElement('section');
host.innerHTML='<h3>Directional entry research</h3><button id="entryCompare">Compare BUY, SELL and OB-aligned entries</button><p id="entryContextStatus">Compare this entry rule with the confirmed orderblocks available strictly before each fill.</p><div class="scroll"><table id="entryPolicies"></table></div><details><summary>Results by the last two pre-entry orderblocks</summary><div class="scroll"><table id="entryGroups"></table></div></details>';
document.getElementById('stats').parentElement.after(host);
window.addEventListener('master-inspect',()=>{
 document.getElementById('entryPolicies').replaceChildren();
 document.getElementById('entryGroups').replaceChildren();
 document.getElementById('entryContextStatus').textContent='Compare this entry rule with the confirmed orderblocks available strictly before each fill.';
});
document.getElementById('entryCompare').onclick=async()=>{
 if(!selected)return;const version=generation;
 document.getElementById('entryContextStatus').textContent='Comparing entry direction policies…';
 try{
  const data=await api('entry_context_report',cohort(selected));if(version!==generation)return;
  document.getElementById('entryContextStatus').textContent=data.method;
  const table=document.getElementById('entryPolicies');
  head(table,['Entry policy','Trades','Skipped','Win rate','Net USD','Average / trade']);
  data.policies.forEach(p=>{const r=table.insertRow();[p.policy,p.trades,p.skipped,p.trades?(100*p.wins/p.trades).toFixed(1)+'%':'—',money(p.net_usd),p.trades?money(p.net_usd/p.trades):'—'].forEach(v=>cell(r,v));});
  const groups=document.getElementById('entryGroups');
  head(groups,['Earlier → latest OB','Paired fills','BUY win','BUY net','SELL win','SELL net']);
  data.groups.forEach(p=>{const r=groups.insertRow();[p.context,p.n,(100*p.buy_wins/p.n).toFixed(1)+'%',money(p.buy_net),(100*p.sell_wins/p.n).toFixed(1)+'%',money(p.sell_net)].forEach(v=>cell(r,v));});
 }catch(e){document.getElementById('entryContextStatus').textContent=e.message;}
};
})();
