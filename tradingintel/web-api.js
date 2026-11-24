// Resolve the same API interface under /tradingintel, with dataset-scoped notes.
(()=>{const original=window.fetch.bind(window);window.fetch=(path,options={})=>{
 if(typeof path==='string'&&path.startsWith('/api/')){const u=new URL(path,location.origin),action=u.pathname.slice(5);u.searchParams.set('action',action);const dataset=localStorage.getItem('ti-dataset');if(dataset)u.searchParams.set('dataset',dataset);path='api.php?'+u.searchParams;options={...options,headers:{...options.headers,'X-CSRF-Token':window.TI_CSRF}};}
 return original(path,options);
};
document.addEventListener('DOMContentLoaded',async()=>{const nav=document.createElement('section');nav.className='controls';nav.innerHTML='<a href="index.php">Chart / backtest</a> · <a href="compare.php">Compare weeks</a> · <a href="import.php">Import CSV</a> · <a href="master-report.php">Master report</a> · <a href="ai-access.php">AI API</a><label>Dataset<select id="datasetSelect"></select></label><button id="logout">Sign out</button>';document.body.prepend(nav);
 el('logout').onclick=async()=>{await original('logout.php',{method:'POST',headers:{'X-CSRF-Token':window.TI_CSRF}});location.href='login.php';};
 try{const r=await original('api.php?action=datasets'),d=await r.json(),select=el('datasetSelect');for(const ds of d.datasets){const option=document.createElement('option');option.value=ds.id;option.textContent=ds.name+' · '+Number(ds.row_count).toLocaleString()+' rows';select.append(option);}const saved=localStorage.getItem('ti-dataset');if(saved&&d.datasets.some(x=>String(x.id)===saved))select.value=saved;else if(saved){localStorage.removeItem('ti-dataset');location.reload();return;}select.onchange=()=>{localStorage.setItem('ti-dataset',select.value);location.reload();};}catch(e){nav.append(document.createTextNode('Unable to load datasets. Sign in again.'));}
 function el(id){return document.getElementById(id);}
});})();
