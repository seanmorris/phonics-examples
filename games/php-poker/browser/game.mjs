/**
 * Browser bridge for PHP Poker: viewport, keyboard and pointer input, card art rasterisation, status publication
 * and the test/accessibility command queue. It never opens a network connection and never holds a hand.
 */

/** Keys the table handles; everything else reaches the page untouched. */
const KEYS=new Set(['f','c','b','d','h','l','m','v','o','p','q','1','2','3','4','5','+','-','=','enter',' ','escape','backspace','arrowleft','arrowright','arrowup','arrowdown']);

/** Cell size for the rasterised cards, capped so the atlas crossing into PHP stays under ~8 MB. */
export function cardCell(dpr)
{
	return dpr>1.25?[150,210]:[125,175];
}

/** Map a card id (suit*13 + rank-2, suits clubs, diamonds, hearts, spades) to its cell on the deck sheet. */
export function sheetCell(id,suitRows)
{
	const suit=Math.floor(id/13),rank=id%13+2;
	return suitRows[suit]*13+(rank===14?0:rank-1);
}

/**
 * Rasterise the deck sheet and both backs into one RGBA atlas: 9 columns of cells, sheet order first, then red and
 * blue backs. Returns the descriptor PHP expects, with the pixels as base64.
 */
export async function rasteriseCards(art,dpr,{createImage=svg=>loadSvg(svg)}={})
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
	// Player emblems are native 64 px pixel art; their atlas cells preserve that grid without smoothing.
	const iconKeys=Object.keys(art.icons??{}),iconSize=64;
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
		const image=await createImage(art.icons[key]);
		const cell=art.columns*art.rows+2+index;
		const x=(cell%columns)*cellWidth+Math.floor((cellWidth-iconSize)/2),y=Math.floor(cell/columns)*cellHeight+Math.floor((cellHeight-iconSize)/2);
		context.drawImage(image,x,y,iconSize,iconSize);
		icons[key]=[x,y,iconSize,iconSize];
	}
	const pixels=context.getImageData(0,0,width,height).data;
	let binary='';
	for(let offset=0;offset<pixels.length;offset+=0x8000)binary+=String.fromCharCode.apply(null,pixels.subarray(offset,offset+0x8000));
	return {width,height,cell:[cellWidth,cellHeight],columns,backs:{red:art.columns*art.rows,blue:art.columns*art.rows+1},icons,base64:btoa(binary)};
}

function loadSvg(svg)
{
	return new Promise((resolve,reject)=>{
		const url=URL.createObjectURL(new Blob([svg],{type:'image/svg+xml'}));
		const image=new Image;
		image.onload=()=>{URL.revokeObjectURL(url);resolve(image);};
		image.onerror=()=>{URL.revokeObjectURL(url);reject(new Error('Card art failed to load'));};
		image.src=url;
	});
}

export function activate({module, canvas, session})
{
	if(!canvas.getContext('webgl2',{alpha:false,antialias:true,depth:false,stencil:false}))
		throw new Error('PHP Poker requires WebGL2');
	const originalStyle=canvas.getAttribute('style'),originalTabIndex=canvas.getAttribute('tabindex'),originalLabel=canvas.getAttribute('aria-label');
	const originalDescription=canvas.getAttribute('aria-describedby');
	canvas.style.cssText='display:block;width:100%;height:100%;max-width:none;max-height:none;margin:0;border:0;border-radius:0;touch-action:none;outline:none;background:#1f8f45';
	canvas.tabIndex=Math.max(0,canvas.tabIndex);
	canvas.setAttribute('aria-label','PHP Poker. F folds, C checks or calls, B bets, 1 to 5 select cards and D draws. Escape opens the menu.');

	let viewport='[1280,720,1]',latest=null,closed=false;
	const measure=()=>{
		const box=canvas.getBoundingClientRect(),dpr=Math.min(2,devicePixelRatio||1);
		if(box.width<1||box.height<1)return;
		viewport=JSON.stringify([Math.max(1,Math.round(box.width*dpr)),Math.max(1,Math.round(box.height*dpr)),dpr]);
	};
	const resize=new ResizeObserver(measure);resize.observe(canvas);measure();

	// Input queues drain once per PHP frame. Positions are device pixels in the canvas's own framebuffer space.
	let keys=[],clicks=[],pointer={x:-1,y:-1,down:false},activePointer=null,pressedAt=null;
	const local=event=>{
		const box=canvas.getBoundingClientRect(),[width,height]=JSON.parse(viewport);
		return {x:(event.clientX-box.left)/Math.max(1,box.width)*width,y:(event.clientY-box.top)/Math.max(1,box.height)*height};
	};
	const onKey=event=>{
		if(closed||event.ctrlKey||event.metaKey||event.altKey)return;
		const key=event.key.length===1?event.key.toLowerCase():event.key.toLowerCase();
		if(!KEYS.has(key))return;
		event.preventDefault();
		if(!event.repeat)keys.push({key});
	};
	const releasePointer=()=>{
		const id=activePointer;activePointer=null;pressedAt=null;pointer.down=false;
		if(id!==null&&canvas.hasPointerCapture?.(id))canvas.releasePointerCapture(id);
	};
	const onMove=event=>{if(activePointer===null||event.pointerId===activePointer)pointer={...local(event),down:pointer.down};};
	const onDown=event=>{
		if(event.button!==0||activePointer!==null)return;
		canvas.focus({preventScroll:true});activePointer=event.pointerId;pressedAt=[event.clientX,event.clientY];
		pointer={...local(event),down:true};canvas.setPointerCapture?.(event.pointerId);
	};
	const onUp=event=>{
		if(event.button!==0||!pointer.down||event.pointerId!==activePointer)return;
		const tapped=pressedAt&&Math.hypot(event.clientX-pressedAt[0],event.clientY-pressedAt[1])<=10;
		const position=local(event);pointer={...position,down:false};
		releasePointer();
		const box=canvas.getBoundingClientRect();
		if(tapped&&event.clientX>=box.left&&event.clientX<=box.right&&event.clientY>=box.top&&event.clientY<=box.bottom)clicks.push(position);
	};
	const onCancel=event=>{if(event.pointerId===activePointer)releasePointer();};
	const clearInput=()=>{keys=[];clicks=[];releasePointer();};
	const onVisibility=()=>{if(document.visibilityState==='hidden')clearInput();};
	canvas.addEventListener('keydown',onKey);
	canvas.addEventListener('pointermove',onMove);
	canvas.addEventListener('pointerdown',onDown);
	canvas.addEventListener('pointerup',onUp);
	canvas.addEventListener('pointercancel',onCancel);
	canvas.addEventListener('lostpointercapture',onCancel);
	canvas.addEventListener('blur',clearInput);
	addEventListener('blur',clearInput);
	document.addEventListener('visibilitychange',onVisibility);

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
		if(!Number.isInteger(id)||id<0||id>51)return 'hidden card';
		return ['two','three','four','five','six','seven','eight','nine','ten','jack','queen','king','ace'][id%13]
			+' of '+['clubs','diamonds','hearts','spades'][Math.floor(id/13)];
	};
	let described='';

	// Card art arrives from PHP as SVG text and goes back as one rasterised atlas, once.
	let atlas=null,delivered=false,rasterising=null;
	module.pokerCardArt=json=>{
		if(closed||rasterising)return;
		const art=JSON.parse(json);
		rasterising=rasteriseCards(art,JSON.parse(viewport)[2]).then(result=>{atlas=JSON.stringify(result);}).catch(error=>console.error('Poker card art failed',error));
	};
	module.pokerCardAtlas=()=>{
		if(!atlas||delivered)return '';
		delivered=true;const result=atlas;atlas=null;return result;
	};

	module.pokerViewport=()=>viewport;
	module.pokerSeed=()=>Array.from(crypto.getRandomValues(new Uint8Array(32)),byte=>byte.toString(16).padStart(2,'0')).join('');
	module.pokerInput=()=>{
		const frame={controls:keys,pointer:{...pointer,clicks},hidden:document.visibilityState==='hidden'};
		keys=[];clicks=[];
		return JSON.stringify(frame);
	};
	let commands=[];
	module.pokerCommands=()=>{const queued=commands;commands=[];return JSON.stringify(queued);};
	module.pokerStatus=json=>{
		latest=JSON.parse(json);canvas.dataset.phpPoker=json;
		const turn=latest.turn===0?'Your turn.':latest.turn>0?`${latest.players?.[latest.turn]?.name??'Opponent'} is thinking.`:'';
		const selected=latest.ui?.discards??[];
		const selection=latest.screen==='table'&&latest.legalActions?.draw
			? selected.length?'Selected for discard: '+selected.map(cardName).join(', ')+'. Press D to draw.':'No cards selected. Press D to stand pat.' : '';
		const line=[latest.error,latest.message,turn,selection].filter(Boolean).join(' ');
		if(line!==spoken){spoken=line;live.textContent=line;}
		// Only the local player's authorized cards enter the accessible hand description.
		const own=latest.screen==='table'?latest.players?.[0]?.cards:[];
		const description=own?.length?'Your hand: '+own.map((card,index)=>`${index+1}: ${cardName(card)}`).join('; ')+'.':'';
		if(description!==described){described=description;hand.textContent=description;}
	};

	const debug={
		get state(){return latest;}
		// Intents queue for PHP; generation and revision default from the last status when the caller omits them,
		// and explicitly supplied stale values are kept so tests can prove they are rejected.
		, command(intent){
			if(closed||!intent||typeof intent!=='object')return false;
			const queued={...intent};
			if(latest&&!['start','menu','resume','help','settings','pace'].includes(queued.type))
			{
				queued.generation??=latest.generation;queued.revision??=latest.revision;
			}
			commands.push(queued);return true;
		}
	};
	canvas.phpPokerDebug=debug;
	session.phpPoker=debug;

	return ()=>{
		if(closed)return;closed=true;resize.disconnect();
		clearInput();
		canvas.removeEventListener('keydown',onKey);canvas.removeEventListener('pointermove',onMove);
		canvas.removeEventListener('pointerdown',onDown);canvas.removeEventListener('pointerup',onUp);canvas.removeEventListener('pointercancel',onCancel);
		canvas.removeEventListener('lostpointercapture',onCancel);canvas.removeEventListener('blur',clearInput);
		document.removeEventListener('visibilitychange',onVisibility);
		removeEventListener('blur',clearInput);
		live.remove();hand.remove();
		for(const name of ['pokerCardArt','pokerCardAtlas','pokerViewport','pokerSeed','pokerInput','pokerCommands','pokerStatus'])delete module[name];
		delete canvas.dataset.phpPoker;delete canvas.phpPokerDebug;delete session.phpPoker;
		if(originalStyle===null)canvas.removeAttribute('style');else canvas.setAttribute('style',originalStyle);
		if(originalTabIndex===null)canvas.removeAttribute('tabindex');else canvas.setAttribute('tabindex',originalTabIndex);
		if(originalLabel===null)canvas.removeAttribute('aria-label');else canvas.setAttribute('aria-label',originalLabel);
		if(originalDescription===null)canvas.removeAttribute('aria-describedby');else canvas.setAttribute('aria-describedby',originalDescription);
	};
}
