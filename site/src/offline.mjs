/** Install the complete poker package within this game's own service-worker scope. */
export async function enableOffline(manifestUrl, host)
{
	const status=document.createElement('p');
	status.className='offline-status'; status.setAttribute('role','status');
	status.textContent='Preparing offline play…'; host?.append(status);
	const set=(state,text)=>{document.documentElement.dataset.offlineState=state;status.textContent=text;};
	if(!isSecureContext || !('serviceWorker' in navigator))
	{
		set('unavailable','Offline downloads require a supported browser on a secure connection.');
		return ()=>status.remove();
	}
	let closed=false;
	const abort=new AbortController;
	const check=worker=>{
		const channel=new MessageChannel;
		const timer=setTimeout(()=>{channel.port1.close();if(!closed)set('failed','Offline download is taking longer than expected. Reopen while connected to retry.');},120000);
		channel.port1.onmessage=event=>{
			clearTimeout(timer);channel.port1.close();
			if(!closed) set(event.data?.ready?'ready':'failed',event.data?.ready?'Available offline':'Offline files need to be downloaded again. Reopen the game while connected.');
		};
		abort.signal.addEventListener('abort',()=>{clearTimeout(timer);channel.port1.close();},{once:true});
		worker.postMessage({type:'poker-offline-check'},[channel.port2]);
	};
	const watch=worker=>{
		if(!worker) return;
		const update=()=>{
			if(closed) return;
			if(worker.state==='activated') check(worker);
			else if(worker.state==='installed') set('update-ready','Update downloaded. It will be ready after you close the game.');
			else if(worker.state==='redundant') set('failed','Offline download did not finish. Your current game is still available.');
		};
		worker.addEventListener('statechange',update,{signal:abort.signal}); update();
	};
	try
	{
		const url=new URL(manifestUrl,location.href);
		const scope=new URL('./',url).pathname;
		let registration=await navigator.serviceWorker.getRegistration(new URL('./',url));
		if(registration?.active && registration.scope===new URL('./',url).href)
		{
			void registration.update().catch(()=>{});
		}
		else registration=await navigator.serviceWorker.register(new URL('offline-worker.js',url),{scope});
		if(closed) return ()=>{};
		if(registration.active) check(registration.active);
		watch(registration.installing??registration.waiting);
		registration.addEventListener('updatefound',()=>watch(registration.installing),{signal:abort.signal});
	}
	catch
	{
		if(!closed) set('failed','Offline download did not finish. Reopen the game while connected to try again.');
	}
	return ()=>{closed=true;abort.abort();status.remove();};
}
