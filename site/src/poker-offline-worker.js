/* The build substitutes this identifier after hashing the complete pinned asset list. */
const build='__POKER_BUILD__';
const site=new URL('../../',self.location.href);
const manifestUrl=new URL('offline-manifest.json',self.location.href);
const prefix='phonics-poker-offline-v1:'+site.href+':';
const cacheName=prefix+build;
const marker=new URL('offline-complete',self.registration.scope).href;
let allowed;

const digest=async bytes=>[...new Uint8Array(await crypto.subtle.digest('SHA-256',bytes))]
	.map(value=>value.toString(16).padStart(2,'0')).join('');
const parse=manifest=>{
	if(manifest.schema!==1 || manifest.build!==build || !Array.isArray(manifest.files) || manifest.files.length>2000)
		throw Error('Invalid offline manifest');
	const urls=new Map;
	for(const file of manifest.files)
	{
		if(typeof file.path!=='string' || !/^[a-zA-Z0-9_./-]+$/.test(file.path) || file.path.startsWith('/')
			|| file.path.split('/').includes('..') || !/^[a-f0-9]{64}$/.test(file.sha256)
			|| !Number.isSafeInteger(file.bytes) || file.bytes<0 || file.bytes>268435456)
			throw Error('Invalid offline asset');
		const url=new URL(file.path,site).href;
		if(urls.has(url)) throw Error('Duplicate offline asset');
		urls.set(url,file);
	}
	return urls;
};
async function download()
{
	const response=await fetch(manifestUrl,{cache:'no-store'});
	if(!response.ok) throw Error('Offline manifest unavailable');
	const manifest=await response.json(); allowed=parse(manifest);
	const cache=await caches.open(cacheName);
	// An already completed identical build can be reused without touching its contents.
	const hadMarker=Boolean(await cache.match(marker));
	if(hadMarker)
	{
		const keys=new Set((await cache.keys()).map(request=>request.url));
		if([...allowed.keys()].every(url=>keys.has(url))) return;
	}
	try
	{
		let total=0;
		for(const file of manifest.files)
		{
			total+=file.bytes;
			if(total>536870912) throw Error('Offline package too large');
			const url=new URL(file.path,site);
			const asset=await fetch(url,{cache:'no-store',credentials:'same-origin'});
			if(!asset.ok || asset.type==='opaque') throw Error('Offline asset unavailable');
			const bytes=await asset.clone().arrayBuffer();
			if(bytes.byteLength!==file.bytes || await digest(bytes)!==file.sha256) throw Error('Offline integrity mismatch');
			await cache.put(url,asset);
		}
		await cache.put(marker,new Response(JSON.stringify(manifest),{headers:{'Content-Type':'application/json'}}));
	}
	catch(error) { if(!hadMarker) await caches.delete(cacheName); throw error; }
	// Do not skipWaiting: an open hand keeps its current runtime and application build.
}
self.addEventListener('install',event=>event.waitUntil(download()));
self.addEventListener('message',event=>{
	if(event.data?.type!=='poker-offline-check' || !event.ports?.[0]) return;
	event.waitUntil((async()=>{
		try
		{
			const cache=await caches.open(cacheName), complete=await cache.match(marker);
			const manifest=complete ? await complete.json() : null;
			const urls=manifest ? parse(manifest) : new Map;
			const keys=new Set((await cache.keys()).map(request=>request.url));
			if(!urls.size || ![...urls.keys()].every(url=>keys.has(url))) await download();
			event.ports[0].postMessage({ready:true});
		}
		catch { event.ports[0].postMessage({ready:false}); }
	})());
});
self.addEventListener('activate',event=>event.waitUntil((async()=>{
	const cache=await caches.open(cacheName);
	const complete=await cache.match(marker);
	if(!complete) throw Error('Incomplete offline installation');
	allowed=parse(await complete.json());
	// This worker is registered only for the full poker route; activation waits for its old clients.
	for(const name of await caches.keys()) if(name.startsWith(prefix)&&name!==cacheName) await caches.delete(name);
	await self.clients.claim();
})()));
self.addEventListener('fetch',event=>{
	if(event.request.method!=='GET') return;
	const url=new URL(event.request.url);
	if(url.origin!==site.origin) return;
	event.respondWith((async()=>{
		const cache=await caches.open(cacheName);
		if(!allowed)
		{
			const complete=await cache.match(marker);
			if(complete) allowed=parse(await complete.json());
		}
		// Every controlled page uses this complete build, including cache-busting asset requests.
		url.search='';
		const key=url.pathname.endsWith('/') ? new URL('index.html',url).href : url.href;
		if(allowed?.has(key))
		{
			const cached=await cache.match(key);
			if(cached) return cached;
			const asset=await fetch(key,{cache:'no-store'}),file=allowed.get(key);
			if(!asset.ok) throw Error('Offline asset unavailable');
			const bytes=await asset.clone().arrayBuffer();
			if(bytes.byteLength!==file.bytes || await digest(bytes)!==file.sha256) throw Error('Offline asset version mismatch');
			await cache.put(key,asset.clone());
			return asset;
		}
		return fetch(event.request);
	})());
});
