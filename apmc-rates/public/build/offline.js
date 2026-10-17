/* Optional install and offline warning. No push notifications or background rate checks. */
(()=>{'use strict';
const el=id=>document.getElementById(id),button=el('kpInstallApp'),status=el('kpOfflineStatus');
if(!button||!status)return;
let pending=null;
const lang=document.documentElement.lang,dict=window.APMC_I18N||{};
const t=(mr,en)=>lang==='hi'?(dict.ui_hi?.[en]||en):(lang==='mr'?mr:en);
function render(){
 const offline=!navigator.onLine;
 status.textContent=offline?t('ऑफलाइन: जतन केलेले पान जुने असू शकते; नवीन दरांसाठी इंटरनेट जोडा.','Offline: cached pages may be outdated; reconnect to refresh source reports.')
  :t('ऑनलाइन: उपलब्ध रिपोर्टची तारीख नेहमी तपासा.','Online: always check each report’s original date.');
}
window.addEventListener('online',render);window.addEventListener('offline',render);
window.addEventListener('beforeinstallprompt',event=>{event.preventDefault();pending=event;button.hidden=false;});
button.addEventListener('click',async()=>{
 if(pending){pending.prompt();const result=await pending.userChoice.catch(()=>({outcome:'dismissed'}));pending=null;
  status.textContent=result.outcome==='accepted'?t('अ‍ॅप इन्स्टॉल करण्याची विनंती स्वीकारली.','App installation request accepted.'):t('इन्स्टॉल रद्द झाले.','Installation cancelled.');
 }else status.textContent=t('ब्राउझरच्या मेनूमधून Add to Home Screen निवडा.','Use your browser menu → Add to Home Screen, if supported.');
});
window.addEventListener('appinstalled',()=>{button.hidden=true;status.textContent=t('अ‍ॅप इन्स्टॉल झाले.','App installed.');});
if('serviceWorker' in navigator&&window.isSecureContext){
 const scope=String(window.APMC_APP_BASE||'')+'/';
 window.addEventListener('load',()=>navigator.serviceWorker.register(scope+'sw.js',{scope}).catch(()=>{}),{once:true});
}
render();
})();