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

/**
 * Loudness of a pocket knock relative to a full-strength ball clack. Pocket strikes are slow beside a break, so the
 * ball-ball curve left them about 12 dB below the recording they replace. This curve keeps the fine chatter of a ball
 * rolling around the pocket edge clearly audible and gives the hardest knocks a clack's full peak.
 */
export function pocketKnockLevel(strength)
{
	strength=Math.max(0,Math.min(1,Number(strength)||0));
	return .3+.7*Math.min(1,strength/.45)**.7;
}

/**
 * The knock, if any, for one strike inside a pocket.
 *
 * Until the ball's top drops below the cloth, two radii down, it is rolling around the pocket edge in sight, and every
 * strike chatters at the edge level. Below that it is falling out of sight down the liner: only a real strike knocks,
 * on the softer ball-ball curve and at most once every 90 ms for each ball, so a sink is a knock or two and the landing
 * rather than a rattle.
 *
 * @param {{strength?:number,depth?:number}} event A pocket-rattle event; depth is in ball radii below resting on the cloth.
 * @param {number} sinceDeep Milliseconds since this ball's last knock out of sight.
 * @param {() => number} random Uniform source in [0, 1).
 * @returns {{hit:string,level:number,deep:boolean}|null} The knock to play, or null for none.
 */
export function pocketKnock(event, sinceDeep=Infinity, random=Math.random)
{
	const strength=Math.max(0,Math.min(1,Number(event?.strength)||0));
	if(!((Number(event?.depth)||0)>=2))return {hit:pocketHit(strength,random),level:pocketKnockLevel(strength),deep:false};
	if(strength<.01||!(sinceDeep>=90))return null;
	return {hit:pocketHit(strength,random),level:.12+.88*strength**.8,deep:true};
}

/**
 * Knocks for a ball landing on the pocket bottom, just before the sink recording takes over.
 * Every landing knocks with hit 1; one that struck the walls hard on the way in also bounces once, a weaker hit 2.
 * Each gap is drawn afresh, so no two landings share a rhythm.
 *
 * @param {number} energy Hardest wall strike this ball made inside the pocket, from 0 to 1.
 * @param {() => number} random Uniform source in [0, 1).
 * @returns {{knocks:{hit:string,level:number,delay:number}[],sinkDelay:number}} Delays in seconds.
 */
export function bottomKnocks(energy, random=Math.random)
{
	const draw=(low,high)=>low+(high-low)*Math.max(0,Math.min(1,Number(random())||0));
	const knocks=[{hit:'pocket1',level:1,delay:0}];
	// The bounce lands 130-190 ms after the first knock, and the sink follows the last knock by 50-80 ms.
	if((Number(energy)||0)>=.2)knocks.push({hit:'pocket2',level:.55,delay:draw(.13,.19)});
	return {knocks,sinkDelay:knocks.at(-1).delay+draw(.05,.08)};
}

/**
 * Start a ball's landing behind any ball still dropping through the same pocket.
 *
 * The pocket narrows below the liner to a single ball's width, so two balls in it together reach the bottom one at a
 * time. A landing starts 200-300 ms after the one ahead of it, or at once when the pocket is clear.
 *
 * @param {number|undefined} previous Start time of the last landing in this pocket, or undefined for none.
 * @param {number} now Current time, in the same seconds as previous.
 * @param {() => number} random Uniform source in [0, 1).
 * @returns {number} Start time of this landing, never before now.
 */
export function queuedDrop(previous, now, random=Math.random)
{
	const gap=.2+.1*Math.max(0,Math.min(1,Number(random())||0));
	return Number.isFinite(previous)?Math.max(now,previous+gap):now;
}

/** Canvas lifecycle, diagnostics, sound, and fullscreen bridge for the native PHP/SDL game. */
export function activate({module, canvas, session, services})
{
	const shell=new services.GameShell({module,canvas,session,title:'PHP Billiards'});
	// The browser module runs before PHP creates its SDL window. Claim the shared context with
	// antialiasing enabled so Emscripten/SDL reuses a multisampled default framebuffer.
	shell.context({alpha:false,antialias:true,depth:true,stencil:false});
	const {signal}=shell;
	const originalStyle=canvas.getAttribute('style'),originalTabIndex=canvas.getAttribute('tabindex');
	const originalLabel=canvas.getAttribute('aria-label'),parent=canvas.parentElement;
	const linkedGame=new URLSearchParams(location.hash.slice(1)).has('phonics-game');
	let latest=null,debugCamera=null,debugValues={};
	let debugActions=linkedGame?['online-play']:[];
	let network=[],networkAcks=[],lobbyOpening=false,lobbyVisible=false,networkWrites=Promise.resolve(),handshake=null;
	let cursor='';
	// PHP owns the mute state and reports it in every status; the shell only persists what PHP decided, so the
	// saved value can ask PHP to start muted on the next run.
	const mute=shell.mute({key:'php-billiards.muted.v1',controlled:true,parse:value=>value==='1',serialize:muted=>muted?'1':'0'});
	if(mute.muted)debugActions.push('mute');
	canvas.style.cssText='display:block;width:100%;height:100%;max-width:none;max-height:none;margin:0;border:0;border-radius:0;touch-action:none;outline:none;background:#020403';
	canvas.tabIndex=Math.max(0,canvas.tabIndex);
	canvas.setAttribute('aria-label','PHP Billiards. Drag on the table to aim, Space to shoot, Escape for the menu, question mark for all controls.');

	// The lobby moves and letterboxes the canvas. Measure the displayed canvas itself so PHP rendering, SDL pointer
	// coordinates, and the native HUD all share the same local pixel space after responsive layout changes. Opening
	// the lobby briefly parks the canvas in a hidden stage; the last valid framebuffer is kept until a joined table
	// makes that stage measurable.
	const view=shell.measure({observe:canvas,dprCap:2,skipEmpty:true,initial:'[1280,720,1]'});

	// The engine's corner shortcuts drive the same mute action and fullscreen request as the PHP menu. Fullscreen
	// takes the canvas's frame, so the shortcuts stay on screen. The mute button only asks PHP, which reports the
	// new state through billiardsStatus.
	const frame=canvas.closest?.('#game-frame')??parent;
	const quick=new services.QuickControls(parent,frame);
	const fullscreen=shell.fullscreen({target:frame,quick});
	quick.muteButton.addEventListener('click',()=>{debugActions.push('mute');canvas.focus({preventScroll:true});},{signal});

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
		if(shell.closed||typeof AudioContext==='undefined')return;
		try
		{
			audio??=new AudioContext;
			if(audio.state==='suspended')void audio.resume().catch(()=>{});
			if(encoded.size)void decodeSamples();
		}
		catch(error){audioError=String(error?.message??error);}
	};
	shell.bridge('billiardsSamples',json=>{
		for(const [name,base64] of Object.entries(JSON.parse(json)))if(typeof base64==='string')encoded.set(name,base64);
		if(audio)void decodeSamples();
	});
	const play=(name,{gain=1,rate=1,lowpass=0,lowpassQ=null,delay=0,limit=8}={})=>{
		const buffer=decoded.get(name),context=audio;
		if(latest?.muted||!buffer||!context||context.state!=='running'||voices>=limit||gain<=.005)return;
		// The repeat guard compares when sounds will start, so a landing queued behind another is never mistaken for it.
		const at=performance.now()+Math.max(0,delay)*1000;
		if(Math.abs(at-(lastPlayed.get(name)??-Infinity))<12)return;
		lastPlayed.set(name,at);
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
		source.start(delay>0?context.currentTime+delay:0);
	};
	// Hardest wall strike of each ball currently inside a pocket, which decides how lively its landing sounds, and the
	// audio-clock start of each pocket's latest landing, which the next ball through it waits behind.
	const pocketEnergy=new Map,pocketLandings=new Map,deepKnocks=new Map;
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
		// pocket-drop is split into three knocks against the pocket's walls, peak-matched to the clack. A ball rolling
		// around the pocket edge chatters on them; one falling out of sight knocks only softly and sparsely. pocket-sink
		// is the ball reaching the bottom: it lands with hit 1, and a lively ball bounces once more, before the sink.
		const knock=(hit,level,options={})=>play(hit,{gain:level*(peaks.get('clack')??1)/Math.max(peaks.get(hit)??1,1e-4),...options});
		if(event.type==='pocket-enter')pocketEnergy.set(event.ball,0);
		if(event.type==='pocket-rattle')
		{
			pocketEnergy.set(event.ball,Math.max(pocketEnergy.get(event.ball)??0,strength));
			const now=performance.now(),sound=pocketKnock(event,now-(deepKnocks.get(event.ball)??-Infinity));
			if(sound?.deep)deepKnocks.set(event.ball,now);
			// Chatter leaves two voices free so the landing knocks and the sink always sound.
			if(sound)knock(sound.hit,sound.level,{limit:6});
		}
		if(event.type==='pocket-reject'||event.type==='pocket')deepKnocks.delete(event.ball);
		if(event.type==='pocket-reject')pocketEnergy.delete(event.ball);
		if(event.type==='pocket')
		{
			const landing=bottomKnocks(pocketEnergy.get(event.ball)??0),now=audio?.currentTime??0;
			const start=queuedDrop(pocketLandings.get(event.pocket),now),wait=start-now;
			pocketEnergy.delete(event.ball);
			// A stopped audio clock would stack every landing behind the last one; nothing sounds then anyway.
			if(audio?.state==='running')pocketLandings.set(event.pocket,start);
			for(const {hit,level,delay} of landing.knocks)knock(hit,level,{delay:wait+delay});
			play('sink',{gain:.32,lowpass:3200,delay:wait+landing.sinkDelay});
		}
		if(event.type==='pocket-reject')play('clack',{gain:.22,rate:.85});
		if(event.type==='place')play('clack',{gain:.12,rate:.6,lowpass:1400});
		if(event.type==='denied')play('impact',{gain:.18,rate:.55,lowpass:700});
	};
	// Listen in the capture phase on the window so SDL's own canvas handlers cannot swallow the unlocking gesture.
	const gesture=()=>unlockAudio();
	const gestures=['pointerdown','keydown','touchend'];
	for(const type of gestures)globalThis.addEventListener?.(type,gesture,{capture:true,signal});
	// Right-drag orbits the camera, so the browser menu must not open over the table.
	shell.suppressContextMenu();

	shell.bridge('billiardsViewport',()=>view.viewport());
	shell.bridge('billiardsRackSeed',()=>shell.seedHex(32));
	shell.bridge('billiardsInput',()=>{
		const result={values:debugValues,actions:debugActions,network,networkAcks};
		debugValues={};debugActions=[];network=[];networkAcks=[];
		if(debugCamera){result.debugCamera=debugCamera;debugCamera=null;}
		return JSON.stringify(result);
	});
	shell.status('billiardsStatus',{dataset:'phpBilliards',handler:state=>{
		const muted=latest?.muted;
		latest=state;
		if(muted!==latest.muted)mute.set(latest.muted,{persist:muted!==undefined});
		if(muted!==latest.muted)quick.setMuted(Boolean(latest.muted));
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
	}});
	shell.bridge('billiardsFullscreen',()=>{void fullscreen.toggle();});

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
		, get audio(){return {state:audio?.state??'locked',samples:encoded.size,decoded:decoded.size,played,voices,error:audioError};}
		, setCamera(patch){if(patch&&typeof patch==='object')debugCamera=structuredClone(patch);}
		, setControls(values){if(values&&typeof values==='object')Object.assign(debugValues,structuredClone(values));}
		, action(name){if(typeof name==='string')debugActions.push(name);}
	};
	session.phpBilliards=debug;
	Object.defineProperty(canvas,'phpBilliardsDebug',{configurable:true,value:debug});
	canvas.focus({preventScroll:true});

	shell.onClose(async()=>{
		session.lobby.protocol.removeEventListener('closed',lobbyClosed);
		for(const timer of retryTimers.values())clearTimeout(timer);retryTimers.clear();
		await audio?.close().catch(()=>{});
		quick.close();
		if(originalStyle===null)canvas.removeAttribute('style');else canvas.setAttribute('style',originalStyle);
		if(originalTabIndex===null)canvas.removeAttribute('tabindex');else canvas.setAttribute('tabindex',originalTabIndex);
		if(originalLabel===null)canvas.removeAttribute('aria-label');else canvas.setAttribute('aria-label',originalLabel);
		delete canvas.dataset.placing;delete canvas.phpBilliardsDebug;delete session.phpBilliards;
	});

	return ()=>shell.close();
}
