import {readFileSync,writeFileSync,readdirSync} from 'node:fs'
import {createHash} from 'node:crypto'
const manifest=readFileSync('public/build/manifest.json','utf8')
const assets=readdirSync('public/build/assets').map(f=>`/build/assets/${f}`)
const cache='pos-shell-'+createHash('sha256').update(manifest).digest('hex').slice(0,16)
writeFileSync('public/sw.js', `const CACHE=${JSON.stringify(cache)},ASSETS=${JSON.stringify(['/',...assets])};
self.addEventListener('install',event=>event.waitUntil(caches.open(CACHE).then(cache=>cache.addAll(ASSETS))));
self.addEventListener('activate',event=>event.waitUntil(caches.keys().then(keys=>Promise.all(keys.filter(key=>key.startsWith('pos-shell-')&&key!==CACHE).map(key=>caches.delete(key)))).then(()=>self.clients.claim())));
self.addEventListener('fetch',event=>{
 const url=new URL(event.request.url);
 if(event.request.method!=='GET'||url.origin!==self.location.origin)return;
 if(url.pathname==='/'&&event.request.mode==='navigate'){
  event.respondWith(fetch(event.request).catch(()=>caches.open(CACHE).then(cache=>cache.match('/'))));return;
 }
 if(ASSETS.includes(url.pathname)&&url.pathname!=='/')event.respondWith(caches.open(CACHE).then(cache=>cache.match(event.request)).then(cached=>cached||fetch(event.request)));
});
`)
