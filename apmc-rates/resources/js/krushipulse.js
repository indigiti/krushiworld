/* KrushiPulse dashboard: verified API data; guest preferences persisted in localStorage. */
(()=>{
'use strict';
const $=id=>document.getElementById(id), lang=['en','mr','hi'].includes(document.documentElement.lang)?document.documentElement.lang:'mr', MR=lang==='mr',HI=lang==='hi';
const dict=window.APMC_I18N||{};
const t=(mr,en)=>HI?(dict.ui_hi?.[en]||en):(MR?mr:en);
const nf=new Intl.NumberFormat('en-IN',{maximumFractionDigits:0});
const storageKey='krushipulse.preferences.v1',themeKey='krushipulse.theme.v1';
const pageRoute=window.APMC_ROUTE||{};
const mandiLink=key=>String(window.APMC_APP_BASE||'')+'/'+lang+'/mandis/'+encodeURIComponent(key)+'/';
const themes=['harvest','midnight','saffron'];
let prefs={market:'all',crops:[]},state={data:window.APMC_BOOT||{},market:'all',category:'all',search:'',onlySaved:false,pageSize:35,busy:false,attempts:0,modalOpen:false,marketDirectory:[],nationalRecords:[],stateFilter:'all',districtFilter:'all',marketRequest:0};
const E=(tag,className,text)=>{const el=document.createElement(tag);if(className)el.className=className;if(text!==undefined)el.textContent=text;return el;};
const safeRead=(key)=>{try{return window.localStorage.getItem(key)}catch{return null}};
const safeSave=(key,value)=>{try{window.localStorage.setItem(key,value);return true}catch{return false}};
function initPrefs(){try{const raw=JSON.parse(safeRead(storageKey)||'null');if(raw&&typeof raw==='object'){prefs.market=typeof raw.market==='string'?raw.market:'all';prefs.crops=Array.isArray(raw.crops)?raw.crops.filter(x=>typeof x==='string'&&x.length<200).slice(0,100):[];}}catch(_){}}
function persist(){safeSave(storageKey,JSON.stringify(prefs));}
function labels(group,key){let v=dict[group]?.[key];return v?.[lang]||v?.en||key||'';}
function commodity(r){if(HI)return r?.name_hi||dict.commodities?.[r?.name_en]?.hi||dict.commodities?.[r?.name_mr]?.hi||r?.name_en||r?.name_mr||'';return (MR?r.name_mr:r.name_en)||(MR?r.name_en:r.name_mr)||'';}
function marketName(r){const key=r?.name_en||r?.market_name_en||r?.name||r?.market_name||'';return r?.['name_'+lang]||r?.['market_name_'+lang]||labels('markets',key)||t('मंडी','Market');}
function id(r){return String(r.commodity_key||r.name_en||r.name_mr||'').trim();}
function amt(n){return n===undefined||n===null||n===''||!Number.isFinite(Number(n))?'—':'₹'+nf.format(Number(n));}
function unit(n){return labels('units',String(n||''))||t('एकक','unit');}
function regionName(group,name){return HI?(dict[group]?.[name]||name):name;}
function trend(n){if(n===null||n===undefined||!Number.isFinite(Number(n)))return {s:'—',cls:'flat'};let x=Number(n);return {s:(x>0?'▲ ':x<0?'▼ ':'')+Math.abs(x).toFixed(1)+'%',cls:x>0?'up':x<0?'down':'flat'};}
function iconFor(r){return typeof window.KrushiPulseCommodityIcon==='function'?window.KrushiPulseCommodityIcon(r):'🌱';}
function records(){const pune=Array.isArray(state.data.records)?state.data.records:[];return state.nationalRecords.length?[...pune,...state.nationalRecords]:pune;}
function marketRows(){const known=new Set(),list=[];for(const m of (state.marketDirectory.length?state.marketDirectory:(Array.isArray(state.data.markets)?state.data.markets:[]))){const key=String(m.market_id||m.slug||m.name||'');if(key&&!known.has(key)){known.add(key);list.push({...m,key});}}return list;}
function categoryRows(){return (Array.isArray(state.data.categories)?state.data.categories:[]).map(c=>typeof c==='string'?c:(c.name||c.slug||'')).filter(Boolean);}
function matchesMarket(r,m){return m==='all'||[r.market_id,r.market_name,r.market_slug,r.feed_slug].includes(m);}
function matching(){const q=state.search.trim().toLocaleLowerCase();return records().filter(r=>matchesMarket(r,state.market)&&(state.market!=='all'||((state.stateFilter==='all'||(r.state||'Maharashtra')===state.stateFilter)&&(state.districtFilter==='all'||(r.district||'Pune')===state.districtFilter)))&&(state.category==='all'||r.category===state.category)&&(!state.onlySaved||prefs.crops.includes(id(r)))&&(!q||[r.name_en,r.name_mr,r.name_hi,commodity(r),r.market_name,r.market_name_mr,r.market_name_hi,marketName(r),r.category,r.category_mr,labels('categories',r.category||'')].filter(Boolean).join(' ').toLocaleLowerCase().includes(q))).sort((a,b)=>String(b.date||'').localeCompare(String(a.date||''))||Number(b.arrival||0)-Number(a.arrival||0));}
function setText(id,val){const el=$(id);if(el)el.textContent=val??'';}
function bind(name,value){document.querySelectorAll('[data-bind="'+name+'"]').forEach(el=>el.textContent=value??'');}
function updateStatus(){const m=state.data.meta||{},exists=Number(m.records||records().length);bind('record-count',nf.format(exists));bind('market-count',String(marketRows().length));const d=/^\d{4}-\d\d-\d\d$/.test(m.serving_date||'')?new Intl.DateTimeFormat(MR?'mr-IN-u-nu-latn':HI?'hi-IN-u-nu-latn':'en-IN',{day:'numeric',month:'short',year:'numeric'}).format(new Date(m.serving_date+'T12:00:00')):t('अहवाल नाही','Not available');bind('report-date',d);bind('freshness',!exists?t('स्रोत प्रतीक्षेत','Awaiting source'):Number(m.markets_error||0)>0?t('काही स्रोत अनुपलब्ध','Some feeds unavailable'):Number(m.markets_stale||0)>0?t('काही अहवाल जुने','Some reports older'):t('प्रकाशित दर उपलब्ध','Published rates available'));setText('kpReportHint',t('अहवालाची मूळ तारीख दाखवलेली आहे','Each row shows its source date'));}
function setSelectOptions(select,items,allLabel,fmt){select.replaceChildren();const all=E('option','',allLabel);all.value='all';select.append(all);for(const m of items){const item=E('option','',fmt(m));item.value=typeof m==='string'?m:m.key;select.append(item);}}
function renderSelectors(){
  const markets=marketRows(),cats=categoryRows();
  const states=[...new Set(markets.map(m=>String(m.state||'Maharashtra')).filter(Boolean))].sort();
  setSelectOptions($('kpState'),states,t('सर्व राज्ये','All states'),m=>regionName('states_hi',m));
  $('kpState').value=states.includes(state.stateFilter)?state.stateFilter:'all';
  const byState=markets.filter(m=>state.stateFilter==='all'||(m.state||'Maharashtra')===state.stateFilter);
  const districts=[...new Set(byState.map(m=>String(m.district||'Pune')).filter(Boolean))].sort();
  if(state.districtFilter!=='all'&&!districts.includes(state.districtFilter))state.districtFilter='all';
  setSelectOptions($('kpDistrict'),districts,t('सर्व जिल्हे','All districts'),m=>regionName('districts_hi',m));
  $('kpDistrict').value=state.districtFilter;
  const scoped=byState.filter(m=>state.districtFilter==='all'||(m.district||'Pune')===state.districtFilter);
  setSelectOptions($('kpMarket'),scoped,t('सर्व मंड्या','All mandis'),m=>marketName(m)+(Number(m.records||0)>0?'':' · '+t('नवे दर नाहीत','No current report')));
  setSelectOptions($('kpPrefMarket'),markets,t('सर्व मंड्या','All mandis'),m=>marketName(m)+' · '+(m.district||'Pune')+', '+(m.state||'Maharashtra'));
  setSelectOptions($('kpCategory'),cats,t('सर्व श्रेणी','All categories'),m=>labels('categories',m));
  if(state.market!=='all'&&!scoped.some(m=>m.key===state.market))state.market='all';
  $('kpMarket').value=state.market;
  $('kpPrefMarket').value=markets.some(m=>m.key===prefs.market)?prefs.market:'all';
  if(!cats.includes(state.category))state.category='all';
  $('kpCategory').value=state.category;
}
function setMarketScope(id){
 const m=marketRows().find(item=>item.key===id);
 if(!m)return;
 state.stateFilter=m.state||'Maharashtra';state.districtFilter=m.district||'Pune';
}
function handleNational(){
 const request=++state.marketRequest;
 state.nationalRecords=[];
 const id=state.market;
 if(!id.startsWith('agmarknet-')){renderData();return;}
 setText('kpDirectoryStatus',t('निवडलेल्या मंडीचे दर लोड होत आहेत…','Loading the selected mandi reports…'));
 const url=window.APMC_API?.replace('api=latest','api=national')+'&market='+encodeURIComponent(id);
 if(!url){renderData();return;}
 fetch(url,{cache:'no-store',headers:{Accept:'application/json'}}).then(r=>{if(!r.ok)throw Error('HTTP');return r.json();})
 .then(json=>{
   if(request!==state.marketRequest||id!==state.market)return;
   state.nationalRecords=Array.isArray(json.data?.records)?json.data.records:[];
   const n=state.nationalRecords.length;
   setText('kpDirectoryStatus',n?t('AGMARKNET प्रकाशित दर: ','AGMARKNET published records: ')+n:t('या मंडीसाठी सध्याचे दर नोंदवलेले नाहीत.','No current published rates for this mandi.'));
   renderData();
 }).catch(()=>{
   if(request!==state.marketRequest)return;
   setText('kpDirectoryStatus',t('राष्ट्रीय बाजारभाव उपलब्ध नाहीत; शेवटची नोंद तपासा.','National feed unavailable; check the last published report.'));
   renderData();
 });
}
async function loadMarketDirectory(){
 if(!window.APMC_API)return;
 try{
  const url=window.APMC_API.replace('api=latest','api=markets');
  const response=await fetch(url,{cache:'no-store',headers:{Accept:'application/json'}});
  if(!response.ok)throw Error('market directory unavailable');
  const payload=await response.json(),rows=payload?.data?.markets;
  if(!payload?.ok||!Array.isArray(rows))throw Error('invalid directory');
  state.marketDirectory=rows;
  const requested=marketRows().find(m=>m.key===prefs.market);
  if(requested){state.market=prefs.market;setMarketScope(prefs.market);}
  renderSelectors();renderData();
  const national=payload.data.national||{};
  setText('kpDirectoryStatus',national.status==='ready'
   ?t('भारतातील उपलब्ध नोंदवलेल्या मंड्या: ','Known reporting mandis in India: ')+national.market_count+' · '+t('अहवाल तारीख पडताळा','Check report dates')
   :national.status==='not_configured'
     ?t('सध्या पुणे APMC उपलब्ध आहे. देशभरातील दरांसाठी सर्व्हर API की आवश्यक.','Pune APMC available. Nationwide rates require a server-side AGMARKNET API key.')
     :t('राष्ट्रीय मंडी दरांचे संकलन सुरू/प्रतीक्षेत आहे.','National mandi data import is awaiting its first successful refresh.'));
  if(state.market.startsWith('agmarknet-'))handleNational();
 }catch(_){
  setText('kpDirectoryStatus',t('मंडी यादी सध्या उपलब्ध नाही; स्थानिक दर वापरता येतील.','Market directory unavailable; existing Pune rates are still accessible.'));
 }
}

function bestForCrop(key){const list=records().filter(r=>id(r)===key&&matchesMarket(r,prefs.market)).sort((a,b)=>String(b.date||'').localeCompare(String(a.date||''))||Number(b.arrival||0)-Number(a.arrival||0));return list[0]||null;}
function refreshPersonal(){const available=marketRows(),selected=available.find(m=>m.key===prefs.market);setText('kpSelectedMarket',prefs.market==='all'?t('सर्व मंड्या','All mandis'):selected?marketName(selected):t('मंडी उपलब्ध नाही','Market unavailable'));const permalink=$('kpMarketPermalink');if(permalink){permalink.hidden=!selected;permalink.href=selected?mandiLink(selected.key):'#';}setText('kpSavedCount',prefs.crops.length+' '+t('पिके','crops'));const list=$('kpWatchlist');list.replaceChildren();if(!prefs.crops.length){list.append(E('div','kp-notice',t('तुमच्या पिकांना खाली ★ करा किंवा “पसंती बदला” वापरा.','Star a crop in the rate table, or open “Edit preferences” to choose your crops.')));return;}
  for(const key of prefs.crops.slice(0,12)){const r=bestForCrop(key),row=E('div','kp-watch-row');const symbol=E('span','kp-watch-row-icon',r?iconFor(r):'🌱');symbol.setAttribute('aria-hidden','true');row.append(symbol);const info=E('div','kp-watch-info');info.append(E('b','',r?commodity(r):key),E('small','',r?marketName(r):t('सध्याच्या डेटात नोंद नाही','Not in current dataset')));const val=E('div','kp-watch-price',r?amt(r.average):'—');val.append(E('small','',r?(unit(r.unit)+' · '+(r.date||t('तारीख नाही','No date'))):t('दर उपलब्ध नाही','Price unavailable')));row.append(info,val);list.append(row);}
  if(prefs.crops.length>12)list.append(E('div','kp-notice',t('आणखी पिके तुमच्या पसंतीमध्ये जतन आहेत.','More crops are saved in your preferences.')));
}
function favButton(r){const key=id(r),saved=prefs.crops.includes(key),btn=E('button','kp-fav-button'+(saved?' saved':''),saved?'★':'☆');btn.type='button';btn.setAttribute('aria-label',saved?t('आवडते पीक काढा','Remove favorite crop'):t('पीक आवडते करा','Save favorite crop'));btn.setAttribute('aria-pressed',String(saved));btn.addEventListener('click',()=>{if(!key)return;prefs.crops=saved?prefs.crops.filter(k=>k!==key):[...prefs.crops,key].slice(0,100);persist();renderTable();refreshPersonal();});return btn;}
function renderTable(){const list=matching(),body=$('kpTableRows');body.replaceChildren();for(const r of list.slice(0,state.pageSize)){const tr=E('tr'),star=E('td');star.append(favButton(r));tr.append(star);const crop=E('td'),name=E('div','kp-cell-crop');const details=E('div');details.append(E('b','',commodity(r)),E('small','',labels('categories',r.category||'')));const symbol=E('span','kp-crop-emoji',iconFor(r));symbol.setAttribute('aria-hidden','true');name.append(symbol,details);crop.append(name);const marketCell=E('td');const marketTitle=marketName(r);const linkId=String(r.market_id||'');if(/^[a-z0-9-]{1,110}$/.test(linkId)){const a=E('a','kp-rate-market-link',marketTitle);a.href=mandiLink(linkId);marketCell.append(a);}else marketCell.textContent=marketTitle;tr.append(crop,marketCell);const p=E('td'),n=E('span','kp-price-value',amt(r.average));n.append(E('small','',unit(r.unit)));p.append(n);tr.append(p,E('td','',amt(r.min)),E('td','',amt(r.max)));const change=E('td'),delta=trend(r.change_pct);change.append(E('span','kp-trend '+delta.cls,delta.s));tr.append(change,E('td','',r.date||'—'));body.append(tr);}
$('kpTableEmpty').hidden=list.length>0;$('kpMore').hidden=list.length<=state.pageSize;setText('kpShowing',t('दाखवत आहे ','Showing ')+Math.min(state.pageSize,list.length)+' / '+list.length);setText('kpFilteredCount',list.length+' '+t('नोंदी','records'));}
function renderData(){updateStatus();refreshPersonal();renderTable();}
function cropChoices(){const root=$('kpCropChoices');root.replaceChildren();const map=new Map();for(const r of records()){const key=id(r);if(key&&!map.has(key))map.set(key,r);}for(const key of prefs.crops)if(!map.has(key))map.set(key,{commodity_key:key,name_en:key});const list=Array.from(map.values()).sort((a,b)=>Number(prefs.crops.includes(id(b)))-Number(prefs.crops.includes(id(a)))||commodity(a).localeCompare(commodity(b),MR?'mr':'en')).slice(0,160);if(!list.length)root.append(E('div','kp-notice',t('स्रोत डेटा उपलब्ध झाल्यानंतर येथे पिके दिसतील.','Crop choices will appear after source data is available.')));for(const r of list){const label=E('label','kp-crop-choice'),check=E('input');check.type='checkbox';check.value=id(r);check.checked=prefs.crops.includes(id(r));const symbol=E('span','kp-choice-emoji',iconFor(r));symbol.setAttribute('aria-hidden','true');label.append(check,symbol,E('span','',commodity(r)||id(r)));root.append(label);}}
function openDrawer(){if(state.modalOpen)return;state.modalOpen=true;renderSelectors();cropChoices();$('kpOverlay').hidden=false;$('kpDrawer').inert=false;$('kpDrawer').classList.add('open');$('kpDrawer').setAttribute('aria-hidden','false');document.body.style.overflow='hidden';$('kpCloseDrawer').focus();}
function closeDrawer(){if(!state.modalOpen)return;state.modalOpen=false;$('kpOverlay').hidden=true;$('kpDrawer').classList.remove('open');$('kpDrawer').setAttribute('aria-hidden','true');$('kpDrawer').inert=true;document.body.style.overflow='';$('kpPreferencesOpen').focus();}
function saveDrawer(){prefs.market=$('kpPrefMarket').value;prefs.crops=Array.from($('kpCropChoices').querySelectorAll('input:checked')).map(el=>el.value).filter(Boolean).slice(0,100);persist();state.market=prefs.market;state.onlySaved=prefs.crops.length>0;state.category='all';state.search='';state.pageSize=35;$('kpSearch').value='';$('kpOnlySaved').checked=state.onlySaved;setMarketScope(state.market);renderSelectors();handleNational();closeDrawer();document.getElementById('my-rates').scrollIntoView({block:'start',behavior:'smooth'});}
function resetDrawer(){prefs={market:'all',crops:[]};persist();state.market='all';state.onlySaved=false;state.category='all';state.search='';$('kpOnlySaved').checked=false;$('kpSearch').value='';renderSelectors();handleNational();cropChoices();}
function themeChange(){const current=document.documentElement.dataset.theme||'harvest',next=themes[(themes.indexOf(current)+1)%themes.length];document.documentElement.dataset.theme=next;safeSave(themeKey,next);renderThemeLabel();}
function renderThemeLabel(){const v=document.documentElement.dataset.theme||'harvest';const names={harvest:'Harvest',midnight:'Midnight',saffron:'Saffron'};setText('kpThemeLabel',names[v]||'Harvest');$('kpTheme').title=t('रंगसंगती बदला · ','Switch theme · ')+(names[v]||'Harvest');$('kpTheme').setAttribute('aria-label',$('kpTheme').title);}
async function refreshLatest(){if(!window.APMC_API)return;try{const response=await fetch(window.APMC_API,{cache:'no-store',headers:{Accept:'application/json'}});if(!response.ok)throw Error('API failed');const json=await response.json();if(json?.ok&&json.data){state.data=json.data;renderSelectors();renderData();if(json.fallback_due)recover();}}catch(e){console.warn('KrushiPulse: source unavailable; only stored verified records shown.');}}
async function recover(){if(state.busy||state.attempts>=24||!window.APMC_BOOTSTRAP_API)return;state.busy=true;state.attempts++;try{const response=await fetch(window.APMC_BOOTSTRAP_API,{method:'POST',credentials:'same-origin',cache:'no-store',headers:{Accept:'application/json','X-Requested-With':'XMLHttpRequest'}});if(response.ok){const json=await response.json();if(json?.ok&&json.data){state.data=json.data;renderSelectors();renderData();}}}catch(_){}finally{state.busy=false;}}
function init(){initPrefs();if(pageRoute.kind==='mandi'&&typeof pageRoute.market==='string'){prefs.market=pageRoute.market;persist();}state.market=prefs.market;state.onlySaved=prefs.crops.length>0;$('kpOnlySaved').checked=state.onlySaved;renderThemeLabel();const toggleButtons=['kpPreferencesOpen','kpHeroSetup','kpEditPrefs','kpChangeMarket','kpMobilePrefs'];for(const id of toggleButtons)$(id).addEventListener('click',openDrawer);
$('kpTheme').addEventListener('click',themeChange);$('kpCloseDrawer').addEventListener('click',closeDrawer);$('kpOverlay').addEventListener('click',closeDrawer);$('kpDrawer').addEventListener('keydown',e=>{if(e.key==='Escape')closeDrawer();if(e.key==='Tab'){const focusables=Array.from($('kpDrawer').querySelectorAll('button,input,select')).filter(el=>!el.disabled);const idx=focusables.indexOf(document.activeElement);if(e.shiftKey&&idx===0){e.preventDefault();focusables.at(-1).focus();}else if(!e.shiftKey&&idx===focusables.length-1){e.preventDefault();focusables[0].focus();}}});
$('kpSavePrefs').addEventListener('click',saveDrawer);$('kpClearPrefs').addEventListener('click',resetDrawer);
$('kpSearch').addEventListener('input',e=>{state.search=e.target.value;state.pageSize=35;renderTable();});$('kpMarket').addEventListener('change',e=>{state.market=e.target.value;prefs.market=state.market;persist();state.pageSize=35;state.category='all';renderSelectors();handleNational();});$('kpState').addEventListener('change',e=>{state.stateFilter=e.target.value;state.districtFilter='all';state.market='all';state.category='all';++state.marketRequest;state.nationalRecords=[];renderSelectors();renderData();});
$('kpDistrict').addEventListener('change',e=>{state.districtFilter=e.target.value;state.market='all';state.category='all';++state.marketRequest;state.nationalRecords=[];renderSelectors();renderData();});
$('kpCategory').addEventListener('change',e=>{state.category=e.target.value;state.pageSize=35;renderTable();});$('kpOnlySaved').addEventListener('change',e=>{state.onlySaved=e.target.checked;state.pageSize=35;renderTable();});$('kpMore').addEventListener('click',()=>{state.pageSize+=35;renderTable();});
renderSelectors();renderData();loadMarketDirectory();try{document.cookie='apmc_language='+lang+'; path=/; max-age=31536000; samesite=lax';}catch(_){ }
if(window.APMC_FALLBACK_DUE)setTimeout(recover,350);setTimeout(refreshLatest,1700);setInterval(refreshLatest,60000);
}
if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',init,{once:true});else init();
})();
