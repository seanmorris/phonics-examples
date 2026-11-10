/**
 * Browser bridge for PHP Poker: viewport, keyboard and pointer input, card art rasterisation, status publication
 * and the test/accessibility command queue. It never opens a network connection and never holds a hand.
 */

/** Keys the table handles; everything else reaches the page untouched. */
const KEYS=new Set(['f','c','b','d','g','h','i','l','m','n','s','v','w','o','p','q','y','0','1','2','3','4','5','6','7','8','+','-','=','enter',' ','escape','backspace','arrowleft','arrowright','arrowup','arrowdown']);

/** Cell size for the rasterised cards, capped so the atlas crossing into PHP stays under ~8 MB. */
export function cardCell(dpr)
{
	return dpr>1.25?[150,210]:[125,175];
}

/** Map a card id (suit*13 + rank-2, suits clubs, diamonds, hearts, spades) to its cell on the deck sheet. */
export function sheetCell(id,suitRows)
{
	if(id===52||id===53)return id;
	const suit=Math.floor(id/13),rank=id%13+2;
	return suitRows[suit]*13+(rank===14?0:rank-1);
}

/**
 * Table-menu shortcut for a key event: Alt+G opens Game and Alt+P opens Poker. The typed letter decides, so other
 * keyboard layouts work. When Alt turns the key into a symbol (macOS Option+G types "©" and Option+P "π"), the
 * physical key decides instead. AltGr arrives as Ctrl+Alt on Windows and types characters, so it is left alone.
 */
export function tableMenuShortcut(event)
{
	if(!event?.altKey||event.ctrlKey||event.metaKey)return null;
	const key=String(event.key??'').toLowerCase();
	const letter=/^[a-z]$/.test(key)?key:({KeyG:'g',KeyP:'p'})[event.code]??'';
	return ['g','p'].includes(letter)?'alt+'+letter:null;
}

/**
 * Local storage for one run of a table in this tab: reads always pass, writes only while `playing()` holds. The
 * table's private record is shared by every tab, and the engine lets one tab play a seat at a time. When another tab
 * takes the seat over, this run stops writing at once, so its late work cannot erase the keys the new tab keeps.
 */
export function seatStorage(storage,playing)
{
	return Object.freeze({
		getItem:key=>storage?.getItem(key)??null
		,setItem:(key,value)=>{if(playing())storage?.setItem(key,value);}
		,removeItem:key=>{if(playing())storage?.removeItem(key);}
	});
}

/** Local storage key for the host's last online house rules, which pre-fill the next House rules dialog. */
export const onlineSettingsKey='php-poker.online-settings.v1';
/** Local storage key listing the tables whose house rules this browser has already shown. */
export const houseRulesSeenKey='php-poker.house-rules-seen.v1';

/** Bicycle Poker 2.15's two PC-speaker effects, recovered from its Sound/Delay/NoSound call sites. */
export const BICYCLE_POKER_CUES=Object.freeze({
	card:Object.freeze([{at:0,frequency:200,duration:.001}])
	,chips:Object.freeze([{at:0,frequency:900,duration:.010},{at:.020,frequency:500,duration:.015}])
});

/** Expand one named PC-speaker cue into absolute oscillator notes. */
export function bicyclePokerTones(cue,at=0)
{
	if(!Number.isFinite(at))return [];
	return (BICYCLE_POKER_CUES[cue]??[]).map(note=>({...note,at:at+note.at}));
}

/**
 * Presentation-only Web Audio reproduction of Bicycle Poker's PIT channel-2 square waves. Motion replacement
 * cancels notes that belonged to the old frame, while a late status update may still recover a cue up to 50 ms old.
 */
export class BicyclePokerBuzzer
{
	constructor({muted=false,createContext=null}={})
	{
		this.muted=Boolean(muted);this.createContext=createContext;this.context=null;this.motionKey='';
		this.sources=new Set;this.played=0;this.lastCue='';this.error='';
	}

	unlock()
	{
		if(this.muted)return false;
		try
		{
			if(!this.context)
			{
				const Context=globalThis.AudioContext??globalThis.webkitAudioContext;
				this.context=this.createContext?.()??(Context?new Context:null);
			}
			if(!this.context)return false;
			if(this.context.state==='suspended')
			{
				const resuming=this.context.resume?.();
				if(resuming?.catch)void resuming.catch(()=>{});
			}
			return true;
		}
		catch(error){this.error=String(error?.message??error);return false;}
	}

	setMuted(muted)
	{
		this.muted=Boolean(muted);
		if(this.muted)this.cancel();
		else this.unlock();
	}

	motion(animation,key)
	{
		key=animation?String(key):'';
		if(key===this.motionKey)return;
		this.motionKey=key;this.cancel();
		if(!animation||this.muted||!this.context)return;
		const elapsed=Number.isFinite(animation.elapsed)?Math.max(0,animation.elapsed):0;
		for(const event of Array.isArray(animation.sounds)?animation.sounds:[])
		{
			const at=Number(event?.at),cue=String(event?.cue??'');
			if(!Number.isFinite(at)||at<elapsed-.05||!BICYCLE_POKER_CUES[cue])continue;
			this.play(cue,Math.max(0,at-elapsed));
		}
	}

	play(cue,delay=0)
	{
		if(this.muted||!this.context)return;
		const now=this.context.currentTime,start=now+Math.max(0,delay);
		for(const note of bicyclePokerTones(cue,start))
		{
			const source=this.context.createOscillator(),gain=this.context.createGain(),end=note.at+note.duration;
			source.type='square';source.frequency.setValueAtTime(note.frequency,note.at);
			gain.gain.setValueAtTime(.035,note.at);
			gain.gain.setValueAtTime(.035,Math.max(note.at,end-.0002));
			gain.gain.linearRampToValueAtTime(0,end);
			source.connect(gain);gain.connect(this.context.destination);
			this.sources.add(source);source.onended=()=>this.sources.delete(source);
			source.start(note.at);source.stop(end+.001);
		}
		this.played++;this.lastCue=cue;
	}

	cancel()
	{
		for(const source of this.sources){try{source.stop();}catch{}}
		this.sources.clear();
	}

	status(){return {muted:this.muted,ready:Boolean(this.context),played:this.played,lastCue:this.lastCue,error:this.error};}
	close(){this.cancel();void this.context?.close?.();this.context=null;}
}

/**
 * Whether a table's house rules should open by themselves: true the first time this browser seats or watches it,
 * false after a reload or in a second tab. The list keeps only the most recent tables. Without storage, the rules
 * show every time rather than never.
 */
export function firstHouseRules(root,storage=globalThis.localStorage,kept=20)
{
	try
	{
		const saved=JSON.parse(storage?.getItem(houseRulesSeenKey)??'[]');
		const seen=Array.isArray(saved)?saved.filter(item=>typeof item==='string'):[];
		if(seen.includes(root))return false;
		storage?.setItem(houseRulesSeenKey,JSON.stringify([...seen,root].slice(-kept)));
	}
	catch{}
	return true;
}

/** Room rejections that retrying cannot fix: the table moved on, or this browser's state no longer matches it. */
export const TERMINAL_ROOM_ERRORS=Object.freeze([
	'It is not your turn','Local state differs from the confirmed turn state','The game supplied an invalid quantized action'
	,'No matching action is settling','This browser is not seated at a table'
]);

/**
 * Publish one room write, retrying while the connection is down. A dropped connection is not a dispute: the table
 * waits, says it is reconnecting from the third failure on, and continues when the room accepts the write. Retries
 * back off to 30 s and stop only when live() turns false (the table ended or the lobby closed), returning undefined.
 * Room rejections that retrying cannot fix still throw at once.
 */
export async function retryRoomWrite(operation,{live=()=>true,reconnecting=()=>{},sleep=delay=>new Promise(resolve=>setTimeout(resolve,delay))}={})
{
	for(let attempt=0;;attempt++)
	{
		try
		{
			const result=await operation();
			if(attempt)reconnecting(false);
			return result;
		}
		catch(error)
		{
			const message=error instanceof Error?error.message:String(error);
			if(TERMINAL_ROOM_ERRORS.includes(message))throw error;
			if(!live())return undefined;
			if(attempt>=2)reconnecting(true);
			await sleep(Math.min(30000,500*2**attempt));
			if(!live())return undefined;
		}
	}
}

/**
 * Apply one player intent to the private deal. A rejected intent (a stale turn, an unavailable move, a bad
 * discard) is this player's mistake, not the peer's, so it becomes the table's error line instead of freezing it.
 * @return {boolean} Whether the intent was accepted.
 */
export function applyOnlineCommand(online,command)
{
	try
	{
		online.command(command);
		return true;
	}
	catch(error)
	{
		online.localError=error instanceof Error?error.message:String(error);
		return false;
	}
}

/** Combine intents produced by PHP input with commands injected through the browser test/accessibility bridge. */
export function pokerCommandBatch(generatedJson,injected=[])
{
	const generated=generatedJson?JSON.parse(generatedJson):[];
	if(!Array.isArray(generated)||!Array.isArray(injected))throw new TypeError('Poker commands must be a list');
	return [...generated,...injected];
}

/** Keep provisional deterministic state visible without offering a turn the room has not confirmed. */
export function pendingPokerView(view)
{
	if(!view)return null;
	return {...view,legalActions:{},waitingForConfirmation:true,message:'Waiting for both players to confirm the action.'};
}

/** Freeze a transcript the Poker verifier rejects without conceding the match to its sender. */
export function freezeOnlineDispute(lobbyPort,error)
{
	const message=error instanceof Error?error.message:String(error);
	try{lobbyPort?.dispute(message);}catch{}
	return message;
}

/** Human-readable Matrix name when a profile has no display name. */
export function matrixPlayerName(id)
{
	const value=String(id??'').trim(),match=/^@([^:]+):/.exec(value);
	return match?.[1]||value||'Player';
}

/**
 * Words for a match the room ended on time: the waiting player's claim after the other missed a step deadline.
 * Returns the status message and, for the player who ran out of time, an error notice; null for other endings.
 */
export function onlineTimeoutNotice(detail,localPlayer,name=matrixPlayerName)
{
	if(!detail?.timeout)return null;
	if(detail.winner===localPlayer)return {message:`${name(detail.loser)} ran out of time. You win the match.`,error:''};
	if(detail.loser===localPlayer)return {message:'You ran out of time.',error:`You ran out of time. ${name(detail.winner)} wins the match.`};
	return {message:`${name(detail.loser)} ran out of time. ${name(detail.winner)} wins the match.`,error:''};
}

/** Add presentation-only Matrix names and avatar atlas keys to the deterministic online view. */
export function decorateOnlineView(view,{players=[],localPlayer=null,profiles=new Map,avatars=new Map}={})
{
	if(!view)return null;
	const ordered=[localPlayer,...players.filter(player=>player!==localPlayer)];
	const lookup=(collection,key)=>collection instanceof Map?collection.get(key):collection?.[key];
	return {...view,players:(view.players??[]).map((player,index)=>{
		const id=ordered[index]??player.name,profile=lookup(profiles,id),avatar=lookup(avatars,id);
		const display=typeof profile?.displayName==='string'&&profile.displayName.trim()!==''
			?profile.displayName.trim():matrixPlayerName(id);
		return {...player,name:index===0?'You':display,portrait:typeof avatar==='string'?avatar:''};
	})};
}

/**
 * Rasterise the deck sheet and both backs into one RGBA atlas: 9 columns of cells, sheet order first, then red and
 * blue backs. Returns the descriptor PHP expects, with the pixels as base64.
 */
export async function rasteriseCards(art,dpr,{createImage=source=>loadArt(source)}={})
{
	const [cellWidth,cellHeight]=cardCell(dpr),columns=art.columns;
	// Give the percentage-sized source SVG an intrinsic raster viewport before cropping.
	// Chrome otherwise interprets drawImage's source rectangles against the SVG viewBox
	// while reporting a different naturalWidth, spreading one oversized card over the atlas.
	const sheetDocument=new DOMParser().parseFromString(art.sheet,'image/svg+xml');
	const sheetRoot=sheetDocument.documentElement;
	if(sheetRoot.localName!=='svg')throw new Error('Invalid card sheet');
	sheetRoot.setAttribute('width',String(cellWidth*columns));
	sheetRoot.setAttribute('height',String(cellHeight*art.rows));
	const sheet=await createImage(new XMLSerializer().serializeToString(sheetRoot));
	const red=await createImage(art.backs.red),blue=await createImage(art.backs.blue);
	// Player emblems stay on their native 32 px grid in the atlas.
	const iconKeys=Object.keys(art.icons??{}),iconSize=32;
	const cells=art.columns*art.rows+2+iconKeys.length,rows=Math.ceil(cells/columns);
	const width=cellWidth*columns,height=cellHeight*rows;
	const canvas=document.createElement('canvas');canvas.width=width;canvas.height=height;
	const context=canvas.getContext('2d',{willReadFrequently:true});
	const sourceWidth=sheet.naturalWidth/art.columns,sourceHeight=sheet.naturalHeight/art.rows;
	for(let cell=0;cell<art.columns*art.rows;cell++)
	{
		const sx=(cell%art.columns)*sourceWidth,sy=Math.floor(cell/art.columns)*sourceHeight;
		context.drawImage(sheet,sx,sy,sourceWidth,sourceHeight,(cell%columns)*cellWidth,Math.floor(cell/columns)*cellHeight,cellWidth,cellHeight);
	}
	for(const [index,image] of [red,blue].entries())
	{
		const cell=art.columns*art.rows+index;
		context.drawImage(image,(cell%columns)*cellWidth,Math.floor(cell/columns)*cellHeight,cellWidth,cellHeight);
	}
	const icons={};
	context.imageSmoothingEnabled=false;
	for(const [index,key] of iconKeys.entries())
	{
		const descriptor=typeof art.icons[key]==='object'?art.icons[key]:{source:art.icons[key]};
		const image=await createImage(descriptor.source);
		const cell=art.columns*art.rows+2+index;
		const x=(cell%columns)*cellWidth+Math.floor((cellWidth-iconSize)/2),y=Math.floor(cell/columns)*cellHeight+Math.floor((cellHeight-iconSize)/2);
		if(descriptor.round)
		{
			const sourceWidth=image.naturalWidth||image.width,sourceHeight=image.naturalHeight||image.height;
			const side=Math.min(sourceWidth,sourceHeight),sx=(sourceWidth-side)/2,sy=(sourceHeight-side)/2;
			context.save();context.beginPath();context.arc(x+iconSize/2,y+iconSize/2,iconSize/2,0,Math.PI*2);context.clip();
			context.drawImage(image,sx,sy,side,side,x,y,iconSize,iconSize);context.restore();
		}
		else context.drawImage(image,x,y,iconSize,iconSize);
		icons[key]=[x,y,iconSize,iconSize];
	}
	const pixels=context.getImageData(0,0,width,height).data;
	let binary='';
	for(let offset=0;offset<pixels.length;offset+=0x8000)binary+=String.fromCharCode.apply(null,pixels.subarray(offset,offset+0x8000));
	return {width,height,cell:[cellWidth,cellHeight],columns,backs:{red:art.columns*art.rows,blue:art.columns*art.rows+1},icons,base64:btoa(binary)};
}

function loadArt(source)
{
	return new Promise((resolve,reject)=>{
		const direct=/^(?:blob:|data:)/.test(source),url=direct?source:URL.createObjectURL(new Blob([source],{type:'image/svg+xml'}));
		const image=new Image;
		image.onload=()=>{if(!direct)URL.revokeObjectURL(url);resolve(image);};
		image.onerror=()=>{if(!direct)URL.revokeObjectURL(url);reject(new Error('Card art failed to load'));};
		image.src=url;
	});
}

function blobDataUrl(blob)
{
	return new Promise((resolve,reject)=>{
		const reader=new FileReader;
		reader.onload=()=>resolve(String(reader.result));reader.onerror=()=>reject(reader.error??new Error('Avatar failed to load'));
		reader.readAsDataURL(blob);
	});
}

export function activate({module, canvas, session, services})
{
	const shell=new services.GameShell({module,canvas,session,title:'PHP Poker'});
	shell.context({alpha:false,antialias:true,depth:false,stencil:false});
	const {signal}=shell;
	const originalStyle=canvas.getAttribute('style'),originalTabIndex=canvas.getAttribute('tabindex'),originalLabel=canvas.getAttribute('aria-label');
	const originalDescription=canvas.getAttribute('aria-describedby');
	canvas.style.cssText='display:block;width:100%;height:100%;max-width:none;max-height:none;margin:0;border:0;border-radius:0;touch-action:none;outline:none;background:#1f8f45;cursor:default';
	canvas.tabIndex=Math.max(0,canvas.tabIndex);
	canvas.setAttribute('aria-label','PHP Poker. F folds, C checks or calls, B bets, 1 to 5 select cards and D draws. Alt G opens Game and Alt P opens Poker. Controller directional controls move focus, confirm selects, back returns, and Start asks before leaving the table.');

	let viewport='[1280,720,1]',latest=null;
	let keys=[],clicks=[],pointer={x:-1,y:-1,down:false},activePointer=null,pressedAt=null,pointerClient=null;
	let online=null,onlineView=null,onlineWork=Promise.resolve(),submitting=false,awaitingConfirmation=false;
	// A room write is retrying after a dropped connection; tableEnded stops those retries once the room ends the table.
	let reconnecting=false,tableEnded=false;
	// A watching tab mirrors a seat another tab is playing: it applies actions for display, but never writes the
	// table's record, submits, reports or resigns. The lobby's dialog and sidebar offer to play here instead. Each start
	// begins a new run; work from an earlier run stops writing and its errors are dropped.
	let watching=false,seatRun=0;
	const watchingNotice='Watching: this seat is being played in another tab.';
	let onlinePlayers=[],onlineLocal=null,onlineProfiles=new Map,onlineAvatars=new Map,identityVersion=0;
	let lobbyOpening=false,lobbyVisible=false,lobbyPort=null;
	const local=event=>{
		const box=canvas.getBoundingClientRect(),[width,height]=JSON.parse(viewport);
		return {x:(event.clientX-box.left)/Math.max(1,box.width)*width,y:(event.clientY-box.top)/Math.max(1,box.height)*height};
	};
	const updateCursor=()=>{
		const [width,height]=JSON.parse(viewport),x=pointer.x/width,y=pointer.y/height;
		const hit=x>=0&&x<=1&&y>=0&&y<=1&&(latest?.ui?.hitRegions??[]).some(([left,top,w,h])=>x>=left&&x<=left+w&&y>=top&&y<=top+h);
		canvas.style.cursor=hit?'pointer':'default';
	};
	const movePointer=event=>{
		pointerClient={clientX:event.clientX,clientY:event.clientY};
		pointer={...local(event),down:pointer.down};
		updateCursor();
	};
	// The pointer is re-projected into the new framebuffer space as soon as the canvas is measured again.
	shell.measure({observe:canvas,dprCap:2,skipEmpty:true,initial:viewport,format:(width,height,dpr)=>{
		viewport=JSON.stringify([width,height,dpr]);
		if(pointerClient)movePointer(pointerClient);
		return viewport;
	}});

	// The engine's corner shortcuts control the game frame and the reconstructed Bicycle PC-speaker effects.
	const frame=canvas.closest?.('#game-frame')??canvas.parentElement;
	const quick=new services.QuickControls(frame,frame);
	let buzzer=null;
	const mute=shell.mute({
		key:'php-poker.muted.v1',quick,parse:value=>value==='1',serialize:muted=>muted?'1':'0'
		,onChange:muted=>buzzer?.setMuted(muted)
	});
	buzzer=new BicyclePokerBuzzer({muted:mute.muted});
	const unlockBuzzer=()=>buzzer.unlock();
	document.addEventListener('pointerdown',unlockBuzzer,{capture:true,signal});
	document.addEventListener('keydown',unlockBuzzer,{capture:true,signal});
	quick.muteButton.addEventListener('click',()=>{if(!mute.muted)buzzer.unlock();canvas.focus({preventScroll:true});},{signal});
	shell.fullscreen({target:frame,quick});

	// Input queues drain once per PHP frame. Positions are device pixels in the canvas's own framebuffer space.
	const onKey=event=>{
		if(shell.closed)return;
		buzzer.unlock();
		if(event.ctrlKey||event.metaKey)return;
		const key=event.key.length===1?event.key.toLowerCase():event.key.toLowerCase();
		if(event.altKey)
		{
			const shortcut=tableMenuShortcut(event);if(!shortcut)return;
			event.preventDefault();if(!event.repeat)keys.push({key:shortcut});return;
		}
		if(!KEYS.has(key))return;
		event.preventDefault();
		if(!event.repeat)keys.push({key});
	};
	const releasePointer=()=>{
		const id=activePointer;activePointer=null;pressedAt=null;pointer.down=false;
		if(id!==null&&canvas.hasPointerCapture?.(id))canvas.releasePointerCapture(id);
	};
	const onMove=event=>{if(activePointer===null||event.pointerId===activePointer)movePointer(event);};
	const onLeave=()=>{if(activePointer===null){pointerClient=null;pointer={x:-1,y:-1,down:false};updateCursor();}};
	const onDown=event=>{
		if(event.button!==0||activePointer!==null)return;
		buzzer.unlock();
		canvas.focus({preventScroll:true});activePointer=event.pointerId;pressedAt=[event.clientX,event.clientY];
		pointer.down=true;movePointer(event);canvas.setPointerCapture?.(event.pointerId);
	};
	const onUp=event=>{
		if(event.button!==0||!pointer.down||event.pointerId!==activePointer)return;
		const tapped=pressedAt&&Math.hypot(event.clientX-pressedAt[0],event.clientY-pressedAt[1])<=10;
		movePointer(event);const position={x:pointer.x,y:pointer.y};
		releasePointer();
		const box=canvas.getBoundingClientRect();
		if(tapped&&event.clientX>=box.left&&event.clientX<=box.right&&event.clientY>=box.top&&event.clientY<=box.bottom)clicks.push(position);
	};
	const onCancel=event=>{if(event.pointerId===activePointer)releasePointer();};
	const onWheel=event=>{
		if(shell.closed||latest?.screen!=='help'||event.deltaY===0)return;
		event.preventDefault();keys.push({key:event.deltaY>0?'arrowdown':'arrowup'});
	};
	const clearInput=()=>{keys=[];clicks=[];releasePointer();onLeave();};
	const onVisibility=()=>{if(document.visibilityState==='hidden')clearInput();};
	canvas.addEventListener('keydown',onKey,{signal});
	canvas.addEventListener('pointermove',onMove,{signal});
	canvas.addEventListener('pointerleave',onLeave,{signal});
	canvas.addEventListener('pointerdown',onDown,{signal});
	canvas.addEventListener('pointerup',onUp,{signal});
	canvas.addEventListener('pointercancel',onCancel,{signal});
	canvas.addEventListener('wheel',onWheel,{passive:false,signal});
	canvas.addEventListener('lostpointercapture',onCancel,{signal});
	canvas.addEventListener('blur',clearInput,{signal});
	addEventListener('blur',clearInput,{signal});
	document.addEventListener('visibilitychange',onVisibility,{signal});

	// Accessible mirror of the authorized status: one live line for the message and turn, nothing private.
	const live=document.createElement('div');
	live.setAttribute('role','status');live.setAttribute('aria-live','polite');
	live.style.cssText='position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0);white-space:nowrap';
	canvas.parentElement?.appendChild(live);
	let spoken='';
	const hand=document.createElement('div');
	hand.id='php-poker-hand-'+crypto.randomUUID();
	hand.style.cssText=live.style.cssText;
	hand.setAttribute('aria-live','polite');
	canvas.parentElement?.appendChild(hand);
	canvas.setAttribute('aria-describedby',[originalDescription,hand.id].filter(Boolean).join(' '));
	const cardName=id=>{
		if(id===52||id===53)return `joker ${id-51}`;
		if(!Number.isInteger(id)||id<0||id>51)return 'hidden card';
		return ['two','three','four','five','six','seven','eight','nine','ten','jack','queen','king','ace'][id%13]
			+' of '+['clubs','diamonds','hearts','spades'][Math.floor(id/13)];
	};
	let described='';

	// Card art arrives from PHP as SVG text. Matrix avatars can append cells and replace the atlas later.
	let cardArt=null,atlas=null,rasterising=null,rebuildAtlas=false,dynamicIcons={};
	const requestAtlas=()=>{
		if(shell.closed||!cardArt)return;
		if(rasterising){rebuildAtlas=true;return;}
		rebuildAtlas=false;
		const art={...cardArt,icons:{...(cardArt.icons??{}),...dynamicIcons}};
		rasterising=rasteriseCards(art,JSON.parse(viewport)[2]).then(result=>{atlas=JSON.stringify(result);})
			.catch(error=>console.error('Poker card art failed',error)).finally(()=>{
				rasterising=null;if(rebuildAtlas)requestAtlas();
			});
	};
	shell.bridge('pokerCardArt',json=>{
		if(shell.closed)return;
		cardArt=JSON.parse(json);requestAtlas();
	});
	shell.bridge('pokerCardAtlas',()=>{
		if(!atlas)return '';
		const result=atlas;atlas=null;return result;
	});

	shell.bridge('pokerViewport',()=>viewport);
	shell.bridge('pokerSeed',()=>shell.seedHex(32));
	shell.bridge('pokerInput',()=>{
		const frame={controls:keys,pointer:{...pointer,clicks},hidden:document.visibilityState==='hidden'};
		keys=[];clicks=[];
		return JSON.stringify(frame);
	});
	let commands=[];
	// The host picks online house rules in the lobby's House rules dialog; the last choice pre-fills the next table.
	const lastOnlineSettings=()=>{
		try{return session.pokerOnline.validateOnlineSettings(JSON.parse(localStorage.getItem(onlineSettingsKey)??'null')??undefined);}
		catch{return session.pokerOnline.DEFAULT_ONLINE_SETTINGS;}
	};
	// A table's house rules open by themselves once, the first time this browser seats or watches it.
	let houseRules=null;
	shell.bridge('pokerCommands',(generatedJson='[]')=>{
		const queued=pokerCommandBatch(generatedJson,commands);commands=[];
		if(online&&watching)return '[]';
		if(online)
		{
			if(queued.some(command=>command.type==='menu'))void enqueue(()=>roomWrite(()=>lobbyPort.leave()));
			else for(const command of queued)void enqueue(async()=>{
				const accepted=applyOnlineCommand(online,command);updateOnlineView();
				if(accepted)await drive();
			});
			return '[]';
		}
		return JSON.stringify(queued);
	});
	shell.bridge('pokerOnlineView',()=>onlineView?JSON.stringify(onlineView):'');
	shell.status('pokerStatus',{dataset:'phpPoker',handler:state=>{
		latest=state;
		const animation=latest.ui?.animation;
		buzzer.motion(animation,animation?`${latest.generation??''}:${latest.revision??''}:${latest.handNumber??''}:${animation.kind}:${animation.duration}`:'');
		updateCursor();
		if(latest.ui?.local==='online'&&!lobbyOpening&&!lobbyVisible)
		{
			lobbyOpening=true;lobbyVisible=true;
			session.lobby.open().catch(error=>console.error('Poker lobby failed to open',error)).finally(()=>{lobbyOpening=false;});
		}
		const turn=latest.waitingForConfirmation?'':latest.turn===0?'Your turn.':latest.turn>0?`${latest.players?.[latest.turn]?.name??'Opponent'} is thinking.`:'';
		const selected=latest.ui?.discards??[];
		const selection=latest.screen==='table'&&latest.legalActions?.draw
			? selected.length?'Selected for discard: '+selected.map(cardName).join(', ')+'. Press D to draw.':'No cards selected. Press D to stand pat.' : '';
		const line=[latest.error,latest.ui?.hint||latest.message,turn,selection].filter(Boolean).join(' ');
		if(line!==spoken){spoken=line;live.textContent=line;}
		// Only the local player's authorized cards enter the accessible hand description.
		const own=latest.screen==='table'?latest.players?.[0]?.cards:[];
		const description=own?.length?'Your hand: '+own.map((card,index)=>`${index+1}: ${cardName(card)}`).join('; ')+'.':'';
		if(description!==described){described=description;hand.textContent=description;}
	}});

	const onlinePresentation=view=>decorateOnlineView(view,{
			players:onlinePlayers,localPlayer:onlineLocal,profiles:onlineProfiles,avatars:onlineAvatars
		});
	const updateOnlineView=()=>{
		let view=onlinePresentation(online?.view()??null);
		if(view&&houseRules)view={...view,houseRules};
		if(view&&watching){onlineView={...view,legalActions:{},message:watchingNotice};return;}
		onlineView=awaitingConfirmation?pendingPokerView(view):view;
		if(onlineView&&reconnecting)onlineView={...onlineView,legalActions:{},message:'Connection lost. Reconnecting to the table…'};
	};
	const clearOnlineIdentities=()=>{
		identityVersion++;onlinePlayers=[];onlineLocal=null;onlineProfiles=new Map;onlineAvatars=new Map;
		if(Object.keys(dynamicIcons).length){dynamicIcons={};requestAtlas();}
	};
	const loadOnlineIdentities=async detail=>{
		const version=++identityVersion,provider=session.lobby.protocol.provider;
		if(Object.keys(dynamicIcons).length){dynamicIcons={};requestAtlas();}
		onlinePlayers=[...detail.players];onlineLocal=detail.localPlayer;onlineProfiles=new Map;onlineAvatars=new Map;
		const snapshot=lobbyPort.snapshot(),known=[snapshot.profile,...(Array.isArray(snapshot.peers)?snapshot.peers:[])].filter(Boolean);
		for(const profile of known)if(detail.players.includes(profile.id))onlineProfiles.set(profile.id,profile);
		updateOnlineView();
		await Promise.all(detail.players.map(async id=>{
			let profile=onlineProfiles.get(id)??{id,displayName:matrixPlayerName(id),avatarUrl:null};
			if(typeof provider.userProfile==='function')
			{
				try{profile=await provider.userProfile(id);}catch{}
			}
			if(shell.closed||version!==identityVersion)return;
			onlineProfiles.set(id,profile);updateOnlineView();
			if(!profile.avatarUrl||typeof provider.avatarThumbnail!=='function')return;
			try
			{
				const source=await blobDataUrl(await provider.avatarThumbnail(profile.avatarUrl,{size:96}));
				if(shell.closed||version!==identityVersion)return;
				const key=id===detail.localPlayer?'matrix-local':'matrix-opponent';
				dynamicIcons={...dynamicIcons,[key]:{source,round:true}};onlineAvatars.set(id,key);
				requestAtlas();updateOnlineView();
			}
			catch(error){console.warn('Matrix avatar failed to load',error);}
		}));
	};
	const failOnline=error=>{
		const message=freezeOnlineDispute(lobbyPort,error);console.error('Poker online protocol failed',error);
		if(online){onlineView={...onlinePresentation(online.view()),error:message,message:'The online Poker action could not be verified.'};}
		submitting=false;
	};
	// Work queued before the latest start belongs to a retired run: a tab that lost its seat expects its writes to fail.
	const enqueue=task=>{
		const run=seatRun;
		onlineWork=onlineWork.then(task).catch(error=>{if(run===seatRun)failOnline(error);});
		return onlineWork;
	};
	// A retry stops once the table ends or a newer start retires this run, so it never holds up that start.
	const roomWrite=operation=>{
		const run=seatRun;
		return retryRoomWrite(operation,{
			live:()=>Boolean(online)&&!tableEnded&&run===seatRun
			,reconnecting:value=>{if(reconnecting!==value){reconnecting=value;updateOnlineView();}}
		});
	};
	const drive=async()=>{
		if(!online||watching||submitting||tableEnded||online.currentActor()!==online.localPlayer)return;
		const action=online.nextAction();if(!action)return;
		const preHash=await online.hash(),settle=online.settlesAction(action);submitting=true;
		try{await roomWrite(()=>lobbyPort.submitAction(action,preHash,{settle}));}
		catch(error){submitting=false;throw error;}
	};
	lobbyPort=session.lobby.attachGame({
		// A standby start (another tab plays this seat) shows the seat's own cards read-only. The engine starts again
		// when the seat moves between tabs.
		start:detail=>{
			const run=++seatRun,standby=Boolean(detail.standby),seat=standby?detail.seat:detail.localPlayer;
			return enqueue(async()=>{
				void loadOnlineIdentities({...detail,localPlayer:seat})
					.catch(error=>console.warn('Matrix player identity failed to load',error));
				tableEnded=false;reconnecting=false;watching=standby;
				const storage=globalThis.localStorage;
				const controller=new session.pokerOnline.OnlineDeal({
					api:session.mentalPoker,root:detail.root,sessionId:detail.sessionId,players:detail.players
					,localPlayer:seat,breaker:detail.breaker,settings:detail.settings??undefined
					,...(standby
						?{storage:session.pokerOnline.readOnlyStorage(storage),legacyStorage:null,follow:true}
						:{storage:seatStorage(storage,()=>run===seatRun)})
				});
				const hash=await controller.replay(detail.history??[]);
				if(detail.sequence!==controller.publicState().sequence)throw new Error('Room history ended at the wrong sequence');
				if(detail.sequence>0&&detail.hash!==hash)throw new Error('Room history does not match its confirmed hash');
				const first=firstHouseRules(detail.root,storage,session.pokerOnline.KEPT_TABLES);
				houseRules=first||houseRules===detail.root?detail.root:null;
				online=controller;submitting=false;awaitingConfirmation=false;updateOnlineView();
				if(!standby)await roomWrite(()=>lobbyPort.reportReady(hash));
			});
		}
		,action:detail=>enqueue(async()=>{
			if(!online)throw new Error('Received a Poker action before the deal started');
			const settlement=await online.apply(detail);
			if(watching){updateOnlineView();return;}
			if(detail.batch_end!==false){awaitingConfirmation=true;updateOnlineView();}
			const waiting=await roomWrite(()=>lobbyPort.reportSettled(settlement));
			if(waiting)updateOnlineView();
			else{awaitingConfirmation=false;submitting=false;await drive();}
		})
		,confirmed:detail=>enqueue(async()=>{
			if(!online)throw new Error('Received a Poker confirmation before the deal started');
			if(detail.sequence!==online.publicState().sequence||detail.hash!==await online.hash())
			throw new Error('Room confirmation does not match the private-deal transcript');
			awaitingConfirmation=false;submitting=false;updateOnlineView();await drive();
		})
		// Open table and Challenge show this form in the lobby's House rules dialog, starting from the last choice.
		,settingsForm:()=>session.pokerOnline.onlineSettingsForm(lastOnlineSettings())
		// The lobby labels each table with its rules and only seats players at tables this build can play.
		,describeSettings:settings=>{
			try{return session.pokerOnline.describeOnlineSettings(settings??undefined);}
			catch{return 'Table settings this version cannot play';}
		}
		,validSettings:settings=>{
			try{session.pokerOnline.validateOnlineSettings(settings??undefined);return true;}
			catch(error){return error?.message||'This table uses settings this version cannot play';}
		}
		,settingsChosen:settings=>{
			try{localStorage.setItem(onlineSettingsKey,JSON.stringify(session.pokerOnline.validateOnlineSettings(settings)));}
			catch{}
		}
		,stop:detail=>{tableEnded=true;return enqueue(async()=>{
			reconnecting=false;
			if(online)
			{
				const view=onlinePresentation(online.view()),completed=detail?.reason==='bankroll';
				const name=id=>onlineProfiles.get(id)?.displayName?.trim()||matrixPlayerName(id);
				const timedOut=onlineTimeoutNotice(detail,onlineLocal,name);
				onlineView=completed?view:timedOut?{...view,legalActions:{},...timedOut}
					:{...view,error:detail?.reason??'The online table ended.',message:'The online table ended.'};
			}
			awaitingConfirmation=false;submitting=false;
		});}
	});
	const lobbyClosed=()=>{
		lobbyVisible=false;online=null;onlineView=null;awaitingConfirmation=false;submitting=false;clearOnlineIdentities();
		reconnecting=false;tableEnded=true;
		watching=false;seatRun++;houseRules=null;
	};
	session.lobby.protocol.addEventListener('closed',lobbyClosed);

	const debug={
		get state(){return latest;}
		, get audio(){return buzzer.status();}
		, get online(){return online?{public:online.publicState(),hand:online.privateHand()}:null;}
		// Intents queue for PHP; generation and revision default from the last status when the caller omits them,
		// and explicitly supplied stale values are kept so tests can prove they are rejected.
		, command(intent){
			if(shell.closed||!intent||typeof intent!=='object')return false;
			const queued={...intent};
			if(latest&&!['start','menu','resume','help','settings','pace','save-game','load-game'].includes(queued.type))
			{
				queued.generation??=latest.generation;queued.revision??=latest.revision;
			}
			commands.push(queued);return true;
		}
	};
	canvas.phpPokerDebug=debug;
	session.phpPoker=debug;

	shell.onClose(()=>{
		buzzer.close();
		quick.close();
		watching=false;seatRun++;houseRules=null;
		session.lobby.protocol.removeEventListener('closed',lobbyClosed);
		clearInput();
		live.remove();hand.remove();
		delete canvas.phpPokerDebug;delete session.phpPoker;
		if(originalStyle===null)canvas.removeAttribute('style');else canvas.setAttribute('style',originalStyle);
		if(originalTabIndex===null)canvas.removeAttribute('tabindex');else canvas.setAttribute('tabindex',originalTabIndex);
		if(originalLabel===null)canvas.removeAttribute('aria-label');else canvas.setAttribute('aria-label',originalLabel);
		if(originalDescription===null)canvas.removeAttribute('aria-describedby');else canvas.setAttribute('aria-describedby',originalDescription);
	});

	return ()=>shell.close();
}
