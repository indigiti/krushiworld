#!/usr/bin/env node
'use strict';
/** Headless visual and interactive audit of the *packaged two-root deployment*. */
const path=require('node:path');
const fs=require('node:fs');
const os=require('node:os');
const {spawn}=require('node:child_process');
const { chromium }=require('playwright-core');
const output=process.env.VISUAL_AUDIT_OUTPUT||path.join(process.cwd(),'visual-audit-screenshots');
const temp=fs.mkdtempSync(path.join(os.tmpdir(),'krushipulse-visual-'));
const repo=path.resolve(__dirname,'..'),port=17843;
const address='http://127.0.0.1:'+port+'/krushiworld';
let php=null,browser=null;
const fixture={
  meta:{generated_at:'2026-10-17T10:20:00Z',serving_date:'2026-10-17',status:'ready',records:2,markets_available:2,markets_expected:19},
  pulse:{up:0,down:0,unchanged:2,total_arrival:0},
  markets:[
    {market_id:'main-market-yard',name:'Main Market Yard',name_en:'Main Market Yard',name_mr:'मुख्य बाजार आवार',records:1,date:'2026-10-17'},
    {market_id:'manjari',name:'Manjari',name_en:'Manjari',name_mr:'मांजरी',records:1,date:'2026-10-17'}
  ],
  categories:['Vegetables','Grains'],featured:[],
  records:[
    {market_id:'main-market-yard',feed_slug:'main-kanda',market_name:'Main Market Yard',
     market_name_en:'Main Market Yard',market_name_mr:'मुख्य बाजार आवार',market_name_hi:'मुख्य बाजार यार्ड',
     name_en:'Onion',name_mr:'कांदा',name_hi:'प्याज',commodity_key:'onion',
     category:'Vegetables',unit:'qtl',date:'2026-10-17',min:700,max:1100,average:950,change_pct:null},
    {market_id:'manjari',feed_slug:'manjari-veg',market_name:'Manjari',
     market_name_en:'Manjari',market_name_mr:'मांजरी',market_name_hi:'मांजरी',
     name_en:'Gahu (lokavana)',name_mr:'गहू',name_hi:'गेहूँ',commodity_key:'gahu-lokavana',
     category:'Grains',unit:'qtl',date:'2026-10-17',min:3200,max:4200,average:3700,change_pct:null}
  ]
};
const markets=[
 {market_id:'main-market-yard',slug:'main-market-yard',name:'Main Market Yard',
  name_en:'Main Market Yard',name_mr:'मुख्य बाजार आवार',name_hi:'मुख्य बाजार यार्ड',
  state:'Maharashtra',district:'Pune',records:1,date:'2026-10-17',source:'Pune APMC'},
 {market_id:'manjari',slug:'manjari',name:'Manjari',
  name_en:'Manjari',name_mr:'मांजरी',name_hi:'मांजरी',
  state:'Maharashtra',district:'Pune',records:1,date:'2026-10-17',source:'Pune APMC'}
];
const marketData={markets,count:2,coverage:{total:2,with_coordinates:2,latest_report_date:'2026-10-17'},national:{status:'not_configured',latest_report_date:null,updated_at:null,market_count:0}};
function check(x,msg){if(!x)throw new Error(msg)}
function sleep(t){return new Promise(r=>setTimeout(r,t))}
async function startServer(){
 const publicRoot=path.join(temp,'public_html','krushiworld');
 const privateRoot=path.join(temp,'private_html','krushiworld');
 fs.mkdirSync(publicRoot,{recursive:true});fs.mkdirSync(privateRoot,{recursive:true});
 fs.cpSync(path.join(repo,'release','public'),publicRoot,{recursive:true});
 fs.cpSync(path.join(repo,'release','private'),privateRoot,{recursive:true});
 const router=path.join(repo,'tools','php-dev-router.php');
 php=spawn('php',['-S','127.0.0.1:'+port,'-t',path.join(temp,'public_html'),router],{
  cwd:repo,env:{...process.env,APMC_DISABLE_WEB_FALLBACK:'1'},stdio:'ignore'
 });
 for(let n=0;n<70;n++){
  try{const response=await fetch(address+'/en/');if(response.ok)return;}catch(_){}
  await sleep(170);
 }
 throw new Error('PHP server never became healthy');
}
async function installFixtures(page){
 await page.route('**/*',async route=>{
  const request=route.request();
  const url=new URL(request.url());
  if(url.hostname!=='127.0.0.1')return route.continue();
  const api=url.searchParams.get('api');
  if(api==='latest')return route.fulfill({status:200,contentType:'application/json',body:JSON.stringify({ok:true,data:fixture,fallback_due:false})});
  if(api==='markets')return route.fulfill({status:200,contentType:'application/json',body:JSON.stringify({ok:true,data:marketData})});
  if(api==='national')return route.fulfill({status:200,contentType:'application/json',body:JSON.stringify({ok:true,data:{records:[],count:0}})});
  return route.continue();
 });
}
async function viewportAudit(page,label,locale,expectedMarket){
 const metrics=await page.evaluate(()=>{
  const q=s=>document.querySelector(s);
  const box=s=>{const el=q(s);if(!el)return null;const r=el.getBoundingClientRect();return {x:r.x,y:r.y,width:r.width,height:r.height,visible:r.width>0&&r.height>0};};
  const body=document.body,root=document.documentElement,table=q('.kp-table-scroll');
  const errors=[];
  document.querySelectorAll('img').forEach(img=>{if(!img.complete||img.naturalWidth===0)errors.push('broken-image:'+img.src)});
  return {
   language:root.lang,screen:innerWidth,documentWidth:root.scrollWidth,
   horizontalOverflow:Math.max(0,root.scrollWidth-innerWidth),
   header:box('.kp-header'),title:box('h1'),rates:box('.kp-table-card'),
   tableContainer:table?{client:table.clientWidth,scroll:table.scrollWidth}:null,
   nav:box('.kp-nav'),pwa:!!q('link[rel="manifest"]'),
   sections:['#rates','#intelligence','#planning','#crop-intelligence'].map(s=>({id:s,...box(s)})),
   imageErrors:errors
  };
 });
 check(metrics.language===locale,label+' incorrect lang');
 check(metrics.horizontalOverflow<18,label+' document overflow '+metrics.horizontalOverflow+'px');
 check(metrics.title?.visible&&metrics.title.x>=-8,label+' hero title hidden/clipped');
 check(metrics.rates?.visible,label+' table section not rendered');
 check(metrics.sections.every(x=>x.visible),label+' feature section hidden');
 check(metrics.pwa,label+' PWA manifest missing');
 check(!metrics.imageErrors.length,label+' broken images '+metrics.imageErrors.join(','));
 const rows=await page.locator('#kpTableRows tr').count();
 check(rows===2,label+' did not show two fixture records (got '+rows+')');
 const cells=await page.locator('#kpTableRows tr').evaluateAll(rows=>rows.map(r=>({crop:r.cells[1]?.innerText,market:r.cells[2]?.innerText,date:r.cells[7]?.innerText})));
 check(cells.some(r=>r.crop.includes('Gahu')&&r.market.includes(expectedMarket)),label+' wheat market label incorrect: '+JSON.stringify(cells));
 check(cells.every(r=>!r.market.includes('Gahu')&&!r.market.includes('Onion')&&!r.market.includes('प्याज')),label+' commodity leaked into market column');
 fs.mkdirSync(output,{recursive:true});
 await page.screenshot({path:path.join(output,label+'.png'),fullPage:true,animations:'disabled'});
 process.stdout.write('VISUAL AUDIT '+label+' '+JSON.stringify({width:metrics.screen,overflow:metrics.horizontalOverflow,table:metrics.tableContainer,sections:metrics.sections.length,rows,labels:cells})+'\n');
}
async function main(){
 await startServer();
 const binary=[process.env.CHROME_BIN,'/usr/bin/google-chrome','/usr/bin/google-chrome-stable','/usr/bin/chromium'].find(p=>p&&fs.existsSync(p));
 check(binary,'No compatible Chromium/Chrome executable found');
 browser=await chromium.launch({executablePath:binary,headless:true,args:['--no-sandbox','--disable-dev-shm-usage']});
 const cases=[
  {label:'desktop-en-harvest',locale:'en',width:1440,height:900,market:'Manjari'},
  {label:'tablet-mr-midnight',locale:'mr',width:768,height:1000,market:'मांजरी',theme:'midnight'},
  {label:'mobile-hi-saffron',locale:'hi',width:390,height:844,market:'मांजरी',theme:'saffron'},
  {label:'narrow-mobile-en',locale:'en',width:360,height:760,market:'Manjari'}
 ];
 for(const c of cases){
  const context=await browser.newContext({viewport:{width:c.width,height:c.height},deviceScaleFactor:1,locale:c.locale==='en'?'en-IN':c.locale==='hi'?'hi-IN':'mr-IN',serviceWorkers:'block'});
  const page=await context.newPage();
  const failures=[];page.on('pageerror',err=>failures.push('pageerror: '+err.message));
  await installFixtures(page);
  await page.goto(address+'/'+c.locale+'/',{waitUntil:'domcontentloaded'});
  if(c.theme)await page.evaluate(t=>document.documentElement.dataset.theme=t,c.theme);
  await page.locator('#kpTableRows tr').first().waitFor({timeout:12000});
  await page.waitForTimeout(400);
  await viewportAudit(page,c.label,c.locale,c.market);
  check(!failures.length,c.label+' JS runtime error '+failures.join('; '));
  // Interactions: search updates table without incorrectly replacing market.
  await page.locator('#kpSearch').fill('Onion');
  check(await page.locator('#kpTableRows tr').count()===1,c.label+' crop search failed');
  await page.locator('#kpSearch').fill('');
  await page.locator('#kpTheme').click();
  check(await page.locator('html').getAttribute('data-theme')!==c.theme||!c.theme,c.label+' theme did not cycle');
  const trend=page.locator('#kpTableRows tr').first().locator('.kp-rate-insight');
  await trend.click();
  check((await page.locator('#kpInsightSelected').innerText()).length>5,c.label+' historical trend selection failed');
  await context.close();
 }
 process.stdout.write('VISUAL/INTERACTION AUDIT: PASS (desktop, tablet, 390px & 360px mobile, 3 languages, 3 themes)\n');
}
main().catch(e=>{process.stderr.write('VISUAL AUDIT FAILURE: '+e.stack+'\n');process.exitCode=1;})
.finally(async()=>{if(browser)await browser.close().catch(()=>{});if(php)php.kill('SIGTERM');fs.rmSync(temp,{recursive:true,force:true});});
