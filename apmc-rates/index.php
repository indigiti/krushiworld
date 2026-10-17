<?php date_default_timezone_set('Asia/Kolkata'); ?>
<!DOCTYPE html>
<html lang="mr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>दैनिक बाजारभाव | KrushiWorld</title>
<style>
:root{font-family:system-ui,-apple-system,sans-serif;background:#f5f7f0;color:#1f3326}*{box-sizing:border-box}
body{margin:0}.shell{max-width:1180px;margin:auto;padding:24px}.hero{background:linear-gradient(120deg,#123c2b,#216345);color:#fff;padding:56px 24px;border-radius:24px}
header{display:flex;justify-content:space-between;align-items:center;gap:12px;margin-bottom:22px}header strong{font-size:1.4rem}header a{color:#245d3c}
h1{font-size:clamp(2rem,5vw,3.6rem);margin:0 0 12px}.hero p{opacity:.87;margin:0;max-width:65ch}
main{margin-top:30px}.controls{display:flex;flex-wrap:wrap;gap:12px;margin:16px 0 22px}
input,select{border:1px solid #ced8ce;border-radius:12px;background:#fff;padding:13px 16px;font:inherit;min-height:48px;color:inherit}
input[type=search]{flex:1;min-width:180px}.card{background:white;border:1px solid #e1e9dc;border-radius:18px;overflow:hidden}
table{width:100%;border-collapse:collapse;text-align:left}th{background:#ecf4ea;font-size:.82rem;color:#49604f}th,td{padding:15px;border-bottom:1px solid #eff2eb}tr:last-child td{border-bottom:0}
th.num,td.num{text-align:right;font-variant-numeric:tabular-nums}.modal{font-weight:750;color:#17683b}
.badge{display:inline-block;border-radius:99px;padding:7px 12px;background:#e1f1e6;color:#145331;font-size:.8rem}
.notice{margin:16px 0;color:#5c695d}footer{padding:25px 0;color:#637265;font-size:.9rem}
a{color:#246a45}@media(max-width:700px){.shell{padding:14px}.hero{padding:32px 20px}th,td{padding:11px 8px;font-size:.87rem}.hide-mobile{display:none}}
</style></head>
<body><div class="shell"><header><strong>🌾 KrushiWorld</strong><span>महाराष्ट्र · Market Intelligence</span></header>
<section class="hero"><div class="badge">Daily APMC Updates</div><h1>आजचा बाजारभाव</h1><p>पुणे कृषी उत्पन्न बाजार समितीचे प्रकाशित दैनिक बाजारभाव. अधिकृत स्रोत, मूळ तारीख आणि ऐतिहासिक नोंदींसह.</p></section>
<main><h2>मंडी / APMC दर</h2>
<div class="controls">
<select id="category" aria-label="Market category">
<option value="main-fruit">फळे · Fruits</option>
<option value="main-veg">भाजीपाला · Vegetables</option>
<option value="main-gul-bhusar-dhanya">भुसार / धान्य · Grains</option>
<option value="keli-paan">केळी पान · Banana leaves</option>
</select>
<input type="date" id="date" aria-label="Report date" max="<?=date('Y-m-d')?>" value="<?=date('Y-m-d')?>">
<input type="search" id="search" placeholder="शेतमाल शोधा / Search commodity" aria-label="Search commodity">
</div>
<div class="notice" id="status" role="status">बाजारभाव लोड होत आहेत…</div>
<div class="card"><table><thead><tr><th>शेतमाल / Commodity</th><th class="num">किमान / Min</th><th class="num">कमाल / Max</th><th class="num hide-mobile">सरासरी / Modal</th></tr></thead><tbody id="rates"></tbody></table></div>
<footer id="source">Source: Pune APMC. Prices are wholesale quotations, not retail prices. Units must be checked against the original official report.</footer>
</main></div>
<script>
const category=document.querySelector('#category'), date=document.querySelector('#date'), search=document.querySelector('#search'), tbody=document.querySelector('#rates'), status=document.querySelector('#status'), source=document.querySelector('#source');
let rows=[];
function show(){
const term=search.value.toLocaleLowerCase();
const filtered=rows.filter(r=>(r.commodity+' '+(r.variety||'')).toLocaleLowerCase().includes(term));
tbody.replaceChildren();
for(const r of filtered){const tr=document.createElement('tr');
const name=document.createElement('td');name.textContent=r.commodity+(r.variety?' · '+r.variety:'');tr.append(name);
for(const k of ['min','max','modal']){const cell=document.createElement('td');cell.className='num'+(k==='modal'?' modal hide-mobile':'');cell.textContent=r[k]==null?'—':new Intl.NumberFormat('en-IN').format(r[k]);tr.append(cell)}
tbody.append(tr)}
if(!filtered.length){const tr=document.createElement('tr'),td=document.createElement('td');td.colSpan=4;td.textContent='या शोधासाठी नोंदी उपलब्ध नाहीत / No matching rates';tr.append(td);tbody.append(tr)}
}
async function load(){
status.textContent='Loading official mandi rates…';rows=[];show();
try{
const response=await fetch('./api.php?category='+encodeURIComponent(category.value)+'&date='+encodeURIComponent(date.value));
const data=await response.json();if(!response.ok)throw new Error(data.error||'Rates unavailable');
rows=data.rows||[];show();
status.textContent=(data.stale?'⚠ Older verified snapshot (not today’s rate). ':'✓ Official report loaded. ')+'Report: '+data.date+' · '+rows.length+' records';
source.replaceChildren(document.createTextNode('Published by Pune APMC · '+data.date+' · Units as specified in original report · '));
const a=document.createElement('a');a.href=data.source_url;a.target='_blank';a.rel='noopener noreferrer';a.textContent='Open official report';source.append(a);
}catch(e){status.textContent=e.message+' — No sample or fabricated rates displayed.';source.textContent='Pune APMC — official source temporarily unavailable.'}
}
category.addEventListener('change',load);date.addEventListener('change',load);search.addEventListener('input',show);load();
</script></body></html>
