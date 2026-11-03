/**
 * Presentation-only contact filtering; zero means bypass, including every hard impact.
 * Hertz contact duration varies with speed to the -1/5 power. Keep that shallow spectral change above the
 * material's floor so even the softest collisions retain their recorded character.
 */
export function impactLowpassCutoff(strength, sampleRate, material)
{
	strength=Math.max(0,Math.min(1,Number(strength)||0));
	if(strength>=.65||!Number.isFinite(sampleRate)||sampleRate<=0)return 0;
	const upper=Math.min(18000,sampleRate*.42),floorStrength=material==='rail'?.01:.02;
	return upper*(Math.max(strength,floorStrength)/.65)**.2;
}

/**
 * Pick the recorded pocket knock for one strike inside a pocket: 1 is the strongest hit and 3 the weakest.
 * The three hits are centred on geometric strengths, and a little jitter lets a strike near a boundary use
 * either neighbour, so a ball knocking around the liner never repeats one sample mechanically.
 */
export function pocketHit(strength, random=Math.random)
{
	strength=Math.max(1e-4,Math.min(1,Number(strength)||0));
	const position=Math.log(.45/strength)/Math.log(.45/.17),jitter=(Math.max(0,Math.min(1,Number(random())||0))-.5)*.6;
	return `pocket${Math.max(0,Math.min(2,Math.round(position+jitter)))+1}`;
}

/** Canvas lifecycle, diagnostics, sound, and fullscreen bridge for the native PHP/SDL game. */
export function activate({module, canvas, session})
{
	// The browser module runs before PHP creates its SDL window. Claim the shared context with
	// antialiasing enabled so Emscripten/SDL reuses a multisampled default framebuffer.
	if(!canvas.getContext('webgl2',{alpha:false,antialias:true,depth:true,stencil:false}))
		throw new Error('PHP Billiards requires WebGL2');
	const originalStyle=canvas.getAttribute('style'),originalTabIndex=canvas.getAttribute('tabindex');
	const originalLabel=canvas.getAttribute('aria-label'),parent=canvas.parentElement;
	const linkedGame=new URLSearchParams(location.hash.slice(1)).has('phonics-game');
	let viewport='[1280,720,1]',latest=null,debugCamera=null,debugValues={};
	let debugActions=linkedGame?['online-play']:[];
	let network=[],networkAcks=[],lobbyOpening=false,lobbyVisible=false,networkWrites=Promise.resolve(),handshake=null;
	let closed=false,cursor='';
	// Direct applications receive no shared services, so mute persists through guarded localStorage.
	const muteKey='php-billiards.muted.v1';
	try{if(localStorage.getItem(muteKey)==='1')debugActions.push('mute');}catch{}
	canvas.style.cssText='display:block;width:100%;height:100%;max-width:none;max-height:none;margin:0;border:0;border-radius:0;touch-action:none;outline:none;background:#020403';
	canvas.tabIndex=Math.max(0,canvas.tabIndex);
	canvas.setAttribute('aria-label','PHP Billiards. Drag on the table to aim, Space to shoot, Escape for the menu, question mark for all controls.');

	const measure=()=>{
		// The lobby moves and letterboxes the canvas. Measure the displayed canvas
		// itself so PHP rendering, SDL pointer coordinates, and the native HUD all
		// share the same local pixel space after responsive layout changes.
		const box=canvas.getBoundingClientRect(),dpr=Math.min(2,devicePixelRatio||1);
		// Opening the lobby briefly parks the canvas in a hidden stage. Retain the
		// last valid framebuffer until a joined table makes that stage measurable.
		if(box.width < 1 || box.height < 1)return;
		viewport=JSON.stringify([Math.max(1,Math.round(box.width*dpr)),Math.max(1,Math.round(box.height*dpr)),dpr]);
	};
	const resize=new ResizeObserver(measure);resize.observe(canvas);measure();

	// The recorded samples live inside the PHAR, so PHP hands their bytes over once through billiardsSamples().
	// They decode after the first gesture unlocks Web Audio; playback adds slight detune, filtering, and a voice
	// cap so a break sounds like ivory rather than a machine-gun of identical clicks.
	const encoded=new Map,decoded=new Map,peaks=new Map,lastPlayed=new Map;
	let audio=null,voices=0,played=0,decoding=null,audioError=null;
	const decodeSamples=()=>decoding??=Promise.all([...encoded].map(async([name,base64])=>{
		const bytes=Uint8Array.from(atob(base64),character=>character.charCodeAt(0));
		const buffer=await audio.decodeAudioData(bytes.buffer);
		let peak=0;
		for(let channel=0;channel<buffer.numberOfChannels;channel++)for(const value of buffer.getChannelData(channel))peak=Math.max(peak,Math.abs(value));
		peaks.set(name,peak);decoded.set(name,buffer);
	})).catch(error=>{audioError=String(error?.message??error);console.error('Billiards samples failed to decode',error);});
	const unlockAudio=()=>{
		if(closed||typeof AudioContext==='undefined')return;
		try
		{
			audio??=new AudioContext;
			if(audio.state==='suspended')void audio.resume().catch(()=>{});
			if(encoded.size)void decodeSamples();
		}
		catch(error){audioError=String(error?.message??error);}
	};
	module.billiardsSamples=json=>{
		for(const [name,base64] of Object.entries(JSON.parse(json)))if(typeof base64==='string')encoded.set(name,base64);
		if(audio)void decodeSamples();
	};
	const play=(name,{gain=1,rate=1,lowpass=0,lowpassQ=null}={})=>{
		const buffer=decoded.get(name),context=audio;
		if(latest?.muted||!buffer||!context||context.state!=='running'||voices>=8||gain<=.005)return;
		const now=performance.now();
		if(now-(lastPlayed.get(name)??-Infinity)<12)return;
		lastPlayed.set(name,now);
		const source=context.createBufferSource(),volume=context.createGain();
		source.buffer=buffer;source.playbackRate.value=rate*(.96+Math.random()*.08);
		volume.gain.value=Math.min(1,gain);
		let tail=volume;
		if(lowpass>0)
		{
			const filter=context.createBiquadFilter();filter.type='lowpass';filter.frequency.value=lowpass;
			if(lowpassQ!==null)filter.Q.value=lowpassQ;
			volume.connect(filter);tail=filter;
		}
		source.connect(volume);tail.connect(context.destination);
		voices++;played++;source.onended=()=>{voices--;source.disconnect();volume.disconnect();if(tail!==volume)tail.disconnect();};
		source.start();
	};
	const sound=event=>{
		const strength=Math.max(0,Math.min(1,Number(event.strength??0)||0));
		if(event.type==='impact')play('clack',{
			gain:.12+.88*strength**.8,rate:.94+.12*strength
			,lowpass:impactLowpassCutoff(strength,audio?.sampleRate,'ball'),lowpassQ:.6
		});
		if(event.type==='shot')play('impact',{gain:.2+.55*Number(event.power??.5),rate:.82,lowpass:2600});
		// Rails use their own recording on the ball-ball curve: its peak is matched to the clack's, so a full-strength
		// rail hit peaks exactly as loud as a full-strength ball hit and both scale with impact speed the same way.
		if(event.type==='cushion')play('rail',{
			gain:(.12+.88*strength**.8)*(peaks.get('clack')??1)/Math.max(peaks.get('rail')??1,1e-4)
			,lowpass:impactLowpassCutoff(strength,audio?.sampleRate,'rail'),lowpassQ:.6
		});
		// pocket-drop is split into three knocks against the pocket's walls: each strike on the rim, back wall or liner
		// plays one on the ball-ball curve, peak-matched like the rail. pocket-sink is the ball reaching the bottom, so a
		// ball that falls in without touching a wall is heard only there.
		if(event.type==='pocket-rattle')
		{
			const hit=pocketHit(strength);
			play(hit,{gain:(.12+.88*strength**.8)*(peaks.get('clack')??1)/Math.max(peaks.get(hit)??1,1e-4)});
		}
		if(event.type==='pocket')play('sink',{gain:.32,lowpass:3200});
		if(event.type==='pocket-reject')play('clack',{gain:.22,rate:.85});
		if(event.type==='place')play('clack',{gain:.12,rate:.6,lowpass:1400});
		if(event.type==='denied')play('impact',{gain:.18,rate:.55,lowpass:700});
	};
	// Listen in the capture phase on the window so SDL's own canvas handlers cannot swallow the unlocking gesture.
	const gesture=()=>unlockAudio();
	const gestures=['pointerdown','keydown','touchend'];
	for(const type of gestures)globalThis.addEventListener?.(type,gesture,{capture:true});
	// Right-drag orbits the camera, so the browser menu must not open over the table.
	const contextMenu=event=>event.preventDefault();
	canvas.addEventListener('contextmenu',contextMenu);

	module.billiardsViewport=()=>viewport;
	module.billiardsInput=()=>{
		const result={values:debugValues,actions:debugActions,network,networkAcks};
		debugValues={};debugActions=[];network=[];networkAcks=[];
		if(debugCamera){result.debugCamera=debugCamera;debugCamera=null;}
		return JSON.stringify(result);
	};
	module.billiardsStatus=json=>{
		const muted=latest?.muted;
		latest=JSON.parse(json);canvas.dataset.phpBilliards=json;
		if(muted!==undefined&&muted!==latest.muted)try{localStorage.setItem(muteKey,latest.muted?'1':'0');}catch{}
		const ui=latest.ui??{},aiming=latest.started&&!latest.overlay&&latest.phase!=='moving';
		const nextCursor=ui.drag==='orbit'?'grabbing':ui.hover?'pointer':ui.drag==='aim'||aiming?'crosshair':'default';
		if(nextCursor!==cursor){cursor=nextCursor;canvas.style.cursor=cursor;}
		canvas.dataset.placing=String(latest.phase==='placing');
		for(const event of latest.events??[])sound(event);
		if(latest.onlineRequested&&!lobbyOpening&&!lobbyVisible)
		{
			lobbyOpening=true;lobbyVisible=true;
			session.lobby.open().catch(()=>{}).finally(()=>{lobbyOpening=false;});
		}
		for(const message of latest.network?.outbox??[])queueNetworkWrite(message);
	};
	module.billiardsFullscreen=()=>{
		const active=document.fullscreenElement===canvas;
		(active?document.exitFullscreen():canvas.requestFullscreen()).catch(()=>{});
	};

	const lobbyPort=session.lobby.attachGame({
		start:detail=>{
			handshake=detail.handshake?structuredClone(detail.handshake):null;
			console.warn('Billiards deterministic seed handshake',handshake);
			const {handshake:discard,...gameDetail}=detail;
			void discard;
			network.push({type:'start',detail:gameDetail});
		}
		,action:detail=>network.push({type:'action',detail})
		,confirmed:detail=>{
			console.log('Billiards confirmation delivered to PHP',{
				sequence:detail.sequence,hash:detail.hash,nextActor:detail.nextActor,outcome:detail.outcome
				,checkpointLength:typeof detail.checkpoint==='string'?detail.checkpoint.length:0
			});
			network.push({type:'confirmed',detail});
		}
		,stop:detail=>network.push({type:'stop',detail})
	});
	const writing=new Set,retired=new Set,retries=new Map,retryAt=new Map,retryTimers=new Map;
	const clearRetry=id=>{
		const timer=retryTimers.get(id);if(timer!==undefined)clearTimeout(timer);
		retryTimers.delete(id);retryAt.delete(id);
	};
	const terminalWriteError=message=>[
		'It is not your turn'
		,'Local state differs from the confirmed turn state'
		,'The game supplied an invalid quantized action'
		,'No matching action is settling'
		,'Acting player must include a checkpoint'
		,'This browser is not seated at a table'
	].includes(message);
	const queueNetworkWrite=message=>{
		if(writing.has(message.id)||retired.has(message.id)||(retryAt.get(message.id)??0)>Date.now())return;
		writing.add(message.id);
		networkWrites=networkWrites.then(async()=>{
			if(message.type==='action-request')await lobbyPort.submitAction(message.action,message.preHash);
			else if(message.type==='ready')await lobbyPort.reportReady(message.hash);
			else if(message.type==='settled')
			{
				console.log('Billiards local settlement submitted to room',{
					sequence:message.sequence,hash:message.hash,nextActor:message.nextActor,outcome:message.outcome??null
					,checkpointLength:typeof message.checkpoint==='string'?message.checkpoint.length:0
				});
				await lobbyPort.reportSettled({
					sequence:message.sequence,hash:message.hash,nextActor:message.nextActor
					,outcome:message.outcome??null,checkpoint:message.checkpoint??null
				});
			}
			else if(message.type==='fatal')await lobbyPort.resign(message.message??'local deterministic failure');
			else if(message.type==='leave')await lobbyPort.leave();
			retired.add(message.id);retries.delete(message.id);clearRetry(message.id);
			networkAcks.push(message.id);
		}).catch(error=>{
			const description=error instanceof Error?error.message:String(error);
			if(terminalWriteError(description))
			{
				retired.add(message.id);retries.delete(message.id);clearRetry(message.id);
				networkAcks.push(message.id);
				network.push({type:'write-rejected',detail:{messageType:message.type,message:description}});
				return;
			}
			const attempts=(retries.get(message.id)??0)+1;
			const delay=Math.min(30000,500*2**Math.min(attempts-1,6));
			retries.set(message.id,attempts);retryAt.set(message.id,Date.now()+delay);
			clearTimeout(retryTimers.get(message.id));
			retryTimers.set(message.id,setTimeout(()=>{
				retryTimers.delete(message.id);retryAt.delete(message.id);queueNetworkWrite(message);
			},delay));
			if(attempts===1)console.error('Billiards room write failed; retrying',error);
		}).finally(()=>writing.delete(message.id));
	};
	const lobbyClosed=()=>{lobbyVisible=false;network.push({type:'online-close',detail:{}});};
	session.lobby.protocol.addEventListener('closed',lobbyClosed);

	const debug={
		get state(){return latest?structuredClone(latest):null;}
		, get handshake(){return handshake?structuredClone(handshake):null;}
		, get camera(){return latest?.camera?structuredClone(latest.camera):null;}
		, get placement(){return latest?.placement?structuredClone(latest.placement):null;}
		, get audio(){return {state:audio?.state??'locked',samples:encoded.size,decoded:decoded.size,played,error:audioError};}
		, setCamera(patch){if(patch&&typeof patch==='object')debugCamera=structuredClone(patch);}
		, setControls(values){if(values&&typeof values==='object')Object.assign(debugValues,structuredClone(values));}
		, action(name){if(typeof name==='string')debugActions.push(name);}
	};
	session.phpBilliards=debug;
	Object.defineProperty(canvas,'phpBilliardsDebug',{configurable:true,value:debug});
	canvas.focus({preventScroll:true});

	return async()=>{
		if(closed)return;closed=true;resize.disconnect();session.lobby.protocol.removeEventListener('closed',lobbyClosed);
		for(const timer of retryTimers.values())clearTimeout(timer);retryTimers.clear();
		for(const type of gestures)globalThis.removeEventListener?.(type,gesture,{capture:true});
		canvas.removeEventListener('contextmenu',contextMenu);
		delete module.billiardsSamples;
		await audio?.close().catch(()=>{});
		if(document.fullscreenElement===canvas)await document.exitFullscreen().catch(()=>{});
		if(originalStyle===null)canvas.removeAttribute('style');else canvas.setAttribute('style',originalStyle);
		if(originalTabIndex===null)canvas.removeAttribute('tabindex');else canvas.setAttribute('tabindex',originalTabIndex);
		if(originalLabel===null)canvas.removeAttribute('aria-label');else canvas.setAttribute('aria-label',originalLabel);
		delete canvas.dataset.phpBilliards;delete canvas.dataset.placing;delete canvas.phpBilliardsDebug;
	};
}
