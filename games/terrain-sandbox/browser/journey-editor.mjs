/** Journey stages share terrain charts between the inspector and visible route cards. */
const networkExample = `A rolling-prairie -> B,C
B canyon -> E,F
C mountain -> F,G
E limestone
F river-valley
G coast`;

function markup(options) {
	return `<style>
	.journey-planner{display:grid;gap:14px}
	.journey-planner .journey-bar,.journey-planner .journey-fields{display:grid;grid-template-columns:1fr 1fr;gap:10px}
	.journey-stage-picker{display:grid;grid-template-columns:1fr auto auto;align-items:end;gap:5px}
	.journey-stage-picker button{width:34px;padding:0;font-size:17px}
	.journey-sheet{border:1px solid #577568;border-radius:8px;overflow:hidden;background:#10271f}
	.journey-map-grid{display:grid;gap:0}
	.journey-map{position:relative;align-self:start;aspect-ratio:1;overflow:hidden;background:#1e3631}
	.journey-map canvas{display:block;width:100%;height:100%}
	.journey-map [data-pins]{position:absolute;inset:0;pointer-events:none}
	.journey-map .journey-pin{position:absolute;transform:translate(-50%,-50%);display:grid;place-items:center;
		padding:0 4px;min-height:23px;min-width:23px;border-radius:50%;border:1px solid #fff0c6;background:#173a32;
		color:#fff1c6;font:bold 12px/1 system-ui;box-shadow:0 1px 4px #10231d;pointer-events:auto}
	.journey-map .journey-pin:disabled{opacity:1;cursor:default}
	.journey-map .journey-pin:first-child{background:#efc276;color:#14251f}
	.journey-north,.journey-scale{position:absolute;color:#fff9df;background:#12261edb;border-radius:3px;
		padding:2px 5px;font:600 11px/1.35 system-ui;pointer-events:none}
	.journey-north{top:6px;right:6px}.journey-scale{bottom:6px;left:6px}
	.journey-stops{display:grid;align-content:center;gap:10px;padding:14px;min-width:0}
	.journey-origins{display:flex;align-items:center;flex-wrap:wrap;gap:5px;font-size:12px;color:#d3e1d5}.journey-origins>span{flex-basis:100%}.journey-origins button{display:inline-flex;align-items:center;gap:5px;padding:3px 7px 3px 3px}
	.journey-stops .terrain-field small{display:block;color:#bdd1c2;font-size:11px}
	.journey-sheet footer{display:flex;align-items:center;justify-content:space-between;gap:8px;flex-wrap:wrap;
		border-top:1px solid #405e4e;padding:7px 10px;min-height:30px;color:#d3e1d5;font-size:12px}
	.journey-sheet footer button,.journey-outline button{min-height:28px;padding:3px 8px;font-size:12px}
	.journey-outline{padding:2px 10px 10px}.journey-level{display:grid;grid-template-columns:4.2em 1fr;gap:6px;
		align-items:baseline;border-bottom:1px solid #355243;padding:6px 0}.journey-level:last-child{border:0}
	.journey-level>span{font-size:12px;color:#b8cbbd}.journey-level>div{display:flex;gap:5px;flex-wrap:wrap}
	.journey-rename{display:flex;align-items:end;gap:8px;margin:10px}.journey-rename label{flex:1}.journey-outline button{display:grid;grid-template-columns:42px 1fr;align-items:center;text-align:left;gap:1px 6px}
	.journey-outline button>span,.journey-outline button>small{grid-column:2}
	.journey-thumb{width:30px;height:30px;flex:none;display:grid;place-items:center;
		overflow:hidden;border-radius:3px;background:#19352a;color:#c0cfbd}.journey-thumb:empty::before{content:attr(data-stage)}.journey-outline .journey-thumb{grid-column:1;grid-row:1/3;width:42px;height:42px}.journey-thumb canvas{width:100%;height:100%}.journey-outline button small{color:#c3d5c8;font-size:11px}.journey-outline [aria-current=location] small{color:#ffdc95}
	.journey-outline [aria-pressed=true]{border-color:#e6bb73;color:#ffe0a5}
	.journey-planner .journey-source{padding:10px}.journey-source textarea{box-sizing:border-box;width:100%;resize:vertical;
		background:#12271f;color:#e7f0df;font:13px/1.5 ui-monospace,monospace;border:1px solid #60796b;border-radius:4px;padding:8px}
	.journey-actions{display:flex;flex-wrap:wrap;align-items:center;gap:6px}
	.journey-footer{position:sticky;bottom:-16px;z-index:1;margin:0 0 -10px;padding:9px 0;background:#19362b;border-top:1px solid #496352}.journey-footer p:not(:empty){margin-top:5px}.journey-planner details .journey-actions{margin:8px 10px 10px}
	.journey-actions [data-action=journey-build]{background:#e4bf7a;color:#16271d;border-color:#efd69f;font-weight:700}
	.journey-planner [data-journey-error]{color:#ffd2a5}
	.journey-footer progress{width:100%;height:10px;margin-top:7px;accent-color:#e4bf7a}
	.journey-planner [data-journey-progress]:empty,.journey-planner [data-journey-error]:empty{display:none}
	@container terrain-lab (min-width:445px){.journey-map-grid{grid-template-columns:minmax(0,1.27fr) minmax(0,1fr)}}
	@container terrain-lab (max-width:444px){.journey-stops{grid-template-columns:repeat(2,minmax(0,1fr));padding:10px}
		.journey-map{width:min(100%,290px);justify-self:center}.journey-stops>label:has([data-stage-preset],[data-journey=west]){grid-column:1/-1}}
	@container terrain-lab (max-width:330px){.journey-planner .journey-bar{grid-template-columns:1fr}}
	</style><div class="journey-planner">
	<div class="journey-bar"><label class="terrain-field">Route layout<select data-journey="fork">
	<option value="network">Multi-stage network</option><option value="direct">One destination</option><option value="divide">Two destinations</option></select></label>
	<label class="terrain-field">Landscape width<select data-journey="span"><option value="16384">10.2 mi</option>
	<option value="32768">20.4 mi</option><option value="65536">40.7 mi</option></select></label></div>
	<div class="journey-stage-picker" data-network-only hidden><label class="terrain-field">Inspect stage<select data-stage-picker></select></label>
	<button type="button" data-stage-back aria-label="Previous stage">‹</button><button type="button" data-stage-next aria-label="Next stage">›</button></div>
	<div class="journey-sheet"><div class="journey-map-grid"><div class="journey-map">
	<canvas width="384" height="384" data-journey-map role="img" aria-label="North-up journey terrain map"></canvas>
	<span class="journey-north" aria-hidden="true">↑ N</span><span class="journey-scale" data-map-width></span><div data-pins></div></div>
	<div class="journey-stops"><label class="terrain-field" data-simple>Departure<select data-journey="west">${options('rolling-prairie')}</select></label>
	<label class="terrain-field" data-simple><span data-east-label>Destination</span><select data-journey="east">${options('mountain')}</select></label>
	<label class="terrain-field" data-south hidden>Right fork<select data-journey="south">${options('coast')}</select></label>
	<label class="terrain-field" data-network-only hidden>Stage landscape<select data-stage-preset>${options('rolling-prairie')}</select></label>
	<div class="journey-origins" data-stage-origins hidden></div>
	<label class="terrain-field" data-network-only hidden><span data-first-exit>Destination</span><select data-exit="0"></select></label>
	<label class="terrain-field" data-network-only hidden><span data-second-exit>Second destination</span><select data-exit="1"></select></label></div></div>
	<footer><span data-map-status role="status"></span><button type="button" data-survey-road hidden>Preview roads</button></footer></div>
	<p class="terrain-note" data-journey-error role="status"></p>

	<details data-network-only data-journey-outline hidden open><summary data-outline-title>Route outline</summary><div class="journey-outline" data-outline></div>
	<div class="journey-rename"><label class="terrain-field">Stage name<input type="text" data-stage-name required pattern="[A-Za-z][A-Za-z0-9_\\-]*" autocomplete="off"></label>
	<button type="button" data-rename-stage disabled>Rename</button></div>
	<div class="journey-actions"><button type="button" data-delete-stage>Remove stage</button>
	<button type="button" data-undo-edit disabled>Undo route edit</button></div></details>
	<details data-network-only hidden><summary>Route text</summary><div class="journey-source"><label class="terrain-field">Squares and exits
	<textarea rows="7" spellcheck="false" data-journey="network">${networkExample}</textarea></label>
	<p class="terrain-note">Enter a stage name, its preset ID and up to two exit names. The first stage starts the drive.</p></div></details>
	<div class="journey-footer"><div class="journey-actions"><button type="button" data-action="journey-build">Build and drive</button>
	<button type="button" data-edit-flight hidden>Return to free flight</button>
	<button type="button" data-action="journey-drive" hidden>Drive from west edge</button>
	<button type="button" data-action="journey-fork" hidden>Drive from fork</button></div>
	<progress data-journey-meter aria-label="Journey build progress" hidden></progress>
	<p class="terrain-note" data-journey-progress role="status"></p></div></div>`;
}

/** Longest distance from departure puts a shared destination after all of its incoming forks. */
export function routeLayers(nodes) {
	const ids = Object.keys(nodes), incoming = new Map(ids.map(id=>[id,0])), depth = new Map();
	for(const node of Object.values(nodes)) for(const id of new Set(node.exits)) {
		if(incoming.has(id)) incoming.set(id,incoming.get(id)+1);
	}
	const first = ids[0], queue = first ? [first] : [], layers = [];
	depth.set(first,0);
	for(let i=0;i<queue.length;i++) {
		const id = queue[i], level = depth.get(id);
		(layers[level] ??= []).push(id);
		for(const exit of new Set(nodes[id].exits)) {
			if(!incoming.has(exit)) continue;
			depth.set(exit,Math.max(depth.get(exit) ?? 0,level+1));
			incoming.set(exit,incoming.get(exit)-1);
			if(incoming.get(exit)===0) queue.push(exit);
		}
	}
	const seen = new Set(queue), rest = ids.filter(id=>!seen.has(id));
	if(rest.length) layers.push(rest);
	return layers;
}

const serialize = nodes => Object.entries(nodes).map(([id,node]) =>
	id+' '+node.preset+(node.exits.length?' -> '+node.exits.join(','):'')).join('\n');

function mount(frame, canvas, signal, roadMap) {
	const tab = frame.querySelector('[data-tab-panel=journey]'), el = s=>tab.querySelector(s);
	const panel = frame.querySelector('.terrain-panel'), select = name=>el('[data-journey="'+name+'"]');
	const layout = select('fork'), extent = select('span'), source = select('network');
	const names = new Map([...select('west').options].map(o=>[o.value,o.text]));
	const picker = el('[data-stage-picker]'), stepBack = el('[data-stage-back]'), stepNext = el('[data-stage-next]');
	const preset = el('[data-stage-preset]'), forks = [...tab.querySelectorAll('[data-exit]')];
	const map = el('[data-journey-map]'), pins = el('[data-pins]'), info = el('[data-map-status]'), survey = el('[data-survey-road]');
	const outline = el('[data-outline]'), outlineBox = el('[data-journey-outline]'), pages=frame.querySelector('.lab-pages'), remove = el('[data-delete-stage]'), undo = el('[data-undo-edit]');
	const rename = el('[data-rename-stage]'), stageName = el('[data-stage-name]');
	const origins=el('[data-stage-origins]');
	const cache = new Map(), sharedRoads = new Map(), history = [], thumbnails = [];
	let roadRevision=0;
	let sharedLive=null;
	let serial=0, thumbnailJob=null, thumbnailTimer;
	let planText=null,planRevision=0;
	let nodes = {}, stage = 'A', lastGraphicPlan = null, valid = null, stageOrder = [], state, activeNetwork;
	let pending = null, id = 0, timer, key = '', current, request, lastRoadMap, surveying = false, visible = false, busy = false;
	const on = (node,event,fn)=>node.addEventListener(event,fn,{signal});
	const network = ()=>layout.value==='network';
	const name = id=>id+' · '+(names.get(nodes[id]?.preset) ?? id);
	const show = (selector,yes)=>{for(const node of tab.querySelectorAll(selector))node.hidden=!yes;};
	const button = (text,fn)=>{const b=document.createElement('button');b.type='button';b.textContent=text;b.onclick=fn;return b;};

	function selectStage(next) {
		if(!Object.hasOwn(nodes,next)) return;
		stage=next; renderNetwork(); refresh();queueThumbnails();
	}

	function thumbnail(which,inOutline) {
		const thumb=document.createElement('span');
		thumb.className='journey-thumb';thumb.ariaHidden='true';thumb.dataset.stage=which;
		thumbnails.push({which,thumb,inOutline,key:null,path:null});
		return thumb;
	}
	function renderNetwork() {
		if(!network()) return;
		thumbnails.length=0;
		if(!Object.hasOwn(nodes,stage)) stage=Object.keys(nodes)[0];
		const node=nodes[stage], layers=routeLayers(nodes);
		const parents=new Map();
		for(const [parent,n] of Object.entries(nodes)) for(const exit of new Set(n.exits)) {
			if(!parents.has(exit)) parents.set(exit,[]);
			parents.get(exit).push(parent);
		}
		const incoming=parents.get(stage) ?? [];
		origins.replaceChildren();
		origins.hidden=incoming.length<2;
		if(incoming.length>=2) {
			const label=document.createElement('span');label.textContent='Rejoin from';origins.append(label);
			for(const parent of incoming) {
				const link=button('',()=>selectStage(parent));link.dataset.origin=parent;
				link.append(thumbnail(parent,false),name(parent));origins.append(link);
			}
		}
		stageOrder=layers.flat();
		picker.replaceChildren(); outline.replaceChildren();
		layers.forEach((ids,i)=>{
			const group=document.createElement('optgroup'); group.label='Layer '+(i+1); picker.append(group);
			const row=document.createElement('div'), heading=document.createElement('span'), links=document.createElement('div');
			row.className='journey-level'; heading.textContent=group.label; row.append(heading,links); outline.append(row);
			for(const id of ids) {
				group.append(new Option(name(id),id));
				const hop=button('',()=>selectStage(id)), label=document.createElement('span');
				hop.dataset.square=id;label.textContent=name(id);hop.append(thumbnail(id,true),label);
				hop.setAttribute('aria-pressed',String(id===stage));
				const exits=document.createElement('small');
				const sources=parents.get(id) ?? [];
				const onward=nodes[id].exits.length?'→ '+nodes[id].exits.join(' / '):'End of route';
				exits.textContent=sources.length>1?'Rejoin from '+sources.join(' + ')+' · '+onward:onward;
				hop.append(exits);
				if(activeNetwork?.plan===source.value && (state?.journeyArrival ?? state?.journeySquare)===id)
					{hop.setAttribute('aria-current','location');exits.textContent+=' · You are here';}
				links.append(hop);
			}
		});
		el('[data-outline-title]').textContent='Route outline · '+stageOrder.length+' stages';
		picker.value=stage ?? '';
		stageName.value=stage ?? '';stageName.setCustomValidity('');rename.disabled=true;
		stepBack.disabled=stageOrder.indexOf(stage)<=0; stepNext.disabled=stageOrder.indexOf(stage)>=stageOrder.length-1;
		remove.disabled=!node || stage===Object.keys(nodes)[0];
		undo.disabled=!history.length;
		preset.disabled=!node; preset.value=node?.preset ?? '';
		// Linking back to any ancestor would introduce a cycle. Other branches may share a destination.
		const split=node?.exits.length===2;
		el('[data-first-exit]').textContent=split?'Left fork':'Destination';
		el('[data-second-exit]').textContent=split?'Right fork':'Second destination';
		const blocked=new Set(), todo=[stage];
		while(todo.length) {const id=todo.pop();if(blocked.has(id))continue;blocked.add(id);todo.push(...(parents.get(id)??[]));}
		for(const [i,field] of forks.entries()) {
			field.replaceChildren(new Option(i?'No second exit':'End route here',''));
			for(const id of stageOrder) if(!blocked.has(id) && id!==node?.exits[1-i]) field.add(new Option(name(id),id));
			field.add(new Option('+ New stage…','+'));
			field.disabled=!node || (i===1&&!node.exits.length);
			field.value=node?.exits[i] ?? '';
		}
	}

	function publishEdit(plan) {
		lastGraphicPlan=source.value=plan;
		renderNetwork();
		source.dispatchEvent(new Event('input',{bubbles:true}));
	}
	function change(edit) {
		history.push({plan:source.value,stage,nodes:structuredClone(nodes)}); if(history.length>30) history.shift();
		edit();publishEdit(serialize(nodes));
	}
	on(picker,'change',()=>selectStage(picker.value));
	on(stepBack,'click',()=>selectStage(stageOrder[stageOrder.indexOf(stage)-1]));
	on(stepNext,'click',()=>selectStage(stageOrder[stageOrder.indexOf(stage)+1]));
	on(preset,'change',()=>change(()=>{nodes[stage].preset=preset.value;}));
	for(const [i,field] of forks.entries()) on(field,'change',()=>change(()=>{
		let exit=field.value;
		const adding=exit==='+';
		if(adding) {
			let j=0;
			do {exit=j<26?String.fromCharCode(65+j):'S'+(j+1);j++;} while(Object.hasOwn(nodes,exit));
			nodes[exit]={preset:'rolling-prairie',exits:[]};
		}
		const exits=nodes[stage].exits;
		if(exit) exits[i]=exit; else exits.splice(i);
		if(adding) {stage=exit;preset.focus();}
	}));
	on(stageName,'input',()=>{
		const next=stageName.value.trim();
		stageName.setCustomValidity(Object.hasOwn(nodes,next)&&next!==stage?'That stage name is already in use.':'');
		rename.disabled=next===stage || !stage;
	});
	on(stageName,'keydown',event=>{if(event.key==='Enter'){event.preventDefault();rename.click();}});
	on(rename,'click',()=>{
		if(!stageName.reportValidity()) return;
		const next=stageName.value.trim(),old=stage;
		change(()=>{
			nodes=Object.fromEntries(Object.entries(nodes).map(([id,n])=>[id===old?next:id,
				{preset:n.preset,exits:n.exits.map(exit=>exit===old?next:exit)}]));
			stage=next;
		});
	});
	on(remove,'click',()=>change(()=>{
		const gone=stage; delete nodes[gone];
		for(const n of Object.values(nodes)) n.exits=n.exits.filter(x=>x!==gone);
		stage=Object.keys(nodes)[0];
	}));
	on(undo,'click',()=>{
		const previous=history.pop(); if(!previous)return;
		stage=previous.stage;nodes=previous.nodes;publishEdit(previous.plan);
		undo.disabled=!history.length;
	});
	function validated(result) {
		valid=result?.nodes ?? null;
		if(planText!==source.value) {planText=source.value;planRevision++;}
		// A graphical edit already drew these controls. Preserve scroll and focus
		// when PHP acknowledges it. Typed or loaded plans still need new controls.
		if(valid && result.plan!==lastGraphicPlan) {
			nodes=structuredClone(valid);lastGraphicPlan=result.plan;renderNetwork();
		} else if(!valid && lastGraphicPlan!==source.value) {
			nodes={};lastGraphicPlan=null;renderNetwork();
		}
		refresh();queueThumbnails();
	}

	function spec(which=stage) {
		if(network() && (!valid || !nodes[which]))return null;
		const span=Number(extent.value), west=network()?nodes[which].preset:select('west').value;
		const exits=network()?[...new Set(nodes[which].exits)]:layout.value==='divide'?['L','R']:['E'];
		const destinations=network()?exits.map(e=>nodes[e].preset):[select('east').value,...(exits.length===2?[select('south').value]:[])];
		const live=Boolean(state && (state.recipe.join?.span ?? state.recipe.worldSize)===span && (network()
			? activeNetwork?.plan===source.value && state.journeySquare===which
			: state.recipe.join && !state.journeySquare && state.recipe.preset===west && state.recipe.join.destinations.length===destinations.length
				&& state.recipe.join.destinations.every((p,i)=>p.preset===destinations[i])));
		const params=network()?{plan:source.value,stage:which,span}:{west,destinations,span};
		if(live)params.generation=state.generation;
		const version=live?[state.generation,state.revision,state.settings.waterColor,state.settings.waterLevel,
			state.hiddenSurfaceLayers,state.surfaceLayers,state.surfaceFields,state.paintInks,roadMap()?.hidden]:null;
		const active=network() && activeNetwork?.plan===source.value && (state.recipe.join?.span ?? state.recipe.worldSize)===span;
		const scope=network()?JSON.stringify([planRevision,span,active?(state.recipe.join?.version ?? (state.recipe.join?1:2)):2]):null;
		const progress=active && !live ? (state.journeyReady===which?'ready':state.journeySurveying===which?'building':null) : null;
		return {params,west,exits,destinations,live,scope,progress,signature:JSON.stringify([span,network()?which:null,west,exits,destinations,version,scope,progress])};
	}

	function squares(entry,route) {
		if(!entry || !route?.scope)return [];
		const ids=[route.params.stage,...route.exits];
		return entry.pins.map(([u,v],i)=>({id:ids[i],x:(u-.5)*entry.extent,z:(v-.5)*entry.extent}));
	}
	function rememberRoads(entry,route,path) {
		if(!entry || !route?.scope || !path || entry.recordedPath===path)return;
		entry.recordedPath=path;
		for(const square of squares(entry,route)) {
			sharedRoads.delete(square.id);
			sharedRoads.set(square.id,{...square,scope:route.scope,path});
		}
		while(sharedRoads.size>36)sharedRoads.delete(sharedRoads.keys().next().value);
		roadRevision++;
	}
	// The driver already has all the curves in the current junction, even if its map was never opened.
	function shareBuiltRoads() {
		const highway=roadMap(), id=state?.journeySquare, join=state?.recipe.join;
		if(!id || !join || !valid || !network() || activeNetwork?.plan!==source.value ||
			!nodes[id] || join.span!==Number(extent.value) || highway?.generation!==state.generation)return;
		if(sharedLive?.path===highway.path && sharedLive.revision===planRevision)return;
		const route=spec(id), extentMeters=state.recipe.worldSize;
		const margin=join.span/(2*extentMeters), split=route.exits.length===2;
		const pins=[[margin,.5],...route.exits.map((_,i)=>[1-margin,split?(i?1-margin:margin):.5])];
		rememberRoads({pins,extent:extentMeters},route,highway.path);
		sharedLive={path:highway.path,revision:planRevision};
		return true;
	}
	function drawMap(target,entry,route,path) {
		const ctx=target.getContext('2d'), size=target.width;
		ctx.clearRect(0,0,size,size);
		if(!entry) return;
		ctx.drawImage(entry.image,0,0,size,size);
		const scale=size/entry.extent, span=route.params.span;
		ctx.save();
		ctx.translate(size/2,size/2);ctx.scale(scale,scale);
		ctx.lineJoin=ctx.lineCap='round';
		const stroke=road=>{
			ctx.strokeStyle='#362c1c';ctx.lineWidth=(size<100?1.8:3.4)/scale;ctx.stroke(road);
			ctx.strokeStyle='#ffd272';ctx.lineWidth=(size<100?1:1.8)/scale;ctx.stroke(road);
		};
		for(const square of squares(entry,route)) {
			const x=square.x-span/2,z=square.z-span/2;
			if(size>=100 && entry.pins.length>1) {
				ctx.save();ctx.strokeStyle='rgba(246,247,221,.55)';ctx.lineWidth=.8/scale;
				ctx.setLineDash([4/scale,4/scale]);ctx.strokeRect(x,z,span,span);ctx.restore();
			}
			const known=sharedRoads.get(square.id);
			if(!path && known?.scope===route.scope) {
				ctx.save();ctx.beginPath();ctx.rect(x,z,span,span);ctx.clip();
				ctx.translate(square.x-known.x,square.z-known.z);stroke(known.path);ctx.restore();
			}
		}
		if(path)stroke(path);
		ctx.restore();
	}
	function pathFor(entry, route) {
		const highway=roadMap();
		return route?.live && highway?.generation===state.generation ? highway.path : entry?.path;
	}
	function draw() {
		const path=pathFor(current,request);
		rememberRoads(current,request,path);drawMap(map,current,request,path);
	}
	function storeChart(signature, data) {
		const image=document.createElement('canvas');
		image.width=image.height=data.size;
		image.getContext('2d').drawImage(canvas,10,canvas.height-10-data.size,data.size,data.size,0,0,data.size,data.size);
		const entry={image,extent:data.extent,pins:data.pins};
		if(data.roads) entry.path=roadPath(data.roads);
		cache.set(signature,entry);
		if(cache.size>18)cache.delete(cache.keys().next().value);
		return entry;
	}
	function roadPath(roads) {
		const path=new Path2D();
		for(const [start,...curves] of roads) {
			path.moveTo(...start);
			for(const curve of curves) path.bezierCurveTo(...curve);
		}
		return path;
	}
	// Fill only visible stage and origin cards. A foreground inspection or survey always takes priority.
	function fillThumbnails() {
		if(!visible || !network() || !valid || !current || busy || surveying || pending) return;
		const viewport=pages.getBoundingClientRect();
		let missing=null;
		for(const card of thumbnails) {
			if(card.inOutline && !outlineBox.open)continue;
			const bounds=card.thumb.getBoundingClientRect();
			if(!bounds.height || bounds.bottom<viewport.top || bounds.top>viewport.bottom)continue;
			const route=spec(card.which), entry=cache.get(route.signature);
			if(!entry) {
				if(card.key!==route.signature) missing ??= route;
				continue;
			}
			const path=pathFor(entry,route);
			if(card.key===route.signature && card.path===path && card.revision===roadRevision)continue;
			const thumbnail=document.createElement('canvas');
			thumbnail.width=thumbnail.height=72;
			drawMap(thumbnail,entry,route,path);
			card.thumb.replaceChildren(thumbnail);card.key=route.signature;card.path=path;card.revision=roadRevision;
		}
		if(missing && !thumbnailJob && (state?.mode==='fly' || state?.paused)) {
			thumbnailJob={id:++serial,key:missing.signature};
			pending={id:thumbnailJob.id,...missing.params};
		}
	}
	function queueThumbnails() {
		clearTimeout(thumbnailTimer);
		if(visible && network() && (outlineBox.open || !origins.hidden)) thumbnailTimer=setTimeout(fillThumbnails,160);
	}

	function describe() {
		el('[data-map-width]').textContent=current?(current.extent/1609.344).toFixed(1)+' mi across':'';
		const carried=squares(current,request).some(s=>sharedRoads.get(s.id)?.scope===request.scope);
		survey.hidden=!current || !request || request.live || request.progress==='building' || Boolean(current.path) || (carried && !request.exits.length);
		survey.textContent=surveying?'Cancel preview':carried && request.exits.length?'Preview onward roads':'Preview roads';
		info.textContent=!request?'Choose a valid route to see its map.':!current?'Drawing terrain…':surveying?'Surveying road contours…'
			:request.live?'Current highway':current.path?'Surveyed highway':request.progress==='building'?'Building this junction for the drive…':carried?(request.exits.length?'Known roads · preview onward roads':'End of route · arriving roads'):request.exits.length?'Terrain before road survey':'End of route';
	}
	function placePins(next) {
		pins.replaceChildren();
		const locations=current?.pins ?? [];
		const labels=[network()?stage:'W',...next.exits];
		locations.forEach(([x,y],i)=>{
			const pin=button(labels[i],()=>selectStage(labels[i]));pin.className='journey-pin';
			pin.style.left=100*x+'%';pin.style.top=100*y+'%';
			const label=names.get(i?next.destinations[i-1]:next.west);
			pin.title=pin.ariaLabel=(i && network()?'Inspect '+labels[i]:i?'Destination':'Departure')+' · '+label;
			pin.disabled=!i || !network();pins.append(pin);
		});
	}

	function driveSelection(fork) {
		if(!state || busy || state.preparing || state.picking) return null;
		const span=Number(extent.value);
		if(network()) {
			if(!valid || activeNetwork?.plan!==source.value || !state.journeySquare
				|| (state.recipe.join?.span ?? state.recipe.worldSize)!==span || !valid[stage]
				|| (fork && valid[stage].exits.length!==2)) return null;
			return {stage, generation:state.generation};
		}
		const join=state.recipe.join;
		if(!join || state.journeySquare || join.span!==span || state.recipe.preset!==select('west').value
			|| join.destinations[0].preset!==select('east').value || (layout.value==='divide')!==(join.destinations.length===2)
			|| (join.destinations.length===2 && join.destinations[1].preset!==select('south').value)
			|| (fork && !state.journeyHasFork)) return null;
		return {generation:state.generation};
	}
	function driveButtons() {
		for(const [key,fork] of [['journey-drive',false],['journey-fork',true]]) {
			const button=el('[data-action='+key+']');
			button.hidden=!driveSelection(fork);
		}
	}
	function refresh() {
		driveButtons();
		const enabled=panel.open && !tab.hidden && !document.hidden;
		const next=enabled && !busy?spec():null;
		if(enabled===visible && (next?.signature ?? '')===key)return;
		visible=enabled; key=next?.signature ?? '';request=next;surveying=false;
		clearTimeout(timer);thumbnailJob=null;id=++serial;pending={id};current=cache.get(key);draw();describe();
		pins.replaceChildren();
		if(!next)return;
		placePins(next);
		map.setAttribute('aria-label','North-up map of '+[next.west,...next.destinations].map(p=>names.get(p)).join(' and '));
		if(!current)timer=setTimeout(()=>{pending={id,...next.params};},100);
		queueThumbnails();
	}
	on(survey,'click',()=>{
		if(!request||!current)return;
		thumbnailJob=null;id=++serial;surveying=!surveying;pending=surveying?{id,...request.params,survey:true}:{id};describe();
	});
	function receive(token,json) {
		if(!visible || !request)return;
		if(thumbnailJob?.id===token) {
			const data=JSON.parse(json), signature=thumbnailJob.key;
			thumbnailJob=null;
			if(data.size) storeChart(signature,data);
			else if(data.error) {for(const card of thumbnails) if(spec(card.which)?.signature===signature)card.key=signature;}
			queueThumbnails();return;
		}
		if(token!==id)return;
		const data=JSON.parse(json);
		if(data.phase) {info.textContent=data.phase;return;}
		if(data.error) {surveying=false;describe();info.textContent=data.error;return;}
		if(data.size) current=storeChart(key,data);
		if(current && data.roads) {
			if(!data.size) current.path=roadPath(data.roads);
			surveying=false;
		}
		draw();placePins(request);describe();queueThumbnails();
	}
	function layoutChanged() {
		show('[data-network-only]',network());if(!network())origins.hidden=true;show('[data-simple]',!network());show('[data-south]',layout.value==='divide');
		el('[data-east-label]').textContent=layout.value==='divide'?'Left fork':'Destination';
		renderNetwork();refresh();
	}
	for(const field of [extent,select('west'),select('east'),select('south')])on(field,'change',refresh);
	const observer=new MutationObserver(refresh);observer.observe(panel,{attributes:true,attributeFilter:['open']});
	observer.observe(tab,{attributes:true,attributeFilter:['hidden']});
	on(document,'visibilitychange',refresh);
	on(outlineBox,'toggle',()=>{
		if(!outlineBox.open && thumbnailJob) {
			thumbnailJob=null;
			pending={id:++serial};
		}
		queueThumbnails();
	});
	pages.addEventListener('scroll',queueThumbnails,{signal,passive:true});
	signal.addEventListener('abort',()=>{observer.disconnect();clearTimeout(timer);clearTimeout(thumbnailTimer);cache.clear();sharedRoads.clear();},{once:true});
	on(el('[data-edit-flight]'),'click',()=>frame.querySelector('[data-action=fly]').click());
	// Published state changes only touch the editor when its map or location can have changed.
	function sync(next,plan,building) {
		const previous=state, oldPlan=activeNetwork, wasBusy=busy;
		state=next;activeNetwork=plan;busy=Boolean(building);
		const roads=roadMap(), roadsChanged=roads!==lastRoadMap;
		const sameWindow=plan && oldPlan?.plan===plan.plan && previous?.generation===next.generation;
		if(sameWindow && (previous.revision!==next.revision || (roadsChanged && roads?.generation===lastRoadMap?.generation &&
			(roads?.hidden ?? []).join(',')!==(lastRoadMap?.hidden ?? []).join(',')))) planRevision++;
		const shared=shareBuiltRoads();
		const location=next.journeyArrival ?? next.journeySquare;
		if(location && plan?.plan===source.value && (oldPlan?.plan!==plan.plan ||
			stage===(previous?.journeyArrival ?? previous?.journeySquare))) stage=location;
		if(previous?.mode!==next.mode)el('[data-edit-flight]').hidden=next.mode==='fly';
		if(previous?.generation!==next.generation || previous?.journeyArrival!==next.journeyArrival || oldPlan!==plan)renderNetwork();
		lastRoadMap=roads;
		if(!tab.hidden && (wasBusy!==busy || previous?.preparing!==next.preparing || previous?.picking!==next.picking)) driveButtons();
		if(!tab.hidden && (shared || roadsChanged || wasBusy!==busy || previous?.generation!==next.generation || previous?.revision!==next.revision
			|| previous?.journeyReady!==next.journeyReady || previous?.journeySurveying!==next.journeySurveying
			|| previous?.settings.waterLevel!==next.settings.waterLevel || previous?.settings.waterColor!==next.settings.waterColor
			|| previous?.materialReply!==next.materialReply || previous?.surfaceOrigin!==next.surfaceOrigin || oldPlan!==plan)) {
			refresh();
			if(shared || (roadsChanged && request?.live)) {draw();describe();queueThumbnails();}
		}
	}
	return {validated,layoutChanged,sync,receive,driveSelection,adopt(id,loaded){history.length=0;stage=id;lastGraphicPlan=null;if(loaded)planRevision++;},takeRequest(){const item=pending;pending=null;return item;},hasRequest:()=>pending!==null};
}

export function activate({module}) {
	module.terrainJourneyEditor={markup,mount};
	return ()=>{delete module.terrainJourneyEditor;};
}
