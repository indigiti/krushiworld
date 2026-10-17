/* KrushiPulse: source-backed chart, comparisons, net value and browser-local alerts. */
(()=>{'use strict';
const $=id=>document.getElementById(id),base=String(window.APMC_APP_BASE||''),lang=document.documentElement.lang,MR=lang==='mr',HI=lang==='hi',dict=window.APMC_I18N||{};
const t=(mr,en)=>HI?(dict.ui_hi?.[en]||en):(MR?mr:en),store='krushipulse.price-alerts.v1';
const money=x=>Number.isFinite(Number(x))?'₹'+Number(x).toLocaleString('en-IN',{maximumFractionDigits:2}):'—';
const el=(tag,cl='',value)=>{const n=document.createElement(tag);n.className=cl;if(value!==undefined)n.textContent=String(value);return n};
let selected=null,days=30,requestId=0;
function crop(r){return String((HI?r.name_hi||dict.commodities?.[r.name_en]?.hi:MR?r.name_mr:r.name_en)||r.name_en||r.commodity||'')}
function cropKey(r){return String(r.commodity_key||r.name_en||'')}
function market(r){return String(window.KrushiPulseMarketNameFor?.(r.market_id)||(HI?r.market_name_hi:MR?r.market_name_mr:r.market_name_en)||r.market_name_en||r.market_id||'')}
function usable(r){return r&&/^[a-z0-9-]{1,112}$/.test(String(r.market_id||''))&&cropKey(r)&&r.date&&r.unit&&Number.isFinite(Number(r.average))}
function read(){try{const a=JSON.parse(localStorage.getItem(store)||'[]');return Array.isArray(a)?a.filter(x=>x&&typeof x==='object'&&Number.isFinite(x.threshold)&&/^[a-z0-9-]{1,112}$/.test(x.market)).slice(0,30):[]}catch(_){return []}}
function save(rows){try{localStorage.setItem(store,JSON.stringify(rows));return true}catch(_){return false}}
function params(){const r=selected;return new URLSearchParams({market:String(r.market_id),commodity:String(r.name_en||cropKey(r)),variety:String(r.variety||''),grade:String(r.grade||''),unit:String(r.unit).toLowerCase(),days:String(days)})}
async function fetchData(method,p){
 const response=await fetch(base+'/api/v1/intelligence/'+method+'?'+p,{cache:'no-store',headers:{Accept:'application/json'}});
 if(!response.ok)throw Error('HTTP');
 const j=await response.json();if(!j?.ok)throw Error('response');return j.data;
}
function draw(points,unit){
 const container=$('kpHistoryChart');container.replaceChildren();
 if(points.length<2)return;
 const ns='http://www.w3.org/2000/svg',W=620,H=240,pad=42;
 const ys=points.map(p=>Number(p.modal)),low=Math.min(...ys),hi=Math.max(...ys),range=Math.max(hi-low,1);
 const xy=(v,i)=>[pad+i/Math.max(1,points.length-1)*(W-2*pad),H-pad-(Number(v)-low)/range*(H-2*pad)];
 const svg=document.createElementNS(ns,'svg');svg.setAttribute('viewBox','0 0 '+W+' '+H);svg.setAttribute('role','img');svg.setAttribute('aria-label',t('प्रकाशित किंमत इतिहास','Reported price history'));
 const description=document.createElementNS(ns,'title');description.textContent=t('सत्यापित मॉडेल बाजारभाव, अंदाज नाही','Actual reported modal prices, no interpolation');svg.append(description);
 for(let n=0;n<3;n++){
  const v=low+range*n/2,y=xy(v,0)[1],line=document.createElementNS(ns,'line');
  for(const [k,val] of Object.entries({x1:pad,x2:W-pad,y1:y,y2:y,stroke:'currentColor',opacity:'.16'}))line.setAttribute(k,String(val));svg.append(line);
  const label=document.createElementNS(ns,'text');label.setAttribute('x','2');label.setAttribute('y',String(y+4));label.setAttribute('font-size','10');label.setAttribute('fill','currentColor');label.textContent=Math.round(v).toLocaleString('en-IN');svg.append(label);
 }
 const line=document.createElementNS(ns,'polyline');line.setAttribute('points',points.map((p,i)=>xy(p.modal,i).join(',')).join(' '));line.setAttribute('fill','none');line.setAttribute('stroke','#16885b');line.setAttribute('stroke-width','3');svg.append(line);
 points.forEach((p,i)=>{const [x,y]=xy(p.modal,i),c=document.createElementNS(ns,'circle');c.setAttribute('cx',x);c.setAttribute('cy',y);c.setAttribute('r','3');c.setAttribute('fill','#16885b');const title=document.createElementNS(ns,'title');title.textContent=p.date+' · '+money(p.modal)+' / '+unit;c.append(title);svg.append(c);});
 for(const [date,x,align] of [[points[0].date,pad,'start'],[points[points.length-1].date,W-pad,'end']]){
  const label=document.createElementNS(ns,'text');label.setAttribute('x',String(x));label.setAttribute('y',String(H-8));label.setAttribute('text-anchor',align);label.setAttribute('font-size','12');label.setAttribute('fill','currentColor');label.textContent=date;svg.append(label);
 }
 container.append(svg);
}
function compare(d){
 const wrap=$('kpCompareResults');wrap.replaceChildren();
 if(!d?.available||!d.markets?.length){wrap.append(el('p','kp-analysis-note',d?.reason||t('तुलना उपलब्ध नाही.','No comparable reports available.')));return;}
 for(const r of d.markets.slice(0,8)){
  const row=el('div','kp-comparison-row');const details=el('div');
  details.append(el('strong','',market(r)),el('small','',r.market_id+' · '+r.source));
  row.append(details,el('b','',money(r.modal)+' / '+r.unit),el('span','',r.date));wrap.append(row);
 }
 wrap.append(el('p','kp-analysis-note',t('समान प्रकार, दर्जा आणि एकक. प्रत्येक मंडीचा अहवाल दिनांक वेगळा असू शकतो.','Same variety, grade and unit; each market may have a different report date.')));
}
async function refresh(){
 if(!selected)return;
 const call=++requestId,query=params();
 $('kpInsightSelected').textContent=crop(selected)+' · '+market(selected)+' · '+(selected.variety||'—')+' · '+(selected.grade||'—')+' · '+selected.unit+' · '+selected.date;
 $('kpHistoryChart').replaceChildren();$('kpCompareResults').replaceChildren();
 $('kpHistoryMessage').textContent=t('प्रकाशित अहवाल तपासत आहोत…','Loading actual reported price history…');
 try{
  const [history,other]=await Promise.all([fetchData('history',query),fetchData('compare',query)]);
  if(call!==requestId)return;
  if(history.available){const points=(history.points||[]).filter(p=>Number.isFinite(Number(p.modal)));draw(points,history.price_unit||selected.unit);
   $('kpHistoryMessage').textContent=points.length<2?t('एक नोंद आहे; कल काढण्यासाठी किमान दोन दिवस हवेत.','One observation; at least two reporting dates needed to show a trend.')
    :points.length+' '+t('प्रकाशित निरीक्षणे; अंदाज नाही.','reported observations, not predicted values.');
  }else $('kpHistoryMessage').textContent=history.reason||t('इतिहास नाही.','No history available.');
  compare(other);
 }catch(_){if(call!==requestId)return;$('kpHistoryMessage').textContent=t('इतिहास उपलब्ध नाही; संग्रह सुरू आहे का ते तपासा.','History unavailable. Check server archival configuration.');compare(null);}
}
function net(){
 const values=['kpQuantity','kpTransport','kpHandling'].map(k=>Number($(k).value));
 if(!selected||values.some(x=>!Number.isFinite(x)||x<0||x>1e8)){$('kpNetResult').textContent=t('पीक व वैध खर्च निवडा.','Select a crop and enter valid costs.');return;}
 const [quantity,transport,handling]=values,gross=Number(selected.average)*quantity;
 $('kpNetResult').textContent=t('अंदाजित निव्वळ: ','Indicative net: ')+money(gross-transport-handling)+' · '+t('एकूण: ','Gross: ')+money(gross)+' · '+quantity+' '+selected.unit+' · '+selected.date;
}
function listAlerts(){
 const holder=$('kpAlertList');holder.replaceChildren();
 for(const [i,a] of read().entries()){
  const row=el('div','kp-alert-row'),text=el('span','',a.crop+' · '+a.market+' · '+(a.direction==='below'?'≤':'≥')+' '+money(a.threshold)+' / '+a.unit);
  const remove=el('button','kp-alert-remove',t('काढा','Remove'));remove.type='button';
  remove.addEventListener('click',()=>{const all=read();all.splice(i,1);save(all);listAlerts()});row.append(text,remove);holder.append(row);
 }
}
function check(){
 const all=window.KrushiPulseAllRecords?.()||[],alerts=read();if(!all.length||!alerts.length)return;
 let changed=false;const fired=[];
 for(const a of alerts){
  const matches=all.filter(r=>usable(r)&&r.market_id===a.market&&cropKey(r)===a.commodity&&String(r.variety||'')===a.variety&&String(r.grade||'')===a.grade&&String(r.unit).toLowerCase()===a.unit).sort((x,y)=>String(y.date).localeCompare(String(x.date)));
  const r=matches[0];if(!r)continue;
  const yes=a.direction==='below'?Number(r.average)<=a.threshold:Number(r.average)>=a.threshold;
  const fingerprint=[r.date,r.average,r.source||''].join('|');
  if(yes&&a.lastSeen!==fingerprint){a.lastSeen=fingerprint;changed=true;fired.push(a.crop+' '+money(r.average)+' · '+r.date);}
 }
 if(changed){save(alerts);$('kpAlertStatus').textContent=t('प्रकाशित भाव लक्ष्यावर पोहोचला: ','Reported rate reached target: ')+fired.join('; ');
 if('Notification'in window&&Notification.permission==='granted'&&document.visibilityState==='visible'){try{new Notification('KrushiPulse Price Alert',{body:fired.slice(0,2).join('; '),tag:'krushipulse-price'})}catch(_){}}}
}
function saveAlert(){
 const price=Number($('kpTargetPrice').value);
 if(!usable(selected)||!$('kpTargetPrice').value.trim()||!Number.isFinite(price)||price<0||price>1e8){
  $('kpAlertStatus').textContent=t('पीक निवडा आणि योग्य लक्ष्य दर भरा.','Choose a crop and valid target price.');return;
 }
 const a={market:String(selected.market_id),commodity:cropKey(selected),crop:crop(selected),variety:String(selected.variety||''),grade:String(selected.grade||''),unit:String(selected.unit).toLowerCase(),threshold:price,direction:$('kpAlertDirection').value==='below'?'below':'above',lastSeen:''};
 const existing=read().filter(x=>[x.market,x.commodity,x.variety,x.grade,x.unit,x.direction].join('|')!==[a.market,a.commodity,a.variety,a.grade,a.unit,a.direction].join('|'));
 existing.unshift(a);existing.length=Math.min(existing.length,30);
 if(!save(existing)){$('kpAlertStatus').textContent=t('सूचना जतन झाली नाही.','Could not save alert.');return;}
 $('kpAlertStatus').textContent=t('सूचना जतन केली. साइट उघडी असताना तपासू.','Alert saved. Checked while the site is open.');
 listAlerts();if('Notification'in window&&Notification.permission==='default')Notification.requestPermission().catch(()=>{});check();
}
function show(r,scroll=true){
 if(!usable(r))return;
 selected={...r};$('kpTargetPrice').value=String(Math.round(Number(r.average)));net();refresh();
 window.KrushiPulsePhase4SelectRate?.(r);
 if(scroll)$('intelligence')?.scrollIntoView({behavior:'smooth',block:'start'});
}
window.KrushiPulseShowInsights=show;
window.KrushiPulseIntelligenceUpdate=()=>{
 check();if(selected)return;
 const all=window.KrushiPulseAllRecords?.()||[],pref=window.KrushiPulseSavedPreferences?.()||{};
 const r=all.find(x=>usable(x)&&pref.crops?.includes(cropKey(x))&&(!pref.market||pref.market==='all'||pref.market===x.market_id))||all.find(usable);
 if(r)show(r,false);
};
function init(){
 if(!$('kpHistoryChart'))return;
 document.querySelectorAll('[data-period]').forEach(button=>button.addEventListener('click',()=>{
  days=Number(button.dataset.period);document.querySelectorAll('[data-period]').forEach(b=>b.setAttribute('aria-pressed',String(b===button)));refresh();
 }));
 for(const key of ['kpQuantity','kpTransport','kpHandling'])$(key).addEventListener('input',net);
 $('kpSaveAlert').addEventListener('click',saveAlert);listAlerts();window.KrushiPulseIntelligenceUpdate();
 document.addEventListener('visibilitychange',()=>{if(!document.hidden)check();});
}
if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',init,{once:true});else init();
})();