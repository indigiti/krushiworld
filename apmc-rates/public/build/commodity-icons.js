/* First-party, deterministic crop emoji resolver (EN + Marathi + common Hindi labels).
 * Source-verified emoji from the APMC dictionary takes priority; missing national
 * AGMARKNET crops use specific names, then a category fallback. No network calls.
 */
(function (root, factory) {
  const resolve = factory();
  if (typeof module === 'object' && module.exports) module.exports = resolve;
  if (root) root.KrushiPulseCommodityIcon = resolve;
})(typeof window !== 'undefined' ? window : undefined, function () {
  'use strict';
  const explicit = [
    [/\b(?:spring onions?|green onions?|scallions?)\b|कांदापात/u, '🌿'],
    [/\b(?:pineapples?)\b|अननस/u, '🍍'],
    [/\b(?:custard apples?|sitaphal|sita phal)\b|सीताफळ|सिताफळ/u, '🍈'],
    [/\b(?:bell peppers?|capsicum|sweet peppers?)\b|ढोबळी/u, '🫑'],
    [/\b(?:sweet corns?|maize|corn)\b|मका/u, '🌽'],
    [/\b(?:groundnuts?|peanuts?)\b|भुईमूग|भुईमुग|भुशेंगा/u, '🥜'],
    [/\b(?:ginger)\b|आले|अदरक/u, '🫚'],
    [/\b(?:garlic)\b|लसूण|लहसुन/u, '🧄'],
    [/\b(?:onions?)\b|कांदा|प्याज/u, '🧅'],
    [/\b(?:tomatoes?|tomato)\b|टोमॅटो|टमाटर/u, '🍅'],
    [/\b(?:potatoes?|potato)\b|बटाटा|आलू/u, '🥔'],
    [/\b(?:chillies?|chillis?|chilies?|chilis?|hot peppers?)\b|मिरची|मिर्च/u, '🌶️'],
    [/\b(?:brinjals?|eggplants?|aubergines?)\b|वांगी|बैंगन/u, '🍆'],
    [/\b(?:carrots?)\b|गाजर/u, '🥕'],
    [/\b(?:beetroots?|beets?)\b|बीट/u, '🫜'],
    [/\b(?:cauliflowers?|broccoli)\b|फुलकोबी|फ्लॉवर|फलॉवर/u, '🥦'],
    [/\b(?:cabbages?)\b|कोबी/u, '🥬'],
    [/\b(?:spinach|palak)\b|पालक/u, '🥬'],
    [/\b(?:cucumbers?|ridge gourds?|snake gourds?|bitter gourds?)\b|काकडी|दोडका|कारली/u, '🥒'],
    [/\b(?:pumpkins?|ash gourds?)\b|भोपळा|कोहळा/u, '🎃'],
    [/\b(?:peas?|green peas?|garden peas?)\b|मटार|मटर/u, '🫛'],
    [/\b(?:beans?|lentils?|chickpeas?|pigeon peas?|green grams?|black grams?|soybeans?|pulses?|urad|moong|tur|toor|gram dal)\b|हरभरा|तूर|मूग|उडीद|मसूर|गवार/u, '🫘'],
    [/\b(?:coconuts?)\b|नारळ/u, '🥥'],
    [/\b(?:mangoes?|mangos?|raw mango)\b|आंबा|कैरी/u, '🥭'],
    [/\b(?:bananas?|plantains?)\b|केळी/u, '🍌'],
    [/\b(?:grapes?)\b|द्राक्ष/u, '🍇'],
    [/\b(?:watermelons?)\b|कलिंगड/u, '🍉'],
    [/\b(?:lemons?|limes?)\b|लिंबू/u, '🍋'],
    [/\b(?:oranges?|sweet limes?|mosambi|mandarins?)\b|संत्रा|मोसंबी/u, '🍊'],
    [/\b(?:apples?)\b|सफरचंद/u, '🍎'],
    [/\b(?:strawberries?)\b|स्ट्रॉबेरी/u, '🍓'],
    [/\b(?:blueberries?|blackberries?)\b|ब्लूबेरी/u, '🫐'],
    [/\b(?:cherries?)\b|चेरी/u, '🍒'],
    [/\b(?:peaches?)\b|पीच/u, '🍑'],
    [/\b(?:pears?)\b|नाशपाती/u, '🍐'],
    [/\b(?:kiwis?)\b|किवी/u, '🥝'],
    [/\b(?:avocados?)\b|अ‍ॅव्होकॅडो/u, '🥑'],
    [/\b(?:papayas?|guavas?|muskmelons?|cantaloupes?)\b|पपई|पेरू|खरबूज/u, '🍈'],
    [/\b(?:roses?)\b|गुलाब/u, '🌹'],
    [/\b(?:sunflowers?)\b|सूर्यफूल/u, '🌻'],
    [/\b(?:marigolds?|chrysanthemums?)\b|झेंडू|शेवंती/u, '🌼'],
    [/\b(?:jasmine|lotus|asters?|gladiolus|orchids?)\b|मोगरा|कमळ|अॅस्टर/u, '🌸'],
    [/\b(?:wheat|rice|paddy|barley|sorghum|jowar|bajra|millets?|ragi|cereals?|grains?)\b|गहू|तांदूळ|तांदुळ|ज्वारी|बाजरी|नाचणी/u, '🌾'],
    [/\b(?:sesame|mustard seed|rapeseed|safflower|oilseeds?)\b|तीळ|मोहरी|करडई/u, '🌻'],
    [/\b(?:cashews?|almonds?|walnuts?|pistachios?)\b|काजू|बदाम|अक्रोड/u, '🥜'],
    [/\b(?:mint|coriander|cilantro|fenugreek|dill|curry leaves?|leafy greens?|herbs?)\b|कोथिंबीर|मेथी|पुदिना|शेपू|कडीपत्ता/u, '🌿'],
    [/\b(?:coffee)\b|कॉफी/u, '☕'],
    [/\b(?:tea leaves?|tea)\b|चहा/u, '🍃'],
    [/\b(?:cotton)\b|कापूस/u, '🌿'],
  ];

  const categoryIcons = [
    [/leafy|greens|पालेभाज्या|पाले/u, '🌿'],
    [/dry fruit|nut|सुकामेवा/u, '🥜'],
    [/flower|blossom|फुले|फूल/u, '🌸'],
    [/fruit|फळे|फल/u, '🍎'],
    [/onion.*potato|vegetable|भाजी|तरकारी/u, '🥬'],
    [/grain|cereal|धान्य|तृणधान्य/u, '🌾'],
    [/pulse|dal|legume|डाळ|कडधान्य/u, '🫘'],
    [/spice|मसाल/u, '🌶️'],
    [/oilseed|तेलबिया/u, '🌻'],
    [/cotton|कापूस/u, '🌿'],
  ];

  return function cropEmoji(record) {
    if (!record || typeof record !== 'object') return '🌱';
    // Only short standalone symbols are accepted from controlled source records.
    const sourceEmoji = typeof record.emoji === 'string' ? record.emoji.trim() : '';
    if (sourceEmoji && sourceEmoji !== '◦' && sourceEmoji !== '•' &&
        Array.from(sourceEmoji).length <= 8 && /[\p{Extended_Pictographic}]/u.test(sourceEmoji)) {
      return sourceEmoji;
    }
    const name = [record.name_en, record.name_mr, record.source_name]
      .filter(x => typeof x === 'string').join(' ').toLocaleLowerCase('en-IN');
    for (const [pattern, icon] of explicit) {
      if (pattern.test(name)) return icon;
    }
    const category = [record.category, record.category_en, record.category_mr]
      .filter(x => typeof x === 'string').join(' ').toLocaleLowerCase('en-IN');
    for (const [pattern, icon] of categoryIcons) {
      if (pattern.test(category)) return icon;
    }
    return '🌱';
  };
});
