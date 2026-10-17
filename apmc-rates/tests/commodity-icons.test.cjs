'use strict';
const test=require('node:test');
const assert=require('node:assert/strict');
const path=require('node:path');
const fs=require('node:fs');
const icon=require('../public/build/commodity-icons.js');

test('recognizable national commodity emoji names',()=>{
  const cases={
    Onion:'🧅',Tomato:'🍅',Potato:'🥔',Garlic:'🧄',Ginger:'🫚',
    'Spring Onion':'🌿', 'Bell Pepper':'🫑', Brinjal:'🍆',
    Carrot:'🥕',Cucumber:'🥒', 'Green Chilli':'🌶️',
    Peas:'🫛', Groundnut:'🥜', 'Green Gram':'🫘',
    Maize:'🌽',Wheat:'🌾', 'Basmati Rice':'🌾',
    Mango:'🥭', Banana:'🍌', 'Raw Banana':'🍌',
    Apple:'🍎', 'Custard Apple':'🍈', Pineapple:'🍍',
    Orange:'🍊', 'Sweet Lime':'🍊', Lemon:'🍋',
    Grapes:'🍇',Watermelon:'🍉', Papaya:'🍈',
    Rose:'🌹',Marigold:'🌼',Jasmine:'🌸',
    'Sunflower Seeds':'🌻', Coconut:'🥥', Cotton:'🌿',
  };
  for(const [name,expected] of Object.entries(cases)){
    assert.equal(icon({name_en:name}),expected,name);
  }
});
test('Marathi names map correctly in raw source records',()=>{
  for(const [name,expected] of Object.entries({
    'टोमॅटो':'🍅','कांदा':'🧅','बटाटा':'🥔','लसूण':'🧄',
    'ढोबळी मिरची':'🫑','केळी':'🍌','गहू':'🌾',
    'द्राक्ष':'🍇','पालक':'🥬','झेंडू':'🌼',
  })){
    assert.equal(icon({name_mr:name}),expected,name);
  }
});
test('existing verified source icons override approximations',()=>{
  assert.equal(icon({name_en:'Unknown',emoji:'🍎'}),'🍎');
  assert.equal(icon({name_en:'Unknown',emoji:'◦'}),'🌱');
  assert.equal(icon({name_en:'Unknown',emoji:'not an emoji'}),'🌱');
  assert.equal(icon({name_en:'Spring Onion',emoji:'🌿'}),'🌿');
});
test('category fallbacks never imply an exact crop',()=>{
  assert.equal(icon({name_en:'Unmapped fruit',category:'Fruits'}),'🍎');
  assert.equal(icon({name_en:'Unknown item',category:'Dry Fruits'}),'🥜');
  assert.equal(icon({name_en:'Unknown item',category:'Leafy Vegetables'}),'🌿');
  assert.equal(icon({name_en:'Unknown item',category:'Flowers'}),'🌸');
  assert.equal(icon({name_en:'Unknown item',category:'Pulses'}),'🫘');
  assert.equal(icon({name_en:'Unknown item',category:'Grains'}),'🌾');
  assert.equal(icon({name_en:'Unknown item',category:'Agricultural produce'}),'🌱');
  assert.equal(icon(null),'🌱');
});
test('104 known APMC crops preserve their verified emoji',()=>{
  const json=JSON.parse(fs.readFileSync(path.join(__dirname,'../collector/commodity_map.json'),'utf8'));
  assert.ok(Object.keys(json).length>=100);
  for(const [mr,item] of Object.entries(json)){
    assert.equal(icon({name_en:item.en,name_mr:mr,emoji:item.emoji}),item.emoji,mr);
  }
  assert.equal(json['मटार'].emoji,'🫛');
});
test('frontend integrates icons in table, favorites and crop picker',()=>{
  const js=fs.readFileSync(path.join(__dirname,'../public/build/krushipulse.js'),'utf8');
  const html=fs.readFileSync(path.join(__dirname,'../app/views/home.php'),'utf8');
  assert.match(js,/KrushiPulseCommodityIcon/);
  assert.match(js,/kp-watch-row-icon/);
  assert.match(js,/kp-crop-emoji/);
  assert.match(js,/kp-choice-emoji/);
  assert.match(js,/aria-hidden/);
  assert.match(html,/commodity-icons\.js/);
  assert.ok(html.indexOf('commodity-icons.js')<html.indexOf('krushipulse.js'));
});
