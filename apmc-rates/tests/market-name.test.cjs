'use strict';
/**
 * Execute the actual production marketName function in a sandbox, not a mock
 * reimplementation. Regression: Market column previously rendered commodity
 * because name_en/name_mr/name_hi were read before market_name_*.
 */
const test=require('node:test');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const vm=require('node:vm');
const path=require('node:path');
const root=path.join(__dirname,'..');
const source=fs.readFileSync(path.join(root,'public/build/krushipulse.js'),'utf8');
const unbuilt=fs.readFileSync(path.join(root,'resources/js/krushipulse.js'),'utf8');
const start=source.indexOf('function marketName(r){');
const end=source.indexOf('\nfunction id(r)',start);
assert.ok(start>=0 && end>start,'Cannot find production market-name resolver');
assert.equal(source,unbuilt,'Production and source scripts are identical');
const functionSource=source.slice(start,end);
const config=JSON.parse(fs.readFileSync(path.join(root,'collector/config.json'),'utf8'));
const marketI18n=JSON.parse(fs.readFileSync(path.join(root,'config/i18n.json'),'utf8')).markets;
const directory=Object.values(Object.fromEntries(config.markets.map(m=>[m.market_id,{
  market_id:m.market_id,key:m.market_id,slug:m.market_id,
  name:m.market,name_en:m.market,name_mr:m.market_mr,
  name_hi:marketI18n[m.market]?.hi,state:'Maharashtra',district:'Pune'
}])));
assert.equal(directory.length,5,'Five unique Pune physical markets');
function make(lang,marketRows=directory){
  const scope={
    lang,MR:lang==='mr',HI:lang==='hi',
    dict:{markets:marketI18n},
    marketRows:()=>marketRows,
    t:(mr,en)=>lang==='mr'?mr:en
  };
  return vm.runInNewContext(functionSource+'\nmarketName;',scope);
}
test('all 19 Pune feeds resolve to their physical mandi, not crop names',()=>{
 for(const lang of ['en','mr','hi']){
  const name=make(lang);
  for(const feed of config.markets){
   const record={
     market_id:feed.market_id,
     market_name:feed.market,
     market_name_en:feed.market,
     market_name_mr:feed.market_mr,
     name_en:'Gahu (lokavana)',name_mr:'गहू (लोकवन)',name_hi:'गेहूँ',
     category:'Grains',commodity_key:'gahu-123',average:3700
   };
   const expected=lang==='mr'?feed.market_mr:lang==='hi'?marketI18n[feed.market].hi:feed.market;
   assert.equal(name(record),expected,lang+' / '+feed.slug);
   assert.notEqual(name(record),record.name_en,'Market must never show commodity');
  }
 }
});
test('source record fields cannot override authoritative physical market ID',()=>{
 const en=make('en');
 const mismatched={
   market_id:'main-market-yard',market_name_en:'Gahu (lokavana)',
   name_en:'Gahu (lokavana)',name_mr:'गहू',average:3700,commodity_key:'x'
 };
 assert.equal(en(mismatched),'Main Market Yard');
});
test('national markets use actual source market_name fields when not in directory',()=>{
 for(const lang of ['en','mr','hi']){
  const name=make(lang);
  const id='agmarknet-lasalgaon-123456abcdef';
  const record={market_id:id,market_name:'Lasalgaon',market_name_en:'Lasalgaon',name_en:'Onion',name_mr:'कांदा',commodity_key:'xyz',average:850};
  assert.equal(name(record),'Lasalgaon');
 }
});
test('national directory translations and watchlist can use same resolver',()=>{
 const md=[...directory,{key:'agmarknet-xyz',market_id:'agmarknet-xyz',name_en:'Lasalgaon',name_mr:'लासलगाव',name_hi:'लासलगांव'}];
 const hi=make('hi',md);
 const mr=make('mr',md);
 assert.equal(hi({market_id:'agmarknet-xyz',name_en:'Tomato',name_hi:'टमाटर',average:99}),'लासलगांव');
 assert.equal(mr({market_id:'agmarknet-xyz',name_en:'Tomato',name_mr:'टोमॅटो',average:99}),'लासलगाव');
});
test('a missing market is not relabeled with the commodity name',()=>{
 for(const lang of ['en','mr','hi']){
  const name=make(lang,[]);
  const result=name({name_en:'Raw Banana',name_mr:'कच्ची केळी',name_hi:'कच्चा केला',commodity_key:'banana',average:950});
  assert.ok(result==='Market unavailable'||result==='मंडी उपलब्ध नाही',result);
  assert.notEqual(result,'Raw Banana');
 }
});
test('market directory entries are not treated as commodity records',()=>{
 for(const lang of ['en','mr','hi']){
  const name=make(lang);
  const m=directory.find(x=>x.market_id==='moshi');
  assert.equal(name(m),lang==='mr'?'मोशी':lang==='hi'?'मोशी':'Moshi');
 }
});
test('table, watchlist, search and market links use marketName',()=>{
 assert.match(source,/const marketTitle=marketName\(r\)/);
 assert.match(source,/r\?marketName\(r\):/);
 assert.match(source,/selected\?marketName\(selected\)/);
 assert.match(source,/window\.KrushiPulseMarketNameFor=.*marketName\(m\)/);
 assert.match(source,/function matching\(\)[\s\S]*marketName\(r\)/);
 assert.doesNotMatch(functionSource,/r\?\.\['name_'\+lang\]/);
});
