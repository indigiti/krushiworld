/* KrushiPulse v2.0 — user-triggered multilingual voice search, provenance-first MSP,
   official mandi profiles, deterministic explainable historical observations. */
(()=>{'use strict';
const $=id=>document.getElementById(id);
const lang=['mr','hi','en'].includes(document.documentElement.lang)?document.documentElement.lang:'mr';
const dict=window.APMC_I18N||{},base=String(window.APMC_APP_BASE||'');
const t=(mr,en)=>lang==='hi'?(dict.ui_hi?.[en]||en):(lang==='mr'?mr:en);
const fmt=n=>Number.isFinite(Number(n))?'₹'+Number(n).toLocaleString('en-IN',{maximumFractionDigits:2}):'—';
let selected=null,revision=0,profileRevision=0;
function E(tag,text='',klass=''){const node=document.createElement(tag);if(klass)node.className=klass;node.textContent=text;return node;}
function clear(id){const node=$(id);if(node)node.replaceChildren();return node}
function addFact(root,label,value){if(!root)return;const row=E('div','','kp-phase4-fact');row.append(E('span',label),E('strong',value));root.append(row);}
function addLink(root,title,url){if(!root||typeof url!=='string'||!/^https:\/\/[a-z0-9.-]+(?:\/[^\s]*)?$/i.test(url))return;
const a=E('a',title+' ↗','kp-phase4-source');a.href=url;a.target='_blank';a.rel='noopener noreferrer';root.append(a);}
async function read(url){const controller=new AbortController();const timeout=setTimeout(()=>controller.abort(),9000);
 try{const result=await fetch(url,{signal:controller.signal,cache:'no-store',headers:{Accept:'application/json'}});if(!result.ok)throw Error('unavailable');const payload=await result.json();if(!payload?.ok||!payload.data)throw Error('no data');return payload.data;}
 finally{clearTimeout(timeout);}
}
function query(row){return new URLSearchParams({
 commodity:String(row.name_en||row.commodity_key||'').slice(0,160),
 market:String(row.market_id||''),variety:String(row.variety||''),
 grade:String(row.grade||''),unit:String(row.unit||'').toLowerCase(),days:'30'});}
function update(row){
 if(!row||!row.market_id||!row.date)return;
 selected={...row};const call=++revision;
 const p=query(row);
 $('kpExplanationStatus').textContent=t('मूळ अहवाल तपासत आहोत…','Checking reported observations…');
 $('kpMspStatus').textContent=t('अधिकृत MSP संदर्भ तपासत आहोत…','Checking official MSP references…');
 clear('kpExplanationFacts');clear('kpMspResults');
 read(base+'/api/v1/intelligence/insights?'+p).then(data=>{
  if(call!==revision)return;
  const root=clear('kpExplanationFacts');
  if(!data.available){$('kpExplanationStatus').textContent=data.reason||t('निरीक्षणासाठी पुरेशी माहिती नाही.','Not enough distinct observations for an explanation.');return;}
  const direction=data.direction==='up'?t('वाढ','Higher'):data.direction==='down'?t('घट','Lower'):t('बदल नाही','Unchanged');
  $('kpExplanationStatus').textContent=t('खऱ्या नोंदींवरील बदल: ','Change between verified reports: ')+direction+
    ' · '+(data.change_pct===null?'—':Math.abs(Number(data.change_pct)).toFixed(2)+'%');
  addFact(root,t('पहिला अहवाल','First report'),data.first_report_date+' · '+fmt(data.first_modal)+' / '+data.unit);
  addFact(root,t('शेवटचा अहवाल','Last report'),data.last_report_date+' · '+fmt(data.last_modal)+' / '+data.unit);
  addFact(root,t('अहवाल असलेले दिवस','Distinct reporting dates'),String(data.observations));
  addFact(root,t('मॉडेल दरांची कक्षा','Observed modal range'),fmt(data.low_modal)+' – '+fmt(data.high_modal));
  addFact(root,t('नमुना उपलब्धता','Sample coverage'),data.observation_quality?.replaceAll('_',' ')||'—');
  if(data.is_stale)root.append(E('p',t('सर्वात नवा अहवालही जुना आहे. आजचा दर समजू नका.','Latest sample is stale; do not treat it as today’s quotation.'),'kp-phase4-warning'));
  root.append(E('p',t('कोणतेही गहाळ दिवस किंवा भाव अंदाजाने भरलेले नाहीत.','No missing days or prices have been interpolated.'),'kp-phase4-note'));
 }).catch(()=>{if(call===revision)$('kpExplanationStatus').textContent=t('डेटा उपलब्ध नाही; संग्रहाची स्थिती तपासा.','Insights unavailable; check historical data collection.');});
 const commodity=String(row.name_en||row.commodity_key||'').slice(0,160),season=$('kpMspSeason')?.value||'2026-27';
 read(base+'/api/v1/intelligence/msp?'+new URLSearchParams({commodity,season})).then(data=>{
  if(call!==revision)return;
  const root=clear('kpMspResults');
  if(!data.available||!Array.isArray(data.references)||!data.references.length){$('kpMspStatus').textContent=t('या पीक व हंगामासाठी अधिकृत MSP संदर्भ उपलब्ध नाही.','No verified MSP entry for this crop and marketing season.');return;}
  $('kpMspStatus').textContent=t('निवडलेल्या हंगामातील शासकीय MSP (रु./क्विंटल).','Government MSP reference for the selected season (₹/quintal).');
  for(const entry of data.references){
   const heading=String(entry.commodity)+(entry.grade?' · '+entry.grade:'');
   addFact(root,heading,fmt(entry.msp_inr_per_quintal)+' / '+t('क्विंटल','quintal'));
   addFact(root,t('विपणन हंगाम','Marketing season'),entry.marketing_season+' · '+entry.season_type);
   addLink(root,t('सरकारी मूळ जाहीरनामा','Government source publication'),entry.source_url);
   // Grades and rate units must match exactly before comparing numbers.
   const gradeMatch=String(row.grade||'').trim().toLocaleLowerCase()===String(entry.grade||'').trim().toLocaleLowerCase();
   const unitMatch=['quintal','qtl'].includes(String(row.unit||'').toLowerCase());
   if(unitMatch&&gradeMatch){
    const diff=Number(row.average)-Number(entry.msp_inr_per_quintal);
    if(Number.isFinite(diff))addFact(root,t('प्रकाशित मॉडेल दर विरुद्ध MSP फरक (हमी नाही)','Reported modal minus MSP (not procurement eligibility)'),(diff>=0?'+':'')+fmt(diff));
   }
  }
  root.append(E('p',t('ग्रेड किंवा एकक वेगळे असल्यास संख्यात्मक तुलना करता येणार नाही.','No numeric comparison when grade or reported unit differs.'),'kp-phase4-note'));
 }).catch(()=>{if(call===revision)$('kpMspStatus').textContent=t('MSP स्रोत उपलब्ध नाही.','MSP reference is unavailable.');});
 showProfile(String(row.market_id));
}
async function showProfile(marketId){
 const call=++profileRevision;
 if(!/^[a-z0-9-]{1,110}$/.test(marketId)||marketId==='all'){
  clear('kpProfileDetails');$('kpProfileStatus').textContent=t('मंडी निवडा.','Choose a mandi.');return;
 }
 $('kpProfileStatus').textContent=t('मंडीचे सत्यापित तपशील आणत आहोत…','Loading source-backed mandi profile…');
 try{
  const profile=await read(base+'/api/v1/mandis/'+encodeURIComponent(marketId)+'/profile');
  if(call!==profileRevision)return;
  const root=clear('kpProfileDetails');
  const name=(lang==='mr'?profile.name_mr:lang==='hi'?profile.name_hi:profile.name_en)||profile.name_en;
  $('kpProfileStatus').textContent=name+' · '+profile.district+', '+profile.state;
  if(profile.pincode)addFact(root,'PIN',String(profile.pincode));
  if(profile.hours)addFact(root,t('प्रकाशित वेळ (विभागनिहाय)','Published department hours'),profile.hours);
  if(profile.contact_phone)addFact(root,t('संपर्क','Contact'),profile.contact_phone);
  addFact(root,t('सुविधांची पडताळणी','Facility verification'),profile.facility_status==='verified_subset'
    ?t('काही सुविधा अधिकृतपणे नोंदलेल्या आहेत.','Some facilities have official references.')
    :t('माहिती अद्याप पडताळलेली नाही.','Not verified; do not assume unavailable.'));
  for(const facility of profile.verified_facilities||[])root.append(E('span','✓ '+facility,'kp-phase4-facility'));
  if(profile.profile_verified_at)addFact(root,t('तपासणीची तारीख','Profile reviewed'),profile.profile_verified_at);
  if(profile.rate_report_date)addFact(root,t('बाजारभाव अहवालाची तारीख','Last rate report'),profile.rate_report_date);
  addLink(root,t('अधिकृत मंडी माहिती','Official market profile'),profile.profile_source_url);
  if(!profile.profile_source_url)root.append(E('p',t('या मंडीसाठी अधिकृत संपर्क/सुविधा तपशील जोडलेले नाहीत.','No verified contact or facility details have been added for this mandi.'),'kp-phase4-note'));
 }catch(_){if(call===profileRevision){clear('kpProfileDetails');$('kpProfileStatus').textContent=t('या मंडीचे तपशील सध्या उपलब्ध नाहीत.','Mandi profile unavailable.');}}
}
window.KrushiPulsePhase4SelectRate=update;
function resolveSpeech(text){
 const normalized=String(text||'').trim().slice(0,120).toLocaleLowerCase();
 if(!normalized)return '';
 const candidates=new Set();
 for(const [key,item] of Object.entries(dict.commodities||{})){
  for(const value of [key,item?.en,item?.mr,item?.hi]){
   if(typeof value==='string'&&value.trim().length>=3)candidates.add(value.trim());
  }
 }
 const longest=[...candidates].filter(x=>normalized.includes(x.toLocaleLowerCase()))
  .sort((a,b)=>b.length-a.length)[0];
 return (longest||normalized.replace(/(?:का भाव|के भाव|बाजार भाव|आजचा भाव|आजचे दर|today price|mandi rate|भाव किती|price of)/gi,'').trim()).slice(0,100);
}
function initVoice(){
 const button=$('kpVoiceSearch'),status=$('kpVoiceStatus'),input=$('kpSearch');
 if(!button||!status||!input)return;
 const Speech=window.SpeechRecognition||window.webkitSpeechRecognition;
 if(!Speech){
  button.disabled=true;button.title=t('या ब्राउझरमध्ये आवाज ओळख उपलब्ध नाही.','Voice recognition is unsupported here; type your crop instead.');
  status.textContent=t('हा ब्राउझर आवाज शोधाला समर्थन देत नाही. टाइप करून शोधा.','This browser does not support speech recognition. You can type to search.');
  return;
 }
 let active=null;
 button.addEventListener('click',()=>{
  if(active){active.stop();return;}
  let listener;
  try{listener=new Speech();}catch(_){status.textContent=t('मायक्रोफोन सेवा सुरू झाली नाही.','Could not start the browser speech service.');return;}
  active=listener;
  listener.lang=lang==='hi'?'hi-IN':lang==='mr'?'mr-IN':'en-IN';
  listener.interimResults=false;listener.maxAlternatives=1;listener.continuous=false;
  status.textContent=t('बोला: कांदा, टोमॅटो, गहू… (तुमचा ब्राउझर ऑडिओ सेवा प्रदात्याकडे पाठवू शकतो.)','Say a crop name; your browser may process audio with its speech provider.');
  button.setAttribute('aria-pressed','true');
  listener.onresult=event=>{
   const transcript=String(event.results?.[0]?.[0]?.transcript||'');
   const query=resolveSpeech(transcript);
   if(query){input.value=query;input.dispatchEvent(new Event('input',{bubbles:true}));input.focus();status.textContent=t('ओळखले: ','Recognized: ')+transcript;}
  };
  listener.onerror=event=>{status.textContent=event.error==='not-allowed'
   ?t('मायक्रोफोनची परवानगी नाकारली. टाइप करून शोधा.','Microphone permission denied. Type to search.')
   :t('आवाज ओळख पूर्ण झाली नाही. टाइप करून शोधा.','Speech could not be recognized. Please type to search.');};
  listener.onend=()=>{active=null;button.setAttribute('aria-pressed','false');};
  try{listener.start();}catch(_){active=null;button.setAttribute('aria-pressed','false');status.textContent=t('मायक्रोफोन सुरू करता आला नाही.','Could not start microphone recognition.');}
 });
}
function init(){
 if(!$('kpMspSeason'))return;
 initVoice();
 $('kpMspSeason').addEventListener('change',()=>{if(selected)update(selected);});
 $('kpMarket')?.addEventListener('change',e=>showProfile(String(e.target.value||'')));
 const routeMarket=String(window.APMC_ROUTE?.market||'');
 if(routeMarket)showProfile(routeMarket);
}
if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',init,{once:true});else init();
})();