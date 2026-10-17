/* KrushiPulse PWA: cache public shell only. NEVER cache APIs or claim old rates as live. */
const CACHE='krushipulse-shell-v2.0.0';
const BASE=new URL(self.registration.scope);
const content=path=>new URL(path,BASE).toString();
const SHELL=['mr/','en/','hi/','build/krushipulse.css','build/krushipulse.js','build/commodity-icons.js','build/nearest-mandi.js','build/intelligence.js','build/phase4.js','build/offline.js','app.webmanifest','icons/krushipulse.svg'];
self.addEventListener('install',event=>{
 event.waitUntil(caches.open(CACHE).then(cache=>Promise.allSettled(SHELL.map(p=>cache.add(new Request(content(p),{cache:'reload'}))))).then(()=>self.skipWaiting()));
});
self.addEventListener('activate',event=>{
 event.waitUntil(Promise.all([caches.keys().then(keys=>Promise.all(keys.filter(k=>k.startsWith('krushipulse-shell-')&&k!==CACHE).map(k=>caches.delete(k)))),self.clients.claim()]));
});
self.addEventListener('fetch',event=>{
 const request=event.request;if(request.method!=='GET')return;
 const url=new URL(request.url);
 if(url.origin!==self.location.origin||!url.pathname.startsWith(BASE.pathname))return;
 const rel=url.pathname.slice(BASE.pathname.length);
 if(rel.startsWith('api/')||rel.startsWith('admin/')||rel==='api.php'||rel==='index.php'||url.searchParams.has('api')||url.searchParams.has('view')||url.searchParams.has('seo'))return;
 const isShell=rel===''||/^(en|mr|hi)(\/mandis(?:\/[a-z0-9-]+)?)?\/?$/.test(rel);
 const isStatic=rel.startsWith('build/')||rel.startsWith('icons/')||rel==='app.webmanifest';
 if(isShell){
  event.respondWith(fetch(request).then(response=>{
   if(response.ok&&response.type!=='opaque'&&response.headers.get('Content-Type')?.includes('text/html')){
    const copy=response.clone();event.waitUntil(caches.open(CACHE).then(cache=>cache.put(request,copy)));
   }
   return response;
  }).catch(async()=>{
   const cache=await caches.open(CACHE);
   return await cache.match(request)||await cache.match(content('mr/'))||Response.error();
  }));
 }else if(isStatic){
  event.respondWith(caches.match(request,{ignoreSearch:true}).then(cached=>cached||fetch(request).then(response=>{
   if(response.ok)event.waitUntil(caches.open(CACHE).then(cache=>cache.put(request,response.clone())));
   return response;
  })));
 }
});
