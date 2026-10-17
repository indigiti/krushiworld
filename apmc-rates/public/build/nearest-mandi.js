/* KrushiPulse Phase 1: privacy-first GPS, audited postal references and mandi search. */
(()=>{
'use strict';
const $=id=>document.getElementById(id);
const lang=document.documentElement.lang,hi=lang==='hi',mr=lang==='mr';
const dict=window.APMC_I18N||{},t=(marathi,english)=>hi?(dict.ui_hi?.[english]||english):(mr?marathi:english);
const valid=(x,a,b)=>Number.isFinite(Number(x))&&Number(x)>=a&&Number(x)<=b;
const rad=Math.PI/180;
let devicePoint=null,mandis=[],loading=null,requesting=false,mode='none';
function distance(a,b){
 const dlat=(b.latitude-a.latitude)*rad,dlon=(b.longitude-a.longitude)*rad;
 const v=Math.sin(dlat/2)**2+Math.cos(a.latitude*rad)*Math.cos(b.latitude*rad)*Math.sin(dlon/2)**2;
 return 6371.0088*2*Math.asin(Math.sqrt(Math.min(v,1)));
}
function setStatus(message){$('kpNearbyStatus').textContent=message;}
function normalize(m){
 if(!m||typeof m!=='object')return null;
 const id=String(m.market_id||m.slug||'');
 if(!/^[a-z0-9-]{1,110}$/.test(id))return null;
 const geo=m.geo&&valid(m.geo.latitude,-90,90)&&valid(m.geo.longitude,-180,180)
  ?{latitude:Number(m.geo.latitude),longitude:Number(m.geo.longitude),precision:String(m.geo.precision||'approximate')}:null;
 const name=String((hi?(m.name_hi||dict.markets?.[m.name_en||m.name]?.hi):mr?m.name_mr:m.name_en)||m.name||id);
 return {id,name,geo,state:String(m.state||''),district:String(m.district||''),pin:String(m.pincode||''),
         count:Number(m.records)||0,date:String(m.date||''),source:String(m.source||'')};
}
async function loadMandis(){
 if(loading)return loading;
 loading=(async()=>{
  try{
   const url=window.APMC_API?.replace('api=latest','api=markets');
   if(!url)throw Error('no directory');
   const response=await fetch(url,{cache:'no-store',headers:{Accept:'application/json'}});
   if(!response.ok)throw Error('unavailable');
   const json=await response.json();
   if(!json?.ok||!Array.isArray(json.data?.markets))throw Error('invalid directory');
   mandis=json.data.markets.map(normalize).filter(Boolean);
   return true;
  }catch(_){return false;}
 })();
 const ok=await loading;
 loading=null;
 return ok;
}
function choose(id){
 if(typeof window.KrushiPulseSelectMarket==='function')return window.KrushiPulseSelectMarket(id);
 const sel=$('kpMarket');
 if(!sel||!Array.from(sel.options).some(o=>o.value===id))return false;
 sel.value=id;sel.dispatchEvent(new Event('change',{bubbles:true}));return true;
}
function render(rows,explanation){
 const holder=$('kpNearbyList');holder.replaceChildren();
 setStatus(explanation);
 for(const m of rows.slice(0,8)){
  const button=document.createElement('button');button.type='button';button.className='kp-nearby-card';
  const title=document.createElement('strong');title.textContent=m.name;
  const location=document.createElement('small');
  location.textContent=[m.district,m.state].filter(Boolean).join(', ')+(m.pin?' · PIN '+m.pin:'');
  const distanceTag=document.createElement('b');
  distanceTag.textContent=Number.isFinite(m.km)?'~'+m.km.toFixed(1)+' km':m.pin? t('टपाल क्रमांक जुळला','PIN match'):t('जवळचा संदर्भ नाही','Distance unavailable');
  const date=document.createElement('small');
  date.textContent=m.count>0?m.count+' '+t('नोंदी','records')+' · '+t('अहवाल: ','Report: ')+(m.date||'—')
   :t('नवीन अहवाल उपलब्ध नाही','No current published report');
  button.append(title,location,distanceTag,date);
  button.addEventListener('click',()=>{
   if(!choose(m.id)){setStatus(t('मंडी यादीतून निवडता आली नाही. कृपया पुन्हा प्रयत्न करा.','Mandi unavailable in the current directory. Please retry.'));return;}
   $('rates')?.scrollIntoView({behavior:'smooth',block:'start'});
  });
  holder.append(button);
 }
}
function byDistance(){
 if(!devicePoint)return[];
 return mandis.filter(x=>x.geo).map(x=>({...x,km:distance(devicePoint,x.geo)}))
  .filter(x=>x.km<=150).sort((a,b)=>a.km-b.km||b.count-a.count).slice(0,8);
}
function onPostalSearch(){
 const pin=String($('kpPincode')?.value||'').trim();
 if(!/^[1-9][0-9]{5}$/.test(pin)){render([],t('६ अंकी वैध पिनकोड टाका.','Enter a valid six-digit PIN code.'));return;}
 mode='pin';
 loadMandis().then(ok=>{
  if(mode!=='pin')return;
  if(!ok){render([],t('मंडी यादी उपलब्ध नाही. नंतर पुन्हा प्रयत्न करा.','Mandi directory unavailable. Please try again later.'));return;}
  const matches=mandis.filter(m=>m.pin===pin).sort((a,b)=>b.count-a.count);
  if(!matches.length){render([],t('या पिनकोडसाठी पडताळलेली मंडी उपलब्ध नाही. राज्य/जिल्हा निवडा किंवा GPS वापरा.','No source-verified mandi postal reference for this PIN. Use state/district or GPS instead.'));return;}
  render(matches,t('या पिनकोडशी नोंदलेली मंडी. हे पिनकोडचे केंद्रबिंदू किंवा रस्त्याचे अंतर नाही.','Mandi address matched to this PIN. This is not a PIN centroid or driving-distance estimate.'));
 });
}
function onTextSearch(){
 const query=String($('kpMandiQuickSearch')?.value||'').trim().toLocaleLowerCase();
 if(query.length<2){render([],t('किमान दोन अक्षरे लिहा.','Enter at least two characters.'));return;}
 mode='text';
 loadMandis().then(ok=>{
  if(mode!=='text')return;
  if(!ok){render([],t('मंडी यादी उपलब्ध नाही.','Mandi directory unavailable.'));return;}
  const rows=mandis.filter(m=>[m.name,m.state,m.district,m.pin,m.id].some(v=>v.toLocaleLowerCase().includes(query)))
    .sort((a,b)=>b.count-a.count||a.name.localeCompare(b.name)).slice(0,8);
  render(rows,rows.length?t('नाव किंवा जिल्ह्यानुसार मंड्या. नोंद असलेल्या मंडीवर क्लिक करा.','Markets matching your name/district search. Choose a mandi to view reports.')
   :t('जुळणारी मंडी सापडली नाही. राज्य/जिल्हा फिल्टर वापरा.','No matching market found. Try state/district filters.'));
 });
}
function locate(){
 if(requesting)return;
 if(!window.isSecureContext||!navigator.geolocation){
  setStatus(t('GPS साठी HTTPS आणि ब्राउझर परवानगी आवश्यक.','Geolocation requires HTTPS and browser permission.'));return;
 }
 mode='gps';requesting=true;$('kpLocate').disabled=true;
 setStatus(t('स्थान परवानगीची वाट पाहत आहोत…','Waiting for your location permission…'));
 navigator.geolocation.getCurrentPosition(async p=>{
  requesting=false;$('kpLocate').disabled=false;
  if(!valid(p.coords?.latitude,-90,90)||!valid(p.coords?.longitude,-180,180)){
   setStatus(t('स्थान अयोग्य आहे. पुन्हा प्रयत्न करा.','Invalid coordinates. Retry.'));return;
  }
  devicePoint={latitude:Number(p.coords.latitude),longitude:Number(p.coords.longitude)};
  $('kpClearLocation').hidden=false;
  const ok=await loadMandis();
  if(mode!=='gps')return;
  if(!ok){render([],t('मंडी यादी उपलब्ध नाही.','Mandi directory unavailable.'));return;}
  const candidates=byDistance();
  const closestWithReports=candidates.find(m=>m.count>0);
  if(closestWithReports)choose(closestWithReports.id);
  if(!candidates.length){
   render([],t('१५० किमीमध्ये नकाशावर पडताळलेल्या मंड्या सापडल्या नाहीत. राज्य व जिल्ह्याने मंडी शोधा.','No mapped mandi within 150 km. Search by state and district; unmapped mandis are not placed on the map.'));
  }else{
   render(candidates,t('पडताळलेले ठिकाण असलेल्या मंड्या, अंदाजे सरळ रेषेत अंतरानुसार.','Mandis with audited coordinates, sorted by approximate straight-line distance.'));
  }
 },err=>{
  requesting=false;$('kpLocate').disabled=false;
  setStatus(err?.code===1?t('स्थान परवानगी नाकारली. मंडी स्वतः निवडा.','Location permission denied. Choose a market manually.')
   :t('स्थान उपलब्ध नाही. नाव, पिनकोड किंवा राज्य/जिल्ह्याने शोधा.','Location unavailable. Search by name, PIN or state/district.'));
 },{enableHighAccuracy:false,timeout:12000,maximumAge:300000});
}
function init(){
 if(!$('kpLocate'))return;
 $('kpLocate').addEventListener('click',locate);
 $('kpPincodeSearch')?.addEventListener('click',onPostalSearch);
 $('kpPincode')?.addEventListener('keydown',e=>{if(e.key==='Enter'){e.preventDefault();onPostalSearch();}});
 $('kpMandiQuickButton')?.addEventListener('click',onTextSearch);
 $('kpMandiQuickSearch')?.addEventListener('keydown',e=>{if(e.key==='Enter'){e.preventDefault();onTextSearch();}});
 $('kpClearLocation').addEventListener('click',()=>{
  devicePoint=null;mode='none';$('kpClearLocation').hidden=true;$('kpNearbyList').replaceChildren();
  setStatus(t('स्थान हटवले. निवडलेली मंडी जतन राहील.','Location cleared. Your selected mandi stays saved.'));
 });
 $('kpBrowseDistrict')?.addEventListener('click',()=>{$('kpState')?.focus();});
}
if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',init,{once:true});else init();
})();