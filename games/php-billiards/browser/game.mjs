/** Place the cue above the first rail it crosses while keeping its tip on the cue ball. */
export const cueAppearance = Object.freeze({
	length:1.35
	, buttDiameter:.0285
	, shaftDiameter:.0125
	, chalkTipLength:.007
	, copperCollarLength:.025
	, patternPoints:12
});

/** Preserve an intuitive horizontal orbit on either side of the on-screen rotation target. */
export const orbitYawDelta = (deltaX,pointerY,targetY) => deltaX*.006*(pointerY<targetY?-1:1);

export function cuePose(cueX, cueY, angle, tableWidth, tableHeight, ballRadius)
{
	const {length,buttDiameter}=cueAppearance,dx = Math.cos(angle), dz = Math.sin(angle);
	const tipGap = ballRadius + .008, tipY = ballRadius + .012;
	const tip = [cueX - dx * tipGap,tipY,cueY - dz * tipGap];
	const backX = -dx, backZ = -dz, inset = .04, distances = [];
	const edgeX = tableWidth / 2 - inset, edgeZ = tableHeight / 2 - inset;

	if(Math.abs(backX) > 1e-8)
	{
		const distance = ((backX > 0 ? edgeX : -edgeX) - tip[0]) / backX;
		if(distance > 0) distances.push(distance);
	}

	if(Math.abs(backZ) > 1e-8)
	{
		const distance = ((backZ > 0 ? edgeZ : -edgeZ) - tip[2]) / backZ;
		if(distance > 0) distances.push(distance);
	}

	const railDistance = Math.max(.001, Math.min(...distances));
	const railClearance = .105 + buttDiameter / 2 + .006;
	const pitch = Math.max(8 * Math.PI / 180, Math.atan2(railClearance - tipY, railDistance));
	const sinYaw = Math.sin(angle / 2), cosYaw = Math.cos(angle / 2);
	const sinPitch = Math.sin(pitch / 2), cosPitch = Math.cos(pitch / 2);
	const direction = [dx * Math.cos(pitch),-Math.sin(pitch),dz * Math.cos(pitch)];

	return {
		position: tip.map((value, index) => value - direction[index] * length / 2)
		, scale: [length,buttDiameter,buttDiameter]
		, rotation: [sinYaw * sinPitch,-sinYaw * cosPitch,-cosYaw * sinPitch,cosYaw * cosPitch]
		, tip
		, direction
		, pitch
		, railDistance
		, railClearance
	};
}

const roundedRectangleSdf = (x,z,halfX,halfZ,radius) => {
	const qx=Math.abs(x)-halfX+radius,qz=Math.abs(z)-halfZ+radius;
	return Math.hypot(Math.max(qx,0),Math.max(qz,0))+Math.min(Math.max(qx,qz),0)-radius;
};
const circleSdf = (x,z,cx,cz,radius) => Math.hypot(x-cx,z-cz)-radius;
const subtractSdf = (solid,...cuts) => Math.max(solid,-Math.min(...cuts));
const polygonSdf = (x,z,vertices) => {
	let distanceSquared=Infinity,inside=false;

	for(let index=0,previous=vertices.length-1;index<vertices.length;previous=index++)
	{
		const [ax,az]=vertices[previous],[bx,bz]=vertices[index],edgeX=bx-ax,edgeZ=bz-az;
		const pointX=x-ax,pointZ=z-az,edgeLength=edgeX*edgeX+edgeZ*edgeZ;
		const amount=Math.max(0,Math.min(1,(pointX*edgeX+pointZ*edgeZ)/edgeLength));
		const dx=pointX-edgeX*amount,dz=pointZ-edgeZ*amount;
		distanceSquared=Math.min(distanceSquared,dx*dx+dz*dz);
		if((az>z)!==(bz>z)&&x<(bx-ax)*(z-az)/(bz-az)+ax)inside=!inside;
	}

	return Math.sqrt(distanceSquared)*(inside?-1:1);
};
const insetPolygon = (vertices,radius) => {
	const area=vertices.reduce((sum,[x,z],index)=>{const [nextX,nextZ]=vertices[(index+1)%vertices.length];
		return sum+x*nextZ-nextX*z;},0);
	const direction=Math.sign(area)||1,lines=vertices.map(([x,z],index)=>{
		const [nextX,nextZ]=vertices[(index+1)%vertices.length],dx=nextX-x,dz=nextZ-z,length=Math.hypot(dx,dz);
		const nx=-dz/length*direction,nz=dx/length*direction;
		return {x:x+nx*radius,z:z+nz*radius,dx,dz};
	});

	return lines.map((line,index)=>{
		const previous=lines[(index+lines.length-1)%lines.length];
		const determinant=previous.dx*line.dz-previous.dz*line.dx;
		const offsetX=line.x-previous.x,offsetZ=line.z-previous.z;
		const amount=(offsetX*line.dz-offsetZ*line.dx)/determinant;
		return [previous.x+previous.dx*amount,previous.z+previous.dz*amount];
	});
};

const convexHull = points => {
	const sorted=[...points].sort(([ax,az],[bx,bz])=>ax-bx||az-bz),cross=(a,b,c)=>(b[0]-a[0])*(c[1]-a[1])-(b[1]-a[1])*(c[0]-a[0]);
	const half=[];
	for(const point of sorted)
	{
		while(half.length>=2&&cross(half.at(-2),half.at(-1),point)<=1e-12)half.pop();
		half.push(point);
	}
	const lower=[...half];half.length=0;
	for(const point of sorted.reverse())
	{
		while(half.length>=2&&cross(half.at(-2),half.at(-1),point)<=1e-12)half.pop();
		half.push(point);
	}
	return [...lower.slice(0,-1),...half.slice(0,-1)];
};

const pocketTangent = (jaw,center,radius,mouthCenter,outward) => {
	const dx=jaw[0]-center[0],dz=jaw[1]-center[1],distance=Math.hypot(dx,dz);
	const base=Math.atan2(dz,dx),offset=Math.acos(radius/distance);
	return [base-offset,base+offset].map(angle=>[center[0]+Math.cos(angle)*radius,center[1]+Math.sin(angle)*radius])
		.sort((a,b)=>((b[0]-mouthCenter[0])*outward[0]+(b[1]-mouthCenter[1])*outward[1])
			-((a[0]-mouthCenter[0])*outward[0]+(a[1]-mouthCenter[1])*outward[1]))[0];
};

/** Signed distance for a vertically extruded profile with a circular fillet around both cap edges. */
export function roundedExtrusionSdf(horizontalDistance,y,bottom,top,radius)
{
	radius=Math.max(0,Math.min(radius,(top-bottom)/2));
	const center=(bottom+top)/2,halfHeight=(top-bottom)/2;
	const horizontal=horizontalDistance+radius;
	const vertical=Math.abs(y-center)-(halfHeight-radius);

	return Math.min(Math.max(horizontal,vertical),0)
		+Math.hypot(Math.max(horizontal,0),Math.max(vertical,0))-radius;
}

/** Unit normal for a fillet whose solved horizontal inset differs from its vertical radius. */
export function roundedFilletNormal(nx,nz,angle,horizontalRadius,verticalRadius,yDirection=1)
{
	const horizontal=Math.cos(angle)/Math.max(horizontalRadius,1e-9);
	const vertical=Math.sin(angle)/Math.max(verticalRadius,1e-9),length=Math.hypot(horizontal,vertical)||1;
	return [nx*horizontal/length,yDirection*vertical/length,nz*horizontal/length];
}

export function tablePocketDefinitions(width,height,cornerMouth=.1158875,sideMouth=.1285875)
{
	// The regulation dimension is the chord between cushion noses. The round
	// drop is a separate throat behind it, joined by two tangent facings.
	const cushionWidth=.055,throatRadius=cornerMouth/2;
	const makePocket=(mouthCenter,outward,mouth,center,type)=>{
		const lateral=[-outward[1],outward[0]],jaws=[-1,1].map(sign=>[
			mouthCenter[0]+lateral[0]*mouth/2*sign,mouthCenter[1]+lateral[1]*mouth/2*sign
		]);
		const faces=jaws.map(jaw=>({jaw,tangent:pocketTangent(jaw,center,throatRadius,mouthCenter,outward)}));
		const bridge=convexHull([...jaws,...faces.map(face=>face.tangent)]);
		const circle=Array.from({length:48},(_,step)=>{
			const angle=step/48*Math.PI*2;return [center[0]+Math.cos(angle)*throatRadius,center[1]+Math.sin(angle)*throatRadius];
		});
		const openingProfile=convexHull([...circle,...jaws,...faces.map(face=>face.tangent)]);
		const throatSdf=(x,z)=>circleSdf(x,z,center[0],center[1],throatRadius);
		return {x:center[0],z:center[1],mouth,radius:throatRadius,outward,mouthCenter,jaws,faces,bridge,openingProfile,type
			, cutSdf:throatSdf
			, openingSdf:(x,z)=>Math.min(throatSdf(x,z),polygonSdf(x,z,bridge))};
	};
	const corner=(signX,signZ)=>{
		const outward=[signX*Math.SQRT1_2,signZ*Math.SQRT1_2],corner=[signX*width/2,signZ*height/2];
		return makePocket(corner.map((value,index)=>value-outward[index]*cornerMouth/2),outward,cornerMouth
			, corner.map((value,index)=>value+outward[index]*cushionWidth/Math.SQRT2),'corner');
	};
	const side=signZ=>{
		const outward=[0,signZ],mouthCenter=[0,signZ*height/2];
		return makePocket(mouthCenter,outward,sideMouth,[0,signZ*(height/2+cushionWidth)],'side');
	};
	return [corner(-1,-1),side(-1),corner(1,-1),corner(-1,1),side(1),corner(1,1)];
}

/** Project a screen pointer onto the horizontal cloth plane. */
export function tablePointFromPointer(clientX,clientY,bounds,camera,planeY=.012,fovy=.63)
{
	if(!bounds?.width||!bounds?.height)return null;
	const normalize=vector=>{const length=Math.hypot(...vector)||1;return vector.map(value=>value/length);};
	const cross=(a,b)=>[a[1]*b[2]-a[2]*b[1],a[2]*b[0]-a[0]*b[2],a[0]*b[1]-a[1]*b[0]];
	const {yaw,pitch,distance,target}=camera;
	const eye=[target[0]+Math.cos(yaw)*Math.cos(pitch)*distance,target[1]+Math.sin(pitch)*distance
		,target[2]+Math.sin(yaw)*Math.cos(pitch)*distance];
	const forward=normalize(target.map((value,index)=>value-eye[index]));
	const right=normalize(cross(forward,[0,1,0])),up=normalize(cross(right,forward));
	const ndcX=((clientX-bounds.left)/bounds.width)*2-1,ndcY=1-((clientY-bounds.top)/bounds.height)*2;
	const halfHeight=Math.tan(fovy/2),halfWidth=halfHeight*bounds.width/bounds.height;
	const direction=normalize(forward.map((value,index)=>value+right[index]*ndcX*halfWidth+up[index]*ndcY*halfHeight));

	if(Math.abs(direction[1])<1e-9)return null;

	const distanceToPlane=(planeY-eye[1])/direction[1];
	if(distanceToPlane<=0)return null;

	return [eye[0]+direction[0]*distanceToPlane,eye[2]+direction[2]*distanceToPlane];
}

/** Mirror the simulation's scratch-placement rules for immediate visual feedback. */
export function cuePlacementValid(state,x,y)
{
	if(!state?.table||!Number.isFinite(x)||!Number.isFinite(y))return false;
	const [width,height,radius,cornerMouth=.1158875,sideMouth=.1285875]=state.table;
	if(Math.abs(x)>width/2-radius*1.2||Math.abs(y)>height/2-radius*1.2)return false;

	for(const pocket of tablePocketDefinitions(width,height,cornerMouth,sideMouth))
	if(Math.hypot(x-pocket.x,y-pocket.z)<=pocketVoidRadius(pocket.radius))return false;

	for(const ball of state.balls??[])
	if(ball.number!==0&&ball.active&&Math.hypot(x-ball.x,y-ball.y)<radius*2.05)return false;

	return true;
}

export const billiardsTableMeshing = Object.freeze({
	columns:432
	, rows:240
	, cushionWidth:.055
	, cushionTopSegments:6
	, pocketSegments:96
	, tabletopTop:.012
	// Keep the leather annulus below the cloth so it cannot overpaint the cut.
	, pocketLinerTop:.0095
	// The analytic leather lip masks the sub-millimetre chord error from the
	// full-table marching grid and leaves a smooth circular visible hole.
	, pocketLipInset:.0025
});

/** Radius of the black void and logical liner inside the leather lip. */
export const pocketVoidRadius = throatRadius => throatRadius-billiardsTableMeshing.pocketLipInset;

/** Six constant-width rails ending on the shared tangent pocket facings. */
export function tableCushionPolygons(width,height,cornerMouth=.1158875,sideMouth=.1285875)
{
	const pockets=tablePocketDefinitions(width,height,cornerMouth,sideMouth),polygons=[];
	const faceAt=(pocket,predicate)=>pocket.faces.find(face=>predicate(face.jaw));
	const backPoint=(face,axis,value)=>{
		const amount=(value-face.jaw[axis])/(face.tangent[axis]-face.jaw[axis]);
		return face.jaw.map((coordinate,index)=>coordinate+(face.tangent[index]-coordinate)*amount);
	};
	const horizontal=(left,right,edge,outward)=>{
		const back=edge+outward*billiardsTableMeshing.cushionWidth;
		polygons.push([left.jaw,right.jaw,backPoint(right,1,back),backPoint(left,1,back)]);
	};
	const vertical=(bottom,top,edge,outward)=>{
		const back=edge+outward*billiardsTableMeshing.cushionWidth;
		polygons.push([bottom.jaw,backPoint(bottom,0,back),backPoint(top,0,back),top.jaw]);
	};
	for(const [cornerLeft,side,cornerRight,signZ] of [[pockets[0],pockets[1],pockets[2],-1],[pockets[3],pockets[4],pockets[5],1]])
	{
		const edge=signZ*height/2;
		horizontal(faceAt(cornerLeft,jaw=>Math.abs(jaw[1]-edge)<1e-9),faceAt(side,jaw=>jaw[0]<0),edge,signZ);
		horizontal(faceAt(side,jaw=>jaw[0]>0),faceAt(cornerRight,jaw=>Math.abs(jaw[1]-edge)<1e-9),edge,signZ);
	}
	for(const [cornerBottom,cornerTop,signX] of [[pockets[0],pockets[3],-1],[pockets[2],pockets[5],1]])
	{
		const edge=signX*width/2;
		vertical(faceAt(cornerBottom,jaw=>Math.abs(jaw[0]-edge)<1e-9),faceAt(cornerTop,jaw=>Math.abs(jaw[0]-edge)<1e-9),edge,signX);
	}

	return polygons.map(vertices=>{
		const area=vertices.reduce((sum,[x,z],index)=>{const [nextX,nextZ]=vertices[(index+1)%vertices.length];
			return sum+x*nextZ-nextX*z;},0);
		return area<0?[...vertices].reverse():vertices;
	});
}

/** Shared implicit profiles used to construct the table as nested solids and cuts. */
export function tableCsgProfiles(width,height,cornerMouth=.1158875,sideMouth=.1285875)
{
	const outerX=width/2+.17,outerZ=height/2+.17,bounds=[-outerX-.02,-outerZ-.02,outerX+.02,outerZ+.02];
	const pockets=tablePocketDefinitions(width,height,cornerMouth,sideMouth);
	const pocketSdf=(x,z)=>Math.min(...pockets.map(pocket=>pocket.cutSdf(x,z)));
	const outer=(x,z)=>roundedRectangleSdf(x,z,outerX,outerZ,.16);
	const cushionRails=tableCushionPolygons(width,height,cornerMouth,sideMouth);
	const cushionBand=(x,z)=>Math.min(...cushionRails.map(vertices=>polygonSdf(x,z,vertices)));
	const pocketCut=(x,z)=>pocketSdf(x,z);
	const tabletop=(x,z)=>roundedRectangleSdf(x,z,width/2+.052,height/2+.052,.072);
	const woodCut=(x,z)=>Math.min(tabletop(x,z),pocketCut(x,z));

	return {
		bounds
		, pockets
		, cushionRails
		, pocketCut
		, apron:(x,z)=>subtractSdf(outer(x,z),pocketCut(x,z))
		// Cloth continues beneath the cushions to form the solid shelf shown
		// between each jaw chord and its separate circular tabletop hole.
		, bed:(x,z)=>subtractSdf(tabletop(x,z),pocketCut(x,z))
		, wood:(x,z)=>subtractSdf(outer(x,z),woodCut(x,z))
		// The rail caps are explicit faces. Avoiding a Boolean at the pocket edge
		// keeps their topology and normals stable when pocket dimensions change.
		, cushion:cushionBand
	};
}

/** Identify a ball close enough in the shot direction to take the cue impulse immediately. */
export function cueContactWarning(balls,ballRadius,angle,threshold=.0012)
{
	const cue=balls.find(ball=>ball.number===0&&ball.active);
	if(!cue)return null;
	const dx=Math.cos(angle),dy=Math.sin(angle);
	let nearest=null,nearestGap=Infinity;

	for(const ball of balls)if(ball.number!==0&&ball.active)
	{
		const offsetX=ball.x-cue.x,offsetY=ball.y-cue.y,distance=Math.hypot(offsetX,offsetY);
		if(distance<1e-9)continue;
		const gap=distance-ballRadius*2,alignment=(offsetX*dx+offsetY*dy)/distance;
		if(gap<=threshold&&alignment>.7&&gap<nearestGap){nearest=ball.number;nearestGap=gap;}
	}

	return nearest;
}

/** roughness, index of refraction, material mode, clearcoat or sheen strength */
export const billiardsMaterials = Object.freeze({
	ball:Object.freeze([.03,1.56,1,.35])
	, wood:Object.freeze([.5,1.52,2,1])
	, cueWood:Object.freeze([.42,1.52,6,1])
	, felt:Object.freeze([.9,1.5,3,.85])
	, cushionFelt:Object.freeze([.93,1.5,3,1.15])
});
export const billiardsEdgeRadii = Object.freeze({wood:.018,cushion:.006,cushionBottom:0});

/** Direct browser shell and WebGL2 renderer for PHP Billiards. */
export function activate({module, canvas, session})
{
	const gl = canvas.getContext('webgl2', {antialias: true, alpha: false, depth: true});

	if(!gl) throw Error('PHP Billiards requires WebGL2');

	const parent = canvas.parentNode, next = canvas.nextSibling, originalStyle = canvas.getAttribute('style');
	const abort = new AbortController, {signal} = abort;
	const ui = document.createElement('section');
	const screen = document.createElement('div');
	const panel = document.createElement('form');
	const title = document.createElement('header');
	const status = document.createElement('p');
	const live = document.createElement('p');
	const quick = document.createElement('div');
	const mute = document.createElement('button');
	const fullscreen = document.createElement('button');
	const style = document.createElement('style');
	ui.className = 'php-billiards-ui';
	screen.className = 'php-billiards-screen';
	panel.className = 'php-billiards-panel';
	title.innerHTML = '<strong>PHP BILLIARDS</strong><span>3D practice table</span>';
	status.className = 'php-billiards-status';
	live.className = 'php-billiards-sr';
	live.setAttribute('aria-live', 'polite');
	quick.className = 'php-billiards-quick';
	mute.type = fullscreen.type = 'button';
	mute.textContent = 'SOUND';
	mute.setAttribute('aria-label', 'Mute sound');
	fullscreen.textContent = 'FULL';
	fullscreen.setAttribute('aria-label', 'Enter fullscreen');
	quick.append(mute, fullscreen);
	style.textContent = `
		.php-billiards-ui{position:relative;display:grid;grid-template-columns:minmax(0,1fr) 238px;width:100%;height:100%;min-height:0;
			background:#050807;color:#eef7f0;font-family:Inter,ui-sans-serif,system-ui,sans-serif;
			box-shadow:0 24px 80px #000a;overflow:hidden}
		.php-billiards-screen{position:relative;min-width:0;min-height:0;width:100%;height:100%;background:radial-gradient(circle at 50% 30%,#17241e,#020403 68%)}
		.php-billiards-screen>canvas{display:block;width:100%;height:100%;touch-action:none;outline:none}
		.php-billiards-screen>canvas[data-placing=true]{cursor:crosshair}
		.php-billiards-panel{display:flex;flex-direction:column;gap:12px;padding:18px;background:linear-gradient(160deg,#17221d,#090d0b);
			border-left:1px solid #b99b5c42;box-sizing:border-box}
		.php-billiards-panel header{display:flex;flex-direction:column;gap:2px;padding-bottom:8px;border-bottom:1px solid #d0ba8240}
		.php-billiards-panel header strong{font:700 17px/1.1 Georgia,serif;letter-spacing:.13em;color:#f2db9b}
		.php-billiards-panel header span,.php-billiards-status{font-size:12px;color:#aebbb2}
		.php-billiards-panel label{display:grid;grid-template-columns:1fr auto;gap:5px;font-size:12px;letter-spacing:.04em;color:#dce6df}
		.php-billiards-panel label output{font-variant-numeric:tabular-nums;color:#e8cd87}
		.php-billiards-panel input[type=range]{grid-column:1/-1;width:100%;accent-color:#d8b763}
		.php-billiards-actions{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-top:3px}
		.php-billiards-panel button{min-height:38px;border:1px solid #d7bd7f5c;border-radius:5px;background:#20372d;color:#f5f6f1;
			font:700 11px/1 system-ui;letter-spacing:.07em;cursor:pointer}
		.php-billiards-panel button:hover,.php-billiards-panel button:focus-visible{background:#315645;outline:2px solid #e9cb80;outline-offset:1px}
		.php-billiards-panel button[data-primary]{grid-column:1/-1;background:#9b7135;color:#fff9ea;border-color:#e5ca8a}
		.php-billiards-panel button:disabled,.php-billiards-panel input:disabled{opacity:.42;cursor:not-allowed}
		.php-billiards-placement{padding:10px;border:1px solid #d7bd7f40;border-radius:5px;background:#0003;color:#dce6df;
			font-size:12px;line-height:1.45}
		.php-billiards-placement[hidden]{display:none}
		.php-billiards-quick{position:absolute;right:10px;top:10px;display:flex;gap:6px;z-index:4}
		.php-billiards-quick button{min-width:48px;height:30px;border:1px solid #ffffff44;border-radius:4px;background:#07110dcc;color:#fff;
			font:700 9px/1 system-ui;letter-spacing:.08em}
		.php-billiards-sr{position:absolute!important;width:1px!important;height:1px!important;padding:0!important;margin:-1px!important;
			overflow:hidden!important;clip-path:inset(50%)!important;white-space:nowrap!important;border:0!important}
		.php-billiards-ui:fullscreen{width:100vw;height:100vh;background:#020403}
		.php-billiards-ui:fullscreen .php-billiards-screen{width:100%;height:100%}
		[data-game-embed=true] .php-billiards-ui{width:100%;height:100%;max-width:none}
		@media(max-width:760px){.php-billiards-ui{display:block}.php-billiards-panel{position:absolute;left:8px;right:8px;bottom:8px;display:grid;
			grid-template-columns:repeat(4,1fr);gap:6px;padding:8px;background:#07100ee8;border:1px solid #ceb77655;border-radius:6px}
			.php-billiards-panel header,.php-billiards-status{display:none}.php-billiards-panel label{font-size:10px}.php-billiards-actions{display:contents}
			.php-billiards-panel button{min-height:34px}.php-billiards-placement{grid-column:1/-1}
			.php-billiards-screen{width:100%;height:100%;min-height:0}}
	`;

	parent.insertBefore(ui, canvas);
	ui.append(style, screen, panel, live);
	screen.append(canvas, quick);
	panel.append(title);
	canvas.tabIndex = Math.max(0, canvas.tabIndex);

	const field = (name, label, min, max, step, value) => {
		const root = document.createElement('label');
		const text = document.createElement('span');
		const output = document.createElement('output');
		const input = document.createElement('input');
		text.textContent = label;
		input.type = 'range';
		Object.assign(input, {name, min: String(min), max: String(max), step: String(step), value: String(value)});
		root.append(text, output, input);
		const update = () => output.value = name === 'aim' ? `${Math.round(Number(input.value))}°`
			: `${Math.round(Number(input.value) * 100)}%`;
		input.addEventListener('input', update, {signal});
		update();
		panel.append(root);

		return input;
	};

	const aim = field('aim', 'Aim', -180, 180, .5, 0);
	const power = field('power', 'Power', .05, 1, .01, .58);
	const top = field('top', 'Top / back spin', -1, 1, .02, 0);
	const side = field('side', 'Side spin', -1, 1, .02, 0);
	const placement = document.createElement('div');
	placement.className = 'php-billiards-placement';
	placement.hidden = true;
	placement.textContent = 'Move over the cloth and click or tap to place the cue ball.';
	panel.append(placement);
	const actions = document.createElement('div');
	actions.className = 'php-billiards-actions';
	const shoot = document.createElement('button');
	const reset = document.createElement('button');
	const rack = document.createElement('button');
	shoot.type = reset.type = rack.type = 'button';
	shoot.textContent = 'SHOOT'; shoot.dataset.primary = '';
	reset.textContent = 'RESET SHOT'; rack.textContent = 'RACK';
	actions.append(shoot, reset, rack); panel.append(actions, status);

	const actionsQueue = [], held = new Set;
	const enqueue = action => { if(!actionsQueue.includes(action)) actionsQueue.push(action); canvas.focus({preventScroll: true}); };
	shoot.addEventListener('click', () => enqueue('shoot'), {signal});
	reset.addEventListener('click', () => enqueue('reset'), {signal});
	rack.addEventListener('click', () => enqueue('rack'), {signal});

	canvas.addEventListener('keydown', event => {
		if(['Space','ArrowLeft','ArrowRight','ArrowUp','ArrowDown','KeyR','Backspace'].includes(event.code)) event.preventDefault();
		held.add(event.code);

		if(event.repeat) return;

		if(event.code === 'Space') enqueue('shoot');
		if(event.code === 'KeyR') enqueue('rack');
		if(event.code === 'Backspace') enqueue('reset');
		if(event.code === 'Enter' && placement.hidden === false&&cuePlacementValid(latest,...placementCandidate))
		{
			pendingPlacement=[...placementCandidate];
			enqueue('place');
		}
	}, {signal});
	canvas.addEventListener('keyup', event => held.delete(event.code), {signal});
	canvas.addEventListener('blur', () => held.clear(), {signal});

	let dragging = null;
	const camera = {yaw: -.42, pitch: .72, distance: 3.35, target: [0,0,0]};
	let placementCandidate = [-.65,0], pendingPlacement = null, previousPhase = null;
	const updatePlacement = event => {
		const point=tablePointFromPointer(event.clientX,event.clientY,canvas.getBoundingClientRect(),camera);
		if(point)placementCandidate=point;
		return point;
	};
	canvas.addEventListener('pointerdown', event => {
		canvas.focus({preventScroll: true});
		if(latest?.phase==='placing')
		{
			if(updatePlacement(event)&&cuePlacementValid(latest,...placementCandidate))
			{
				pendingPlacement=[...placementCandidate];
				enqueue('place');
			}
			return;
		}
		canvas.setPointerCapture(event.pointerId);
		dragging = {id: event.pointerId,x: event.clientX,y: event.clientY};
	}, {signal});
	canvas.addEventListener('pointermove', event => {
		if(latest?.phase==='placing'){updatePlacement(event);return;}
		if(!dragging || dragging.id !== event.pointerId) return;

		const bounds=canvas.getBoundingClientRect(),targetY=bounds.top+bounds.height/2;
		camera.yaw += orbitYawDelta(event.clientX-dragging.x,event.clientY,targetY);
		camera.pitch = Math.max(.28, Math.min(1.18, camera.pitch + (event.clientY-dragging.y)*.005));
		dragging.x=event.clientX;dragging.y=event.clientY;
	}, {signal});
	const release = event => { if(dragging?.id === event.pointerId) dragging = null; };
	canvas.addEventListener('pointerup', release, {signal});
	canvas.addEventListener('pointercancel', release, {signal});
	canvas.addEventListener('wheel', event => {
		event.preventDefault();
		camera.distance = Math.max(.85, Math.min(5.2, camera.distance * Math.exp(event.deltaY * .001)));
	}, {signal, passive: false});
	canvas.addEventListener('contextmenu', event => event.preventDefault(), {signal});
	canvas.addEventListener('webglcontextlost', event => event.preventDefault(), {signal});
	canvas.addEventListener('webglcontextrestored', () => location.reload(), {signal});

	let muted = false, fullscreenBusy = false, audio = null;
	mute.addEventListener('click', () => {
		muted = !muted;
		mute.textContent = muted ? 'MUTED' : 'SOUND';
		mute.setAttribute('aria-label', muted ? 'Unmute sound' : 'Mute sound');
	}, {signal});
	fullscreen.addEventListener('click', async() => {
		if(fullscreenBusy) return;

		fullscreenBusy = true;

		try
		{
			if(document.fullscreenElement === ui) await document.exitFullscreen();
			else await ui.requestFullscreen();
		}
		finally { fullscreenBusy = false; canvas.focus({preventScroll: true}); }
	}, {signal});
	document.addEventListener('fullscreenchange', () => {
		fullscreen.textContent = document.fullscreenElement === ui ? 'EXIT' : 'FULL';
		fullscreen.setAttribute('aria-label', document.fullscreenElement === ui ? 'Exit fullscreen' : 'Enter fullscreen');
	}, {signal});

	const tone = (frequency, gain, duration) => {
		if(muted) return;

		audio ??= new AudioContext;
		const oscillator = audio.createOscillator(), volume = audio.createGain();
		oscillator.type = 'triangle'; oscillator.frequency.value = frequency;
		volume.gain.setValueAtTime(Math.max(.001, gain), audio.currentTime);
		volume.gain.exponentialRampToValueAtTime(.001, audio.currentTime + duration);
		oscillator.connect(volume).connect(audio.destination);
		oscillator.start(); oscillator.stop(audio.currentTime + duration);
	};

	const compile = (type, source) => {
		const shader = gl.createShader(type); gl.shaderSource(shader, source); gl.compileShader(shader);

		if(!gl.getShaderParameter(shader, gl.COMPILE_STATUS)) throw Error(gl.getShaderInfoLog(shader));

		return shader;
	};
	const program = (() => {
		const result = gl.createProgram();
		gl.attachShader(result, compile(gl.VERTEX_SHADER, `#version 300 es
			layout(location=0) in vec3 aPosition; layout(location=1) in vec3 aNormal; layout(location=2) in vec2 aUv;
			uniform mat4 uModel; uniform mat4 uViewProjection; uniform mat3 uNormal;
			out vec3 vPosition; out vec3 vNormal; out vec2 vUv;
			void main(){vec4 world=uModel*vec4(aPosition,1.0);vPosition=world.xyz;vNormal=normalize(uNormal*aNormal);vUv=aUv;
			gl_Position=uViewProjection*world;}`));
		gl.attachShader(result, compile(gl.FRAGMENT_SHADER, `#version 300 es
			precision highp float; in vec3 vPosition; in vec3 vNormal; in vec2 vUv;
			uniform vec4 uBase; uniform vec3 uEye; uniform vec4 uMaterial; uniform vec2 uAtlasCell;
			uniform sampler2D uBallAtlas; uniform sampler2D uWood; out vec4 color;
			float distribution(float nDotH,float rough){float a=rough*rough;float a2=a*a;float d=nDotH*nDotH*(a2-1.0)+1.0;return a2/(3.14159*d*d);}
			float random(vec3 p){return fract(sin(dot(p,vec3(12.9898,78.233,37.719)))*43758.5453);}
			float noise3(vec3 p){vec3 cell=floor(p),f=fract(p);f=f*f*(3.0-2.0*f);
				float n000=random(cell),n100=random(cell+vec3(1.0,0.0,0.0)),n010=random(cell+vec3(0.0,1.0,0.0)),n110=random(cell+vec3(1.0,1.0,0.0));
				float n001=random(cell+vec3(0.0,0.0,1.0)),n101=random(cell+vec3(1.0,0.0,1.0)),n011=random(cell+vec3(0.0,1.0,1.0)),n111=random(cell+vec3(1.0));
				return mix(mix(mix(n000,n100,f.x),mix(n010,n110,f.x),f.y),mix(mix(n001,n101,f.x),mix(n011,n111,f.x),f.y),f.z);}
			float feltMacro(vec3 p){return .5+(noise3(p*11.0)-.5)*.4+(noise3(p*27.0+vec3(8.1,2.7,5.4))-.5)*.29
				+(noise3(p*61.0+vec3(3.2,9.4,1.8))-.5)*.13;}
			vec3 bumpNormal(vec3 position,vec3 normal,float height){vec3 sigmaX=dFdx(position),sigmaY=dFdy(position);
				vec3 r1=cross(sigmaY,normal),r2=cross(normal,sigmaX);float determinant=dot(sigmaX,r1);
				vec3 gradient=sign(determinant)*(dFdx(height)*r1+dFdy(height)*r2);
				return normalize(abs(determinant)*normal-gradient);}
			vec3 wood(vec3 p,vec3 n){vec3 blend=pow(abs(n),vec3(6.0));blend/=max(.001,blend.x+blend.y+blend.z);
				vec3 x=texture(uWood,p.zy*vec2(2.7,7.1)).rgb,y=texture(uWood,p.xz*vec2(2.7,7.1)).rgb;
				vec3 z=texture(uWood,p.xy*vec2(2.7,7.1)).rgb;return x*blend.x+y*blend.y+z*blend.z;}
			vec3 fresnel(vec3 f0,float vDotH){return f0+(1.0-f0)*pow(1.0-vDotH,5.0);}
			vec3 lightPbr(vec3 lightPosition,vec3 lightColor,float intensity,vec3 N,vec3 V,vec3 base,vec3 f0,float rough,float lightSize){
				rough=sqrt(rough*rough+lightSize*lightSize);
				vec3 L=normalize(lightPosition-vPosition),H=normalize(L+V);float nl=max(dot(N,L),0.0),nv=max(dot(N,V),.001);
				float nh=max(dot(N,H),0.0),vh=max(dot(V,H),0.0);vec3 F=fresnel(f0,vh);float D=distribution(nh,rough);
				float k=(rough+1.0)*(rough+1.0)/8.0,G=(nl/(nl*(1.0-k)+k))*(nv/(nv*(1.0-k)+k));
				vec3 spec=D*G*F/max(.01,4.0*nl*nv),diffuse=(1.0-F)*base/3.14159;
				vec3 diffuseLight=diffuse*nl*lightColor*intensity,specularLight=spec*nl*lightColor*intensity;
				return diffuseLight+.55*specularLight/(1.0+specularLight);}
			float coatSpec(vec3 lightPosition,vec3 N,vec3 V,float rough,float lightSize){rough=sqrt(rough*rough+lightSize*lightSize);
				vec3 L=normalize(lightPosition-vPosition),H=normalize(L+V);
				float nl=max(dot(N,L),0.0),nv=max(dot(N,V),.001),nh=max(dot(N,H),0.0),vh=max(dot(V,H),0.0);
				float F=.04+.96*pow(1.0-vh,5.0),D=distribution(nh,rough),k=(rough+1.0)*(rough+1.0)/8.0;
				float G=(nl/(nl*(1.0-k)+k))*(nv/(nv*(1.0-k)+k));return D*G*F*nl/max(.01,4.0*nl*nv);}
			void main(){vec3 N=normalize(vNormal),V=normalize(uEye-vPosition),base=uBase.rgb;float mode=uMaterial.z;
				if(mode>3.5&&mode<4.5){float edge=length(vUv-.5)*2.0,opacity=(1.0-smoothstep(.18,1.0,edge))*uBase.a;
					if(opacity<.002)discard;color=vec4(vec3(0.0),opacity);return;}
				if(mode>0.5&&mode<1.5){vec2 pad=vec2(4.0)/vec2(textureSize(uBallAtlas,0));
					// Raw atlas rows run opposite WebGL's vertical axis; longitude must stay unchanged to preserve numeral chirality.
					vec2 uv=mix(uAtlasCell/4.0+pad,(uAtlasCell+1.0)/4.0-pad,vec2(vUv.x,1.0-vUv.y));base=texture(uBallAtlas,uv).rgb;}
				else if((mode>1.5&&mode<2.5)||mode>5.5){base*=wood(vPosition,N)*1.45;
					if(mode>5.5){float phase=fract(vUv.y+1.0/24.0)*12.0,sector=floor(phase),across=abs(fract(phase)-.5)*2.0;
						float rear=1.0-step(1.0,mod(sector,2.0)),progress=mix((.22-vUv.x)/.095,(vUv.x-.03)/.095,rear);
						float inside=step(0.0,progress)*step(progress,1.0),edge=max(fwidth(across)*1.5,.008);
						float point=inside*(1.0-smoothstep(1.0-progress-edge,1.0-progress+edge,across));
						vec3 blueDye=base*vec3(.16,.36,.78)+vec3(.012,.045,.14);base=mix(base,blueDye,point*.84);}}
				else if(mode>2.5&&mode<3.5){float macro=feltMacro(vPosition),grain=noise3(vPosition*430.0+vec3(4.7,1.3,9.1));
					float looseFiber=smoothstep(.68,.9,noise3(vPosition*760.0+vec3(2.1,7.4,3.8)));
					float height=(macro-.5)*.00055+(grain-.5)*.00016+looseFiber*.00007;
					N=bumpNormal(vPosition,N,height);float top=smoothstep(.62,.92,N.y);
					float track=exp(-pow(abs(vPosition.z-.055*sin(vPosition.x*5.0))*18.0,2.0))*top;
					base*=.92+macro*.13+(grain-.5)*.045+looseFiber*.035;base=mix(base,base*1.04,track*.035);}
				float rough=uMaterial.x;if(mode>0.5&&mode<1.5)rough=clamp(rough+(random(floor(vPosition*900.0))-.5)*.012,.01,.05);
				float ior=uMaterial.y>1.0?uMaterial.y:1.5,f0Value=pow((ior-1.0)/(ior+1.0),2.0),nv=max(dot(N,V),.001);
				bool metallic=mode>4.5&&mode<5.5;
				vec3 f0=metallic?mix(vec3(.04),base,.82):vec3(f0Value),key=vec3(-.45,2.8,-.7),fill=vec3(1.8,1.45,2.15);
				float skyAmbient=clamp(N.y*.5+.5,0.0,1.0);
				vec3 ambient=mix(vec3(.15,.21,.17),vec3(.25,.27,.3),skyAmbient);
				vec3 diffuseBase=metallic?base*.14:base;
				vec3 lit=diffuseBase*ambient+lightPbr(key,vec3(1.0,.94,.82),3.7,N,V,diffuseBase,f0,rough,.14)
					+lightPbr(fill,vec3(.58,.72,1.0),.58,N,V,diffuseBase,f0,rough,.18);
				if(mode>0.5&&mode<1.5){lit+=base*.035*(1.0-max(dot(N,normalize(key-vPosition)),0.0));
					float coat=coatSpec(key,N,V,.018,.16)*3.7+coatSpec(fill,N,V,.028,.2)*.58;
					lit+=vec3(.55*coat/(1.0+coat))*uMaterial.w;}
				else if((mode>1.5&&mode<2.5)||mode>5.5){float coat=coatSpec(key,N,V,.11,.12)*3.7+coatSpec(fill,N,V,.14,.16)*.58;
					lit+=vec3(.55*coat/(1.0+coat))*uMaterial.w;}
				else if(mode>2.5&&mode<3.5){float backLight=max(dot(-N,normalize(key-vPosition)),0.0);
					vec3 fiberTint=mix(base,sqrt(max(base,vec3(0.0))),.28);
					lit+=base*(.04+.055*backLight)+fiberTint*pow(1.0-nv,1.8)*uMaterial.w*.68;}
				else lit+=base*pow(1.0-nv,3.0)*.08;
				color=vec4(lit,uBase.a);}`));
		gl.linkProgram(result);

		if(!gl.getProgramParameter(result, gl.LINK_STATUS)) throw Error(gl.getProgramInfoLog(result));

		return result;
	})();
	const locations = Object.fromEntries(['uModel','uViewProjection','uNormal','uBase','uEye','uMaterial','uAtlasCell','uBallAtlas','uWood']
		.map(name => [name,gl.getUniformLocation(program, name)]));

	const mesh = (vertices, indices) => {
		const vao = gl.createVertexArray(), buffer = gl.createBuffer(), elements = gl.createBuffer();
		gl.bindVertexArray(vao); gl.bindBuffer(gl.ARRAY_BUFFER, buffer);
		gl.bufferData(gl.ARRAY_BUFFER, new Float32Array(vertices), gl.STATIC_DRAW);
		gl.bindBuffer(gl.ELEMENT_ARRAY_BUFFER, elements);
		gl.bufferData(gl.ELEMENT_ARRAY_BUFFER, new Uint32Array(indices), gl.STATIC_DRAW);
		for(const [location,size,offset] of [[0,3,0],[1,3,12],[2,2,24]])
		{
			gl.enableVertexAttribArray(location); gl.vertexAttribPointer(location,size,gl.FLOAT,false,32,offset);
		}
		gl.bindVertexArray(null);

		return {vao,buffer,elements,count: indices.length,type: gl.UNSIGNED_INT};
	};
	/** Extrude a composed two-dimensional signed-distance field into one watertight mesh. */
	const extrudeCsg = (field,bounds,columns,rows,bottom,top) => {
		const [minX,minZ,maxX,maxZ]=bounds,stepX=(maxX-minX)/columns,stepZ=(maxZ-minZ)/rows;
		const vertices=[],indices=[],epsilon=Math.min(stepX,stepZ)*.7;
		const point=(x,z)=>({x,z,distance:field(x,z)});
		const interpolate=(a,b)=>{const amount=a.distance/(a.distance-b.distance);return {
			x:a.x+(b.x-a.x)*amount,z:a.z+(b.z-a.z)*amount,distance:0
		};};
		const unique=points=>points.filter((entry,index)=>points.findIndex(candidate=>
			Math.hypot(entry.x-candidate.x,entry.z-candidate.z)<1e-9)===index);
		const endpoints=points=>{let pair=[],distance=-1;
			for(let a=0;a<points.length;a++)for(let b=a+1;b<points.length;b++)
			{
				const candidate=(points[a].x-points[b].x)**2+(points[a].z-points[b].z)**2;
				if(candidate>distance){distance=candidate;pair=[points[a],points[b]];}
			}
			return pair;
		};
		const vertex=(entry,y,normal,u,v)=>{const index=vertices.length/8;vertices.push(entry.x,y,entry.z,...normal,u,v);return index;};
		const gradient=entry=>{const x=field(entry.x+epsilon,entry.z)-field(entry.x-epsilon,entry.z);
			const z=field(entry.x,entry.z+epsilon)-field(entry.x,entry.z-epsilon),length=Math.hypot(x,z)||1;return [x/length,z/length];};

		const addSurface=(polygon,y,normal,reverse) => {
			for(let index=1;index<polygon.length-1;index++)
			{
				const points=reverse?[polygon[0],polygon[index+1],polygon[index]]:[polygon[0],polygon[index],polygon[index+1]];
				for(const entry of points)indices.push(vertex(entry,y,normal,(entry.x-minX)/(maxX-minX),(entry.z-minZ)/(maxZ-minZ)));
			}
		};
		const addWall=(first,second) => {
			let a=first,b=second,dx=b.x-a.x,dz=b.z-a.z;
			const midpoint={x:(a.x+b.x)/2,z:(a.z+b.z)/2},[nx,nz]=gradient(midpoint);

			if(-dz*nx+dx*nz<0)[a,b]=[b,a];

			const normalA=gradient(a),normalB=gradient(b),base=vertices.length/8;
			vertices.push(a.x,bottom,a.z,normalA[0],0,normalA[1],0,0);
			vertices.push(b.x,bottom,b.z,normalB[0],0,normalB[1],1,0);
			vertices.push(b.x,top,b.z,normalB[0],0,normalB[1],1,1);
			vertices.push(a.x,top,a.z,normalA[0],0,normalA[1],0,1);
			indices.push(base,base+1,base+2,base,base+2,base+3);
		};

		for(let row=0;row<rows;row++)for(let column=0;column<columns;column++)
		{
			const left=minX+column*stepX,right=left+stepX,near=minZ+row*stepZ,far=near+stepZ;
			const corners=[point(left,near),point(right,near),point(right,far),point(left,far)];
			const center=point((left+right)/2,(near+far)/2);

			for(let corner=0;corner<4;corner++)
			{
				const triangle=[corners[corner],corners[(corner+1)%4],center],polygon=[],crossings=[];

				for(let edge=0;edge<3;edge++)
				{
					const a=triangle[edge],b=triangle[(edge+1)%3],inside=a.distance<=0,nextInside=b.distance<=0;
					if(inside)polygon.push(a);
					if(Math.abs(a.distance)<1e-10)crossings.push(a);
					if(inside!==nextInside){const crossing=interpolate(a,b);polygon.push(crossing);crossings.push(crossing);}
				}

				const clipped=unique(polygon),boundary=endpoints(unique(crossings));
				if(clipped.length>=3){addSurface(clipped,top,[0,1,0],true);addSurface(clipped,bottom,[0,-1,0],false);}
				if(boundary.length===2)addWall(boundary[0],boundary[1]);
			}
		}

		return mesh(vertices,indices);
	};

	/** Sweep explicit quarter-round bands around a high-resolution CSG contour. */
	const extrudeRoundedCsg = (field,bounds,columns,rows,bottom,top,radius,roundSegments=6,bottomRadius=radius) => {
		const [minX,minZ,maxX,maxZ]=bounds,stepX=(maxX-minX)/columns,stepZ=(maxZ-minZ)/rows;
		const vertices=[],indices=[],epsilon=Math.min(stepX,stepZ)*.7;
		const point=(x,z,offset=0)=>({x,z,distance:field(x,z)+offset});
		const interpolate=(a,b)=>{const amount=a.distance/(a.distance-b.distance);return {
			x:a.x+(b.x-a.x)*amount,z:a.z+(b.z-a.z)*amount,distance:0
		};};
		const unique=points=>points.filter((entry,index)=>points.findIndex(candidate=>
			Math.hypot(entry.x-candidate.x,entry.z-candidate.z)<1e-9)===index);
		const endpoints=points=>{let pair=[],distance=-1;
			for(let a=0;a<points.length;a++)for(let b=a+1;b<points.length;b++)
			{
				const candidate=(points[a].x-points[b].x)**2+(points[a].z-points[b].z)**2;
				if(candidate>distance){distance=candidate;pair=[points[a],points[b]];}
			}
			return pair;
		};
		const gradient=entry=>{const x=field(entry.x+epsilon,entry.z)-field(entry.x-epsilon,entry.z);
			const z=field(entry.x,entry.z+epsilon)-field(entry.x,entry.z-epsilon),length=Math.hypot(x,z)||1;return [x/length,z/length];};
		const addVertex=(entry,y,normal)=>{const index=vertices.length/8;vertices.push(entry.x,y,entry.z,...normal,
			(entry.x-minX)/(maxX-minX),(entry.z-minZ)/(maxZ-minZ));return index;};
		const addQuad=(a,b,c,d)=>{const base=vertices.length/8;
			for(const entry of [a,b,c,d])vertices.push(entry.x,entry.y,entry.z,...entry.normal,
				(entry.x-minX)/(maxX-minX),(entry.z-minZ)/(maxZ-minZ));
			indices.push(base,base+1,base+2,base,base+2,base+3);
		};
		const insetDistance=(entry,edgeRadius)=>{
			if(edgeRadius<=0)return 0;
			entry.insets??=new Map;
			if(entry.insets.has(edgeRadius))return entry.insets.get(edgeRadius);
			const [nx,nz]=gradient(entry),target=-edgeRadius;
			let low=0,high=edgeRadius,value=field(entry.x-nx*high,entry.z-nz*high);
			while(value>target&&high<edgeRadius*6){high*=1.5;value=field(entry.x-nx*high,entry.z-nz*high);}
			if(value<=target)for(let iteration=0;iteration<12;iteration++)
			{
				const middle=(low+high)/2,middleValue=field(entry.x-nx*middle,entry.z-nz*middle);
				if(middleValue>target)low=middle;else high=middle;
			}
			else high=edgeRadius;
			entry.insets.set(edgeRadius,high);return high;
		};
		const ringPoint=(entry,angle,yDirection)=>{
			const edgeRadius=yDirection>0?radius:bottomRadius,[nx,nz]=gradient(entry),horizontalRadius=insetDistance(entry,edgeRadius);
			const inset=horizontalRadius*(1-Math.cos(angle));
			return {x:entry.x-nx*inset,z:entry.z-nz*inset,
				y:yDirection>0?top-radius+Math.sin(angle)*radius:bottom+bottomRadius-Math.sin(angle)*bottomRadius,
				normal:roundedFilletNormal(nx,nz,angle,horizontalRadius,edgeRadius,yDirection)};
		};
		const addRoundedWall=(first,second)=>{
			let a=first,b=second,dx=b.x-a.x,dz=b.z-a.z;
			const midpoint={x:(a.x+b.x)/2,z:(a.z+b.z)/2},[nx,nz]=gradient(midpoint);
			if(-dz*nx+dx*nz<0)[a,b]=[b,a];
			const normalA=gradient(a),normalB=gradient(b);
			addQuad(
				{x:a.x,y:bottom+bottomRadius,z:a.z,normal:[normalA[0],0,normalA[1]]}
				, {x:b.x,y:bottom+bottomRadius,z:b.z,normal:[normalB[0],0,normalB[1]]}
				, {x:b.x,y:top-radius,z:b.z,normal:[normalB[0],0,normalB[1]]}
				, {x:a.x,y:top-radius,z:a.z,normal:[normalA[0],0,normalA[1]]}
			);
			for(let segment=0;segment<roundSegments;segment++)
			{
				const angle=segment/roundSegments*Math.PI/2,next=(segment+1)/roundSegments*Math.PI/2;
				addQuad(ringPoint(a,angle,1),ringPoint(b,angle,1),ringPoint(b,next,1),ringPoint(a,next,1));
				if(bottomRadius>0)addQuad(ringPoint(a,next,-1),ringPoint(b,next,-1),ringPoint(b,angle,-1),ringPoint(a,angle,-1));
			}
		};
		const addCap=(polygon,y,normal,reverse)=>{
			for(let index=1;index<polygon.length-1;index++)
			{
				const entries=reverse?[polygon[0],polygon[index+1],polygon[index]]:[polygon[0],polygon[index],polygon[index+1]];
				for(const entry of entries)indices.push(addVertex(entry,y,normal));
			}
		};

		for(let row=0;row<rows;row++)for(let column=0;column<columns;column++)
		{
			const left=minX+column*stepX,right=left+stepX,near=minZ+row*stepZ,far=near+stepZ;
			for(const layer of [{offset:0,wall:true},{offset:radius,top:true},{offset:bottomRadius,bottom:true}])
			{
				const {offset}=layer;
				const corners=[point(left,near,offset),point(right,near,offset),point(right,far,offset),point(left,far,offset)];
				const center=point((left+right)/2,(near+far)/2,offset);
				for(let corner=0;corner<4;corner++)
				{
					const triangle=[corners[corner],corners[(corner+1)%4],center],polygon=[],crossings=[];
					for(let edge=0;edge<3;edge++)
					{
						const a=triangle[edge],b=triangle[(edge+1)%3],inside=a.distance<=0,nextInside=b.distance<=0;
						if(inside)polygon.push(a);
						if(Math.abs(a.distance)<1e-10)crossings.push(a);
						if(inside!==nextInside){const crossing=interpolate(a,b);polygon.push(crossing);crossings.push(crossing);}
					}
					const clipped=unique(polygon),boundary=endpoints(unique(crossings));
					if(layer.top&&clipped.length>=3)addCap(clipped,top,[0,1,0],true);
					if(layer.bottom&&clipped.length>=3)addCap(clipped,bottom,[0,-1,0],false);
					if(layer.wall&&boundary.length===2)addRoundedWall(boundary[0],boundary[1]);
				}
			}
		}

		return mesh(vertices,indices);
	};

	/** Build each felt rail from analytic rings instead of rounding a Boolean contour. */
	const buildCushionMesh = (polygons,bounds,bottom=.013,top=.083,radius=billiardsEdgeRadii.cushion) => {
		const [minX,minZ,maxX,maxZ]=bounds,vertices=[],indices=[];
		const add=([x,z],y,[nx,ny,nz])=>{const index=vertices.length/8;
			vertices.push(x,y,z,nx,ny,nz,(x-minX)/(maxX-minX),(z-minZ)/(maxZ-minZ));return index;};
		const quad=(a,b,c,d)=>indices.push(a,b,c,a,c,d);

		for(const source of polygons)
		{
			const polygon=[...source],count=polygon.length;
			const edgeNormals=polygon.map(([x,z],index)=>{
				const [nextX,nextZ]=polygon[(index+1)%count],dx=nextX-x,dz=nextZ-z,length=Math.hypot(dx,dz);
				return [dz/length,-dx/length];
			});
			const cornerNormals=edgeNormals.map((normal,index)=>{
				const previous=edgeNormals[(index+count-1)%count],x=previous[0]+normal[0],z=previous[1]+normal[1];
				const length=Math.hypot(x,z)||1;return [x/length,z/length];
			});

			// Flat side faces retain one exact normal, including both angled pocket facings.
			for(let edge=0;edge<count;edge++)
			{
				const next=(edge+1)%count,[nx,nz]=edgeNormals[edge];
				const lowerA=add(polygon[edge],bottom,[nx,0,nz]),upperA=add(polygon[edge],top-radius,[nx,0,nz]);
				const upperB=add(polygon[next],top-radius,[nx,0,nz]),lowerB=add(polygon[next],bottom,[nx,0,nz]);
				quad(lowerA,upperA,upperB,lowerB);
			}

			// Insetting the whole convex polygon for every ring guarantees matching
			// vertices at the corners; no independently generated edge can tear away.
			const rings=[];
			for(let segment=0;segment<=billiardsTableMeshing.cushionTopSegments;segment++)
			{
				const angle=segment/billiardsTableMeshing.cushionTopSegments*Math.PI/2;
				const ring=insetPolygon(polygon,radius*(1-Math.cos(angle)));
				rings.push(ring.map((point,index)=>{
					const [nx,nz]=cornerNormals[index],horizontal=Math.cos(angle);
					return add(point,top-radius+Math.sin(angle)*radius,[nx*horizontal,Math.sin(angle),nz*horizontal]);
				}));
			}
			for(let segment=0;segment<rings.length-1;segment++)for(let edge=0;edge<count;edge++)
			{
				const next=(edge+1)%count;
				quad(rings[segment][edge],rings[segment+1][edge],rings[segment+1][next],rings[segment][next]);
			}

			const topRing=rings.at(-1),bottomRing=polygon.map(point=>add(point,bottom,[0,-1,0]));
			for(let index=1;index<count-1;index++)
			{
				indices.push(topRing[0],topRing[index+1],topRing[index]);
				indices.push(bottomRing[0],bottomRing[index],bottomRing[index+1]);
			}
		}

		return mesh(vertices,indices);
	};

	/** A tapered drop beginning at the circular tabletop hole behind each mouth. */
	const buildPocketLiners = (pockets,ballRadius) => {
		const vertices=[],indices=[];
		const top=billiardsTableMeshing.pocketLinerTop,throat=-.09,bottom=-.235,draft=13.5*Math.PI/180;
		const add=(x,y,z,nx,ny,nz,u,v)=>{const index=vertices.length/8;vertices.push(x,y,z,nx,ny,nz,u,v);return index;};

		for(const pocket of pockets)
		{
			const segments=billiardsTableMeshing.pocketSegments,lipRadius=pocketVoidRadius(pocket.radius);
			const outline=Array.from({length:segments},(_,step)=>{
				const angle=step/segments*Math.PI*2;
				return [pocket.x+Math.cos(angle)*lipRadius,pocket.z+Math.sin(angle)*lipRadius];
			});
			const outer=outline.map(([x,z])=>{
				const dx=x-pocket.x,dz=z-pocket.z,length=Math.hypot(dx,dz)||1;
				return [pocket.x+dx/length*pocket.radius,pocket.z+dz/length*pocket.radius];
			});
			const throatRadius=Math.max(ballRadius*1.12,lipRadius-(top-throat)*Math.tan(draft));
			const rim=[],upper=[],lower=[];

			for(let step=0;step<segments;step++)
			{
				const [x,z]=outline[step],dx=x-pocket.x,dz=z-pocket.z,radial=Math.hypot(dx,dz)||1;
				const c=dx/radial,s=dz/radial,slope=(radial-throatRadius)/(top-throat);
				const normalLength=Math.hypot(1,slope),nx=-c/normalLength,ny=slope/normalLength,nz=-s/normalLength,u=step/segments;
				rim.push([
					add(outer[step][0],top,outer[step][1],0,1,0,u,0)
					, add(x,top,z,0,1,0,u,1)
				]);
				upper.push([
					add(x,top,z,nx,ny,nz,u,0)
					, add(pocket.x+c*throatRadius,throat,pocket.z+s*throatRadius,nx,ny,nz,u,.55)
				]);
				lower.push([
					add(pocket.x+c*throatRadius,throat,pocket.z+s*throatRadius,-c,0,-s,u,.55)
					, add(pocket.x+c*throatRadius,bottom,pocket.z+s*throatRadius,-c,0,-s,u,1)
				]);
			}

			for(let step=0;step<segments;step++)
			{
				const next=(step+1)%segments,[outerA,innerA]=rim[step],[outerB,innerB]=rim[next];
				indices.push(outerA,innerA,innerB,outerA,innerB,outerB);
				for(const wall of [upper,lower])
				{
					const [topA,bottomA]=wall[step],[topB,bottomB]=wall[next];
					indices.push(topA,bottomA,bottomB,topA,bottomB,topB);
				}
			}
		}

		const liners=mesh(vertices,indices);liners.doubleSided=true;return {liners};
	};

	const buildTableMeshes = (width,height,ballRadius,cornerMouth,sideMouth) => {
		const {bounds,pockets,cushionRails,apron,bed,wood}=tableCsgProfiles(width,height,cornerMouth,sideMouth);
		const pocketMeshes=buildPocketLiners(pockets,ballRadius);

		return {
			apron:extrudeCsg(apron,bounds,112,64,-.25,-.042)
			, bed:extrudeCsg(bed,bounds,128,72,-.047,billiardsTableMeshing.tabletopTop)
			, wood:extrudeRoundedCsg(wood,bounds,billiardsTableMeshing.columns,billiardsTableMeshing.rows
				,billiardsTableMeshing.tabletopTop,.105,billiardsEdgeRadii.wood,8)
			, cushion:buildCushionMesh(cushionRails,bounds)
			, ...pocketMeshes
		};
	};
	const cube = (() => {
		const v = [], i = [];
		const faces = [
			[[1,0,0],[[1,-1,-1],[1,1,-1],[1,1,1],[1,-1,1]]],
			[[-1,0,0],[[-1,-1,1],[-1,1,1],[-1,1,-1],[-1,-1,-1]]],
			[[0,1,0],[[-1,1,-1],[-1,1,1],[1,1,1],[1,1,-1]]],
			[[0,-1,0],[[-1,-1,1],[-1,-1,-1],[1,-1,-1],[1,-1,1]]],
			[[0,0,1],[[1,-1,1],[1,1,1],[-1,1,1],[-1,-1,1]]],
			[[0,0,-1],[[-1,-1,-1],[-1,1,-1],[1,1,-1],[1,-1,-1]]]
		];
		for(const [normal,points] of faces)
		{
			const base = v.length / 8;
			points.forEach((point,index) => v.push(point[0]/2,point[1]/2,point[2]/2,...normal,...[[0,0],[0,1],[1,1],[1,0]][index]));
			i.push(base,base+1,base+2,base,base+2,base+3);
		}

		return mesh(v,i);
	})();
	const cueCylinder = (rearRadius=.5,frontRadius=.5,segments=48) => {
		const v=[],i=[],slope=rearRadius-frontRadius,normalLength=Math.hypot(slope,1);
		for(let end=0;end<2;end++)for(let segment=0;segment<=segments;segment++)
		{
			const amount=segment/segments,angle=amount*Math.PI*2,c=Math.cos(angle),s=Math.sin(angle),radius=end?frontRadius:rearRadius;
			v.push(end-.5,c*radius,s*radius,slope/normalLength,c/normalLength,s/normalLength,end,amount);
		}
		for(let segment=0;segment<segments;segment++)
		{
			const rear=segment,front=segments+1+segment;
			i.push(rear,rear+1,front,rear+1,front+1,front);
		}
		for(const [x,radius,normal,direction] of [[-.5,rearRadius,[-1,0,0],-1],[.5,frontRadius,[1,0,0],1]])
		{
			const center=v.length/8;v.push(x,0,0,...normal,.5,.5);
			const ring=v.length/8;
			for(let segment=0;segment<=segments;segment++)
			{
				const angle=segment/segments*Math.PI*2,c=Math.cos(angle),s=Math.sin(angle);
				v.push(x,c*radius,s*radius,...normal,(c+1)/2,(s+1)/2);
				if(segment>0)i.push(center,direction>0?ring+segment-1:ring+segment,direction>0?ring+segment:ring+segment-1);
			}
		}
		return mesh(v,i);
	};
	const cueWood=cueCylinder(.5,cueAppearance.shaftDiameter/cueAppearance.buttDiameter/2);
	const cueTrim=cueCylinder();
	const sphere = (() => {
		const v = [], i = [], rows = 32, columns = 64;
		for(let row=0;row<=rows;row++) for(let column=0;column<=columns;column++)
		{
			const y = row / rows, x = column / columns, phi = y*Math.PI, theta = x*Math.PI*2;
			const nx = Math.sin(phi)*Math.cos(theta), ny = Math.cos(phi), nz = Math.sin(phi)*Math.sin(theta);
			v.push(nx,ny,nz,nx,ny,nz,x,y);
		}
		for(let row=0;row<rows;row++) for(let column=0;column<columns;column++)
		{
			const a=row*(columns+1)+column,b=a+columns+1;i.push(a,a+1,b,b,a+1,b+1);
		}

		return mesh(v,i);
	})();
	const disc = (() => {
		const v = [0,0,0,0,1,0,.5,.5], i = [];
		for(let index=0;index<=40;index++)
		{
			const angle=index/40*Math.PI*2;v.push(Math.cos(angle),0,Math.sin(angle),0,1,0,(Math.cos(angle)+1)/2,(Math.sin(angle)+1)/2);
			if(index>0)i.push(0,index+1,index);
		}

		return mesh(v,i);
	})();

	const mat4Multiply = (a,b) => {
		const out = new Float32Array(16);
		for(let column=0;column<4;column++) for(let row=0;row<4;row++)
			out[column*4+row]=a[row]*b[column*4]+a[4+row]*b[column*4+1]+a[8+row]*b[column*4+2]+a[12+row]*b[column*4+3];
		return out;
	};
	const perspective = (fovy,aspect,near,far) => {
		const f=1/Math.tan(fovy/2),out=new Float32Array(16);out[0]=f/aspect;out[5]=f;out[10]=(far+near)/(near-far);
		out[11]=-1;out[14]=2*far*near/(near-far);return out;
	};
	const normalize = vector => { const length=Math.hypot(...vector)||1;return vector.map(value=>value/length); };
	const cross = (a,b) => [a[1]*b[2]-a[2]*b[1],a[2]*b[0]-a[0]*b[2],a[0]*b[1]-a[1]*b[0]];
	const lookAt = (eye,target,up) => {
		const z=normalize(eye.map((value,index)=>value-target[index])),x=normalize(cross(up,z)),y=cross(z,x),out=new Float32Array(16);
		out.set([x[0],y[0],z[0],0,x[1],y[1],z[1],0,x[2],y[2],z[2],0,-x.reduce((s,v,i)=>s+v*eye[i],0),-y.reduce((s,v,i)=>s+v*eye[i],0),-z.reduce((s,v,i)=>s+v*eye[i],0),1]);return out;
	};
	const model = (position,scale,rotation=[0,0,0,1]) => {
		const [x,y,z,w]=rotation,[sx,sy,sz]=scale,x2=x+x,y2=y+y,z2=z+z;
		const xx=x*x2,xy=x*y2,xz=x*z2,yy=y*y2,yz=y*z2,zz=z*z2,wx=w*x2,wy=w*y2,wz=w*z2;
		return new Float32Array([(1-(yy+zz))*sx,(xy+wz)*sx,(xz-wy)*sx,0,(xy-wz)*sy,(1-(xx+zz))*sy,(yz+wx)*sy,0,
			(xz+wy)*sz,(yz-wx)*sz,(1-(xx+yy))*sz,0,...position,1]);
	};
	const normalMatrix = (rotation,scale) => {
		const inverse=scale.map(value=>1/value),m=model([0,0,0],inverse,rotation);
		return new Float32Array([m[0],m[1],m[2],m[4],m[5],m[6],m[8],m[9],m[10]]);
	};
	const texture = () => {
		const result=gl.createTexture();gl.bindTexture(gl.TEXTURE_2D,result);gl.texParameteri(gl.TEXTURE_2D,gl.TEXTURE_MIN_FILTER,gl.LINEAR_MIPMAP_LINEAR);
		gl.texParameteri(gl.TEXTURE_2D,gl.TEXTURE_MAG_FILTER,gl.LINEAR);gl.texParameteri(gl.TEXTURE_2D,gl.TEXTURE_WRAP_S,gl.REPEAT);
		gl.texParameteri(gl.TEXTURE_2D,gl.TEXTURE_WRAP_T,gl.REPEAT);gl.texImage2D(gl.TEXTURE_2D,0,gl.RGBA,1,1,0,gl.RGBA,gl.UNSIGNED_BYTE,new Uint8Array([255,255,255,255]));return result;
	};
	const ballTexture=texture(),woodTexture=texture();

	const upload = (target,asset) => {
		const binary=atob(asset.data),bytes=new Uint8Array(binary.length);
		for(let index=0;index<binary.length;index++)bytes[index]=binary.charCodeAt(index);
		gl.bindTexture(gl.TEXTURE_2D,target);gl.texImage2D(gl.TEXTURE_2D,0,gl.RGBA,asset.width,asset.height,0,gl.RGBA,gl.UNSIGNED_BYTE,bytes);
		gl.generateMipmap(gl.TEXTURE_2D);
	};

	const draw = (geometry,position,scale,rotation,color,material=[.4,0,0,0],cell=[0,0]) => {
		const transform=model(position,scale,rotation);gl.uniformMatrix4fv(locations.uModel,false,transform);
		gl.uniformMatrix3fv(locations.uNormal,false,normalMatrix(rotation,scale));gl.uniform4fv(locations.uBase,color);
		gl.uniform4fv(locations.uMaterial,material);gl.uniform2fv(locations.uAtlasCell,cell);gl.bindVertexArray(geometry.vao);
		const translucent=material[2]>3.5&&material[2]<4.5;
		if(translucent)gl.depthMask(false);
		if(geometry.doubleSided)gl.disable(gl.CULL_FACE);
		gl.drawElements(gl.TRIANGLES,geometry.count,geometry.type,0);
		if(geometry.doubleSided)gl.enable(gl.CULL_FACE);
		if(translucent)gl.depthMask(true);
	};
	const colors = ['#f3eee0','#e7bb21','#1f62b5','#bd2830','#5c3399','#e66e20','#237a48','#761d24','#151515','#e5b824','#2467b7','#bc2931','#653b9c','#e57523','#287c4e','#7b2028']
		.map(hex=>[parseInt(hex.slice(1,3),16)/255,parseInt(hex.slice(3,5),16)/255,parseInt(hex.slice(5,7),16)/255,1]);
	let latest = null, assetsReady = false, previousGamepad = {}, tableMeshes = null, tableMeshSize = null;

	function controls()
	{
		if(latest?.phase==='placing')
		{
			if(held.has('ArrowLeft'))placementCandidate[0]-=.012;
			if(held.has('ArrowRight'))placementCandidate[0]+=.012;
			if(held.has('ArrowUp'))placementCandidate[1]+=.012;
			if(held.has('ArrowDown'))placementCandidate[1]-=.012;
		}
		else
		{
			if(held.has('ArrowLeft')) aim.value=String(Math.max(-180,Number(aim.value)-1.1));
			if(held.has('ArrowRight')) aim.value=String(Math.min(180,Number(aim.value)+1.1));
			if(held.has('ArrowUp')) power.value=String(Math.min(1,Number(power.value)+.012));
			if(held.has('ArrowDown')) power.value=String(Math.max(.05,Number(power.value)-.012));
		}
		const pad=navigator.getGamepads?.()[0];
		if(pad)
		{
			if(latest?.phase==='placing')
			{
				if(Math.abs(pad.axes[0]??0)>.18)placementCandidate[0]+=(pad.axes[0]??0)*.014;
				if(Math.abs(pad.axes[1]??0)>.18)placementCandidate[1]-=(pad.axes[1]??0)*.014;
			}
			else
			{
				if(Math.abs(pad.axes[0]??0)>.18)aim.value=String(Math.max(-180,Math.min(180,Number(aim.value)+(pad.axes[0]??0)*1.4)));
				if(Math.abs(pad.axes[1]??0)>.18)power.value=String(Math.max(.05,Math.min(1,Number(power.value)-(pad.axes[1]??0)*.012)));
			}
			const buttons={primary:pad.buttons[0]?.pressed,rack:pad.buttons[3]?.pressed,reset:pad.buttons[1]?.pressed};
			for(const [button,pressed] of Object.entries(buttons))if(pressed&&!previousGamepad[button])
			{
				const action=button==='primary'?(latest?.phase==='placing'?'place':'shoot'):button;
				if(action!=='place'||cuePlacementValid(latest,...placementCandidate))
				{
					if(action==='place')pendingPlacement=[...placementCandidate];
					enqueue(action);
				}
			}
			previousGamepad=buttons;
		}
		for(const input of [aim,power,top,side])input.dispatchEvent(new Event('input'));
		const actions=actionsQueue.splice(0),submitted=pendingPlacement??placementCandidate;
		pendingPlacement=null;
		const result={aim:Number(aim.value),power:Number(power.value),top:Number(top.value),side:Number(side.value),
			cueX:submitted[0],cueY:submitted[1],actions};
		return JSON.stringify(result);
	}

	function render(json)
	{
		latest=JSON.parse(json);canvas.dataset.phpBilliards=json;
		if(gl.isContextLost()) return;
		if(latest.phase==='placing'&&previousPhase!=='placing')placementCandidate=[-.65,0];
		previousPhase=latest.phase;
		placement.hidden=latest.phase!=='placing';
		canvas.dataset.placing=String(latest.phase==='placing');
		canvas.setAttribute('aria-label',latest.phase==='placing'
			?'Billiards table. Move the pointer over the cloth and click to place the cue ball.'
			:'Billiards table. Drag to orbit and use the mouse wheel to zoom.');
		const locked=latest.phase!=='aim';for(const input of [aim,power,top,side,shoot])input.disabled=locked;
		const contact=latest.phase==='aim'?cueContactWarning(latest.balls,latest.table[2],latest.aim):null;
		const placementValid=latest.phase==='placing'&&cuePlacementValid(latest,...placementCandidate);
		reset.disabled=latest.shots<1;status.textContent=latest.phase==='placing'
			?(placementValid?'Scratch — click to place the cue ball':'Scratch — choose a clear point on the cloth'):latest.moving?'Balls in motion'
			:contact!==null?`Cue nearly frozen to ball ${contact} · impact will be immediate`
			:`Ready · ${latest.shots} shot${latest.shots===1?'':'s'}`;
		live.textContent=status.textContent;
		for(const event of latest.events??[])
		{
			if(event.type==='impact')tone(240+220*(event.strength??0),.025+.07*(event.strength??0),.045);
			if(event.type==='cushion')tone(150,.018+.035*(event.strength??0),.035);
			if(event.type==='pocket')
			{
				tone(72,.11,.16);
			}
			if(event.type==='pocket-rattle')tone(118,.025+.04*(event.strength??0),.032);
			if(event.type==='pocket-reject')tone(145,.035,.04);
		}

		const box=screen.getBoundingClientRect(),dpr=Math.min(2,devicePixelRatio||1),width=Math.max(1,Math.round(box.width*dpr)),height=Math.max(1,Math.round(box.height*dpr));
		if(canvas.width!==width||canvas.height!==height){canvas.width=width;canvas.height=height;}
		gl.viewport(0,0,width,height);gl.enable(gl.DEPTH_TEST);gl.enable(gl.CULL_FACE);gl.enable(gl.BLEND);gl.blendFunc(gl.SRC_ALPHA,gl.ONE_MINUS_SRC_ALPHA);
		gl.clearColor(.012,.021,.017,1);gl.clear(gl.COLOR_BUFFER_BIT|gl.DEPTH_BUFFER_BIT);gl.useProgram(program);
		const moving=latest.moving,distance=camera.distance+(moving?.32:0),target=camera.target,eye=[target[0]+Math.cos(camera.yaw)*Math.cos(camera.pitch)*distance,target[1]+Math.sin(camera.pitch)*distance,target[2]+Math.sin(camera.yaw)*Math.cos(camera.pitch)*distance];
		const vp=mat4Multiply(perspective(.63,width/height,.05,20),lookAt(eye,target,[0,1,0]));
		gl.uniformMatrix4fv(locations.uViewProjection,false,vp);gl.uniform3fv(locations.uEye,eye);
		gl.activeTexture(gl.TEXTURE0);gl.bindTexture(gl.TEXTURE_2D,ballTexture);gl.uniform1i(locations.uBallAtlas,0);
		gl.activeTexture(gl.TEXTURE1);gl.bindTexture(gl.TEXTURE_2D,woodTexture);gl.uniform1i(locations.uWood,1);
		const [tableWidth,tableHeight,radius,cornerMouth=.1158875,sideMouth=.1285875]=latest.table;
		const meshSize=[tableWidth,tableHeight,radius,cornerMouth,sideMouth];
		const pockets=tablePocketDefinitions(tableWidth,tableHeight,cornerMouth,sideMouth);
		if(!tableMeshSize||meshSize.some((value,index)=>tableMeshSize[index]!==value))
		{
			for(const item of Object.values(tableMeshes??{})){gl.deleteVertexArray(item.vao);gl.deleteBuffer(item.buffer);gl.deleteBuffer(item.elements);}
			tableMeshes=buildTableMeshes(...meshSize);tableMeshSize=meshSize;
		}
		draw(tableMeshes.apron,[0,0,0],[1,1,1],[0,0,0,1],[.45,.23,.095,1],billiardsMaterials.wood);
		draw(tableMeshes.bed,[0,0,0],[1,1,1],[0,0,0,1],[.055,.285,.18,1],billiardsMaterials.felt);
		draw(tableMeshes.wood,[0,0,0],[1,1,1],[0,0,0,1],[.38,.18,.07,1],billiardsMaterials.wood);
		draw(tableMeshes.cushion,[0,0,0],[1,1,1],[0,0,0,1],[.042,.21,.13,1],billiardsMaterials.cushionFelt);
		draw(tableMeshes.liners,[0,0,0],[1,1,1],[0,0,0,1],[.12,.045,.022,1],[.72,1.52,0,0]);
		for(const pocket of pockets)
		{
			const voidRadius=pocketVoidRadius(pocket.radius);
			draw(disc,[pocket.x,-.237,pocket.z],[voidRadius,1,voidRadius],[0,0,0,1],[.001,.0015,.001,1],[.96,1.5,0,0]);
		}
		for(const ball of latest.balls)if(ball.active&&ball.pocket===null)
		{
			const shadowOffset=.0085;
			draw(disc,[ball.x+(ball.x+.45)*shadowOffset,.0124,ball.y+(ball.y+.7)*shadowOffset]
				,[radius*1.28,1,radius*1.28],[0,0,0,1],[0,0,0,.3],[1,0,4,0]);
		}
		for(const ball of latest.balls)if(ball.active)
			draw(sphere,[ball.x,radius+.012-(ball.drop??0),ball.y],[radius,radius,radius]
				,ball.rotation,colors[ball.number],billiardsMaterials.ball,[ball.number%4,Math.floor(ball.number/4)]);
		if(latest.phase==='placing')
		{
			const [x,z]=placementCandidate,valid=cuePlacementValid(latest,x,z),previewColor=valid?[.72,.92,.82,.72]:[1,.12,.08,.72];
			draw(disc,[x,.0124,z],[radius*1.35,1,radius*1.35],[0,0,0,1],valid?[.05,.5,.22,.32]:[.7,.02,.01,.35],[1,0,4,0]);
			draw(sphere,[x,radius+.012,z],[radius,radius,radius],[0,0,0,1],previewColor,[.08,1.5,0,.2]);
		}
		if(latest.phase==='aim')
		{
			const cue=latest.balls.find(ball=>ball.number===0&&ball.active),angle=latest.aim,dx=Math.cos(angle),dz=Math.sin(angle);
			if(cue){const pose=cuePose(cue.x,cue.y,angle,tableWidth,tableHeight,radius),guideRotation=[0,-Math.sin(angle/2),0,Math.cos(angle/2)];
				const {length,buttDiameter,shaftDiameter,chalkTipLength,copperCollarLength}=cueAppearance;
				const segmentPosition=(fromTip,toTip)=>pose.tip.map((value,index)=>value-pose.direction[index]*(fromTip+toTip)/2);
				const woodStart=chalkTipLength+copperCollarLength,woodScale=[length-woodStart,buttDiameter,buttDiameter];
				const woodPosition=segmentPosition(woodStart,length);
				draw(cueWood,woodPosition,woodScale,pose.rotation,[.75,.57,.31,1],billiardsMaterials.cueWood);
				draw(cueTrim,segmentPosition(chalkTipLength,woodStart),[copperCollarLength,shaftDiameter*1.06,shaftDiameter*1.06]
					,pose.rotation,[.72,.31,.12,1],[.2,1.5,5,0]);
				draw(cueTrim,segmentPosition(0,chalkTipLength),[chalkTipLength,shaftDiameter,shaftDiameter]
					,pose.rotation,[.08,.28,.52,1],[.82,1.5,0,0]);
				draw(cube,[cue.x+dx*.38,.017,cue.y+dz*.38],[.66,.003,.006],guideRotation,[.94,.76,.26,.72],[.6,0,0,0]);}
		}
	}

	module.billiardsAssets=json=>{const data=JSON.parse(json);upload(ballTexture,data.ballAtlas);upload(woodTexture,data.wood);assetsReady=true;};
	module.billiardsInput=controls;
	module.billiardsRender=json=>{if(assetsReady)render(json);};
	session.phpBilliards={get state(){return latest?structuredClone(latest):null;},camera
		,get placement(){return {position:[...placementCandidate],valid:cuePlacementValid(latest,...placementCandidate)};}};
	Object.defineProperty(canvas,'phpBilliardsDebug',{configurable:true,value:{camera,get state(){return latest?structuredClone(latest):null;}
		,get placement(){return {position:[...placementCandidate],valid:cuePlacementValid(latest,...placementCandidate)};}}});

	return async() => {
		abort.abort();
		if(document.fullscreenElement===ui)await document.exitFullscreen().catch(()=>{});
		for(const item of [cube,cueWood,cueTrim,sphere,disc,...Object.values(tableMeshes??{})])
			{gl.deleteVertexArray(item.vao);gl.deleteBuffer(item.buffer);gl.deleteBuffer(item.elements);}
		gl.deleteTexture(ballTexture);gl.deleteTexture(woodTexture);gl.deleteProgram(program);audio?.close();
		if(next?.parentNode===parent)parent.insertBefore(canvas,next);else parent.append(canvas);
		if(originalStyle===null)canvas.removeAttribute('style');else canvas.setAttribute('style',originalStyle);
		ui.remove();delete canvas.dataset.phpBilliards;delete canvas.phpBilliardsDebug;
	};
}
