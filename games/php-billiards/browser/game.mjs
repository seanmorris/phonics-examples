/** Place the cue above the first rail it crosses while keeping its tip on the cue ball. */
export function cuePose(cueX, cueY, angle, tableWidth, tableHeight, ballRadius)
{
	const length = 1.35, thickness = .014, dx = Math.cos(angle), dz = Math.sin(angle);
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
	const railClearance = .105 + thickness / 2 + .006;
	const pitch = Math.max(8 * Math.PI / 180, Math.atan2(railClearance - tipY, railDistance));
	const sinYaw = Math.sin(angle / 2), cosYaw = Math.cos(angle / 2);
	const sinPitch = Math.sin(pitch / 2), cosPitch = Math.cos(pitch / 2);
	const direction = [dx * Math.cos(pitch),-Math.sin(pitch),dz * Math.cos(pitch)];

	return {
		position: tip.map((value, index) => value - direction[index] * length / 2)
		, scale: [length,thickness,thickness]
		, rotation: [sinYaw * sinPitch,-sinYaw * cosPitch,-cosYaw * sinPitch,cosYaw * cosPitch]
		, tip
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

export function tablePocketDefinitions(width,height,cornerMouth=.1158875,sideMouth=.1285875)
{
	return [
		{x:-width/2,z:-height/2,mouth:cornerMouth,outward:[-Math.SQRT1_2,-Math.SQRT1_2]}
		, {x:0,z:-height/2,mouth:sideMouth,outward:[0,-1]}
		, {x:width/2,z:-height/2,mouth:cornerMouth,outward:[Math.SQRT1_2,-Math.SQRT1_2]}
		, {x:-width/2,z:height/2,mouth:cornerMouth,outward:[-Math.SQRT1_2,Math.SQRT1_2]}
		, {x:0,z:height/2,mouth:sideMouth,outward:[0,1]}
		, {x:width/2,z:height/2,mouth:cornerMouth,outward:[Math.SQRT1_2,Math.SQRT1_2]}
	];
}

/** Shared implicit profiles used to construct the table as nested solids and cuts. */
export function tableCsgProfiles(width,height,cornerMouth=.1158875,sideMouth=.1285875)
{
	const outerX=width/2+.17,outerZ=height/2+.17,bounds=[-outerX-.02,-outerZ-.02,outerX+.02,outerZ+.02];
	const pockets=tablePocketDefinitions(width,height,cornerMouth,sideMouth);
	const pocketSdf=(x,z,extra=0)=>Math.min(...pockets.map(pocket=>circleSdf(x,z,pocket.x,pocket.z,pocket.mouth/2+extra)));
	const outer=(x,z)=>roundedRectangleSdf(x,z,outerX,outerZ,.16);

	return {
		bounds
		, pockets
		, apron:(x,z)=>subtractSdf(outer(x,z),pocketSdf(x,z,.006))
		, bed:(x,z)=>subtractSdf(roundedRectangleSdf(x,z,width/2,height/2,.02),pocketSdf(x,z,.001))
		, wood:(x,z)=>subtractSdf(outer(x,z)
			,roundedRectangleSdf(x,z,width/2+.052,height/2+.052,.072),pocketSdf(x,z,.006))
		, cushion:(x,z)=>subtractSdf(roundedRectangleSdf(x,z,width/2+.055,height/2+.055,.075)
			,roundedRectangleSdf(x,z,width/2,height/2,.02),pocketSdf(x,z,.003))
	};
}

export function pocketDropPose(pocket,ballRadius,progress)
{
	progress=Math.max(0,Math.min(1,progress));
	const smooth=progress*progress*(3-2*progress),roll=Math.max(0,(progress-.38)/.62),travel=.055*roll;

	return [pocket.x+pocket.outward[0]*travel,ballRadius+.012-.19*smooth,pocket.z+pocket.outward[1]*travel];
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
	, felt:Object.freeze([.9,1.5,3,.85])
});

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
		.php-billiards-placement{display:grid;gap:8px;padding:9px;border:1px solid #d7bd7f40;border-radius:5px;background:#0003}
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
			.php-billiards-panel button{min-height:34px}.php-billiards-placement{grid-column:1/-1;grid-template-columns:1fr 1fr auto}
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
	panel.append(placement);
	const cueX = document.createElement('input'), cueY = document.createElement('input');
	for(const [input,name,label,min,max] of [
		[cueX,'cueX','Cue position, length',-1.20,1.20]
		, [cueY,'cueY','Cue position, width',-.57,.57]
	])
	{
		const wrapper = document.createElement('label');
		wrapper.textContent = label;
		input.type = 'range'; input.name = name; input.min = min; input.max = max; input.step = .01;
		input.value = name === 'cueX' ? -.65 : 0;
		wrapper.append(input); placement.append(wrapper);
	}
	const place = document.createElement('button');
	place.type = 'button'; place.textContent = 'PLACE CUE'; placement.append(place);
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
	place.addEventListener('click', () => enqueue('place'), {signal});

	canvas.addEventListener('keydown', event => {
		if(['Space','ArrowLeft','ArrowRight','ArrowUp','ArrowDown','KeyR','Backspace'].includes(event.code)) event.preventDefault();
		held.add(event.code);

		if(event.repeat) return;

		if(event.code === 'Space') enqueue('shoot');
		if(event.code === 'KeyR') enqueue('rack');
		if(event.code === 'Backspace') enqueue('reset');
		if(event.code === 'Enter' && placement.hidden === false) enqueue('place');
	}, {signal});
	canvas.addEventListener('keyup', event => held.delete(event.code), {signal});
	canvas.addEventListener('blur', () => held.clear(), {signal});

	let dragging = null;
	const camera = {yaw: -.42, pitch: .72, distance: 3.35, target: [0,0,0]};
	canvas.addEventListener('pointerdown', event => {
		canvas.focus({preventScroll: true});
		canvas.setPointerCapture(event.pointerId);
		dragging = {id: event.pointerId,x: event.clientX,y: event.clientY,yaw: camera.yaw,pitch: camera.pitch};
	}, {signal});
	canvas.addEventListener('pointermove', event => {
		if(!dragging || dragging.id !== event.pointerId) return;

		camera.yaw = dragging.yaw - (event.clientX - dragging.x) * .006;
		camera.pitch = Math.max(.28, Math.min(1.18, dragging.pitch + (event.clientY - dragging.y) * .005));
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
			float feltMacro(vec3 p){return noise3(p*11.0)*.58+noise3(p*27.0+vec3(8.1,2.7,5.4))*.29
				+noise3(p*61.0+vec3(3.2,9.4,1.8))*.13;}
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
				if(mode>3.5){float edge=length(vUv-.5)*2.0,opacity=(1.0-smoothstep(.18,1.0,edge))*uBase.a;
					if(opacity<.002)discard;color=vec4(vec3(0.0),opacity);return;}
				if(mode>0.5&&mode<1.5){vec2 pad=vec2(4.0)/vec2(textureSize(uBallAtlas,0));
					// Raw atlas rows run opposite WebGL's vertical axis; longitude must stay unchanged to preserve numeral chirality.
					vec2 uv=mix(uAtlasCell/4.0+pad,(uAtlasCell+1.0)/4.0-pad,vec2(vUv.x,1.0-vUv.y));base=texture(uBallAtlas,uv).rgb;}
				else if(mode>1.5&&mode<2.5)base*=wood(vPosition,N)*1.45;
				else if(mode>2.5){float macro=feltMacro(vPosition),grain=noise3(vPosition*430.0+vec3(4.7,1.3,9.1));
					float looseFiber=smoothstep(.68,.9,noise3(vPosition*760.0+vec3(2.1,7.4,3.8)));
					float height=(macro-.5)*.00055+(grain-.5)*.00016+looseFiber*.00007;
					N=bumpNormal(vPosition,N,height);float top=smoothstep(.62,.92,N.y);
					float track=exp(-pow(abs(vPosition.z-.055*sin(vPosition.x*5.0))*18.0,2.0))*top;
					base*=.92+macro*.13+(grain-.5)*.045+looseFiber*.035;base=mix(base,base*1.04,track*.035);}
				float rough=uMaterial.x;if(mode>0.5&&mode<1.5)rough=clamp(rough+(random(floor(vPosition*900.0))-.5)*.012,.01,.05);
				float ior=uMaterial.y>1.0?uMaterial.y:1.5,f0Value=pow((ior-1.0)/(ior+1.0),2.0),nv=max(dot(N,V),.001);
				vec3 f0=vec3(f0Value),key=vec3(-.45,2.8,-.7),fill=vec3(1.8,1.45,2.15);
				float skyAmbient=clamp(N.y*.5+.5,0.0,1.0);
				vec3 ambient=mix(vec3(.15,.21,.17),vec3(.25,.27,.3),skyAmbient);
				vec3 lit=base*ambient+lightPbr(key,vec3(1.0,.94,.82),3.7,N,V,base,f0,rough,.14)
					+lightPbr(fill,vec3(.58,.72,1.0),.58,N,V,base,f0,rough,.18);
				if(mode>0.5&&mode<1.5){lit+=base*.035*(1.0-max(dot(N,normalize(key-vPosition)),0.0));
					float coat=coatSpec(key,N,V,.018,.16)*3.7+coatSpec(fill,N,V,.028,.2)*.58;
					lit+=vec3(.55*coat/(1.0+coat))*uMaterial.w;}
				else if(mode>1.5&&mode<2.5){float coat=coatSpec(key,N,V,.11,.12)*3.7+coatSpec(fill,N,V,.14,.16)*.58;
					lit+=vec3(.55*coat/(1.0+coat))*uMaterial.w;}
				else if(mode>2.5){float backLight=max(dot(-N,normalize(key-vPosition)),0.0);
					lit+=base*(.04+.055*backLight)+base*pow(1.0-nv,1.8)*uMaterial.w*.82;}
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
		const vertices=[],indices=[],epsilon=Math.min(stepX,stepZ)*.2;
		const point=(x,z)=>({x,z,distance:field(x,z)});
		const interpolate=(a,b)=>{const amount=a.distance/(a.distance-b.distance);return {
			x:a.x+(b.x-a.x)*amount,z:a.z+(b.z-a.z)*amount,distance:0
		};};
		const unique=points=>points.filter((entry,index)=>points.findIndex(candidate=>
			Math.hypot(entry.x-candidate.x,entry.z-candidate.z)<1e-9)===index);
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
					if(inside!==nextInside){const crossing=interpolate(a,b);polygon.push(crossing);crossings.push(crossing);}
				}

				const clipped=unique(polygon),boundary=unique(crossings);
				if(clipped.length>=3){addSurface(clipped,top,[0,1,0],true);addSurface(clipped,bottom,[0,-1,0],false);}
				if(boundary.length===2)addWall(boundary[0],boundary[1]);
			}
		}

		return mesh(vertices,indices);
	};

	/** Leather facings and a 13.5-degree tapered drop under every CSG pocket cut. */
	const buildPocketLiners = (pockets,ballRadius) => {
		const vertices=[],indices=[],segments=40,top=.017,throat=-.09,bottom=-.235,draft=13.5*Math.PI/180;
		const add=(x,y,z,nx,ny,nz,u,v)=>{const index=vertices.length/8;vertices.push(x,y,z,nx,ny,nz,u,v);return index;};

		for(const pocket of pockets)
		{
			const outer=pocket.mouth/2+.006,inner=pocket.mouth/2-.001;
			const throatRadius=Math.max(ballRadius*1.12,inner-(top-throat)*Math.tan(draft));
			const rim=[],upper=[],lower=[];

			for(let step=0;step<=segments;step++)
			{
				const angle=step/segments*Math.PI*2,c=Math.cos(angle),s=Math.sin(angle),slope=(inner-throatRadius)/(top-throat);
				const normalLength=Math.hypot(1,slope),nx=-c/normalLength,ny=slope/normalLength,nz=-s/normalLength,u=step/segments;
				rim.push([
					add(pocket.x+c*outer,top,pocket.z+s*outer,0,1,0,u,0)
					, add(pocket.x+c*inner,top,pocket.z+s*inner,0,1,0,u,1)
				]);
				upper.push([
					add(pocket.x+c*inner,top,pocket.z+s*inner,nx,ny,nz,u,0)
					, add(pocket.x+c*throatRadius,throat,pocket.z+s*throatRadius,nx,ny,nz,u,.55)
				]);
				lower.push([
					add(pocket.x+c*throatRadius,throat,pocket.z+s*throatRadius,-c,0,-s,u,.55)
					, add(pocket.x+c*throatRadius,bottom,pocket.z+s*throatRadius,-c,0,-s,u,1)
				]);
			}

			for(let step=0;step<segments;step++)
			{
				const [outerA,innerA]=rim[step],[outerB,innerB]=rim[step+1];
				indices.push(outerA,innerA,innerB,outerA,innerB,outerB);
				for(const wall of [upper,lower])
				{
					const [topA,bottomA]=wall[step],[topB,bottomB]=wall[step+1];
					indices.push(topA,bottomA,bottomB,topA,bottomB,topB);
				}
			}
		}

		const result=mesh(vertices,indices);result.doubleSided=true;return result;
	};

	const buildTableMeshes = (width,height,ballRadius,cornerMouth,sideMouth) => {
		const {bounds,pockets,apron,bed,wood,cushion}=tableCsgProfiles(width,height,cornerMouth,sideMouth);

		return {
			apron:extrudeCsg(apron,bounds,112,64,-.25,-.042)
			, bed:extrudeCsg(bed,bounds,128,72,-.047,.012)
			, wood:extrudeCsg(wood,bounds,144,80,.012,.105)
			, cushion:extrudeCsg(cushion,bounds,144,80,.013,.083)
			, liners:buildPocketLiners(pockets,ballRadius)
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
	const normalMatrix = rotation => {
		const m=model([0,0,0],[1,1,1],rotation);return new Float32Array([m[0],m[1],m[2],m[4],m[5],m[6],m[8],m[9],m[10]]);
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
		gl.uniformMatrix3fv(locations.uNormal,false,normalMatrix(rotation));gl.uniform4fv(locations.uBase,color);
		gl.uniform4fv(locations.uMaterial,material);gl.uniform2fv(locations.uAtlasCell,cell);gl.bindVertexArray(geometry.vao);
		if(material[2]>3.5)gl.depthMask(false);
		if(geometry.doubleSided)gl.disable(gl.CULL_FACE);
		gl.drawElements(gl.TRIANGLES,geometry.count,geometry.type,0);
		if(geometry.doubleSided)gl.enable(gl.CULL_FACE);
		if(material[2]>3.5)gl.depthMask(true);
	};
	const colors = ['#f3eee0','#e7bb21','#1f62b5','#bd2830','#5c3399','#e66e20','#237a48','#761d24','#151515','#e5b824','#2467b7','#bc2931','#653b9c','#e57523','#287c4e','#7b2028']
		.map(hex=>[parseInt(hex.slice(1,3),16)/255,parseInt(hex.slice(3,5),16)/255,parseInt(hex.slice(5,7),16)/255,1]);
	let latest = null, assetsReady = false, previousGamepad = {}, tableMeshes = null, tableMeshSize = null, pocketDrops = [];

	function controls()
	{
		if(held.has('ArrowLeft')) aim.value=String(Math.max(-180,Number(aim.value)-1.1));
		if(held.has('ArrowRight')) aim.value=String(Math.min(180,Number(aim.value)+1.1));
		if(held.has('ArrowUp')) power.value=String(Math.min(1,Number(power.value)+.012));
		if(held.has('ArrowDown')) power.value=String(Math.max(.05,Number(power.value)-.012));
		const pad=navigator.getGamepads?.()[0];
		if(pad)
		{
			if(Math.abs(pad.axes[0]??0)>.18)aim.value=String(Math.max(-180,Math.min(180,Number(aim.value)+(pad.axes[0]??0)*1.4)));
			if(Math.abs(pad.axes[1]??0)>.18)power.value=String(Math.max(.05,Math.min(1,Number(power.value)-(pad.axes[1]??0)*.012)));
			const buttons={shoot:pad.buttons[0]?.pressed,rack:pad.buttons[3]?.pressed,reset:pad.buttons[1]?.pressed};
			for(const [action,pressed] of Object.entries(buttons))if(pressed&&!previousGamepad[action])enqueue(action);
			previousGamepad=buttons;
		}
		for(const input of [aim,power,top,side])input.dispatchEvent(new Event('input'));
		const result={aim:Number(aim.value),power:Number(power.value),top:Number(top.value),side:Number(side.value),
			cueX:Number(cueX.value),cueY:Number(cueY.value),actions:actionsQueue.splice(0)};
		return JSON.stringify(result);
	}

	function render(json)
	{
		latest=JSON.parse(json);canvas.dataset.phpBilliards=json;
		if(gl.isContextLost()) return;
		placement.hidden=latest.phase!=='placing';
		const locked=latest.phase!=='aim';for(const input of [aim,power,top,side,shoot])input.disabled=locked;
		const contact=latest.phase==='aim'?cueContactWarning(latest.balls,latest.table[2],latest.aim):null;
		reset.disabled=latest.shots<1;status.textContent=latest.phase==='placing'?'Scratch — place the cue ball':latest.moving?'Balls in motion'
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
				const ball=latest.balls.find(candidate=>candidate.number===event.ball);
				pocketDrops.push({number:event.ball,index:event.pocket,rotation:ball?.rotation??[0,0,0,1],started:performance.now()});
			}
			if(event.type==='rack'||event.type==='reset')pocketDrops=[];
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
		draw(tableMeshes.cushion,[0,0,0],[1,1,1],[0,0,0,1],[.035,.17,.105,1],billiardsMaterials.felt);
		draw(tableMeshes.liners,[0,0,0],[1,1,1],[0,0,0,1],[.16,.065,.032,1],[.68,1.52,0,0]);
		for(const pocket of pockets)
			draw(disc,[pocket.x,-.237,pocket.z],[pocket.mouth*.34,1,pocket.mouth*.34],[0,0,0,1],[.001,.0015,.001,1],[.96,1.5,0,0]);
		for(const ball of latest.balls)if(ball.active)
		{
			const shadowOffset=.0085;
			draw(disc,[ball.x+(ball.x+.45)*shadowOffset,.0124,ball.y+(ball.y+.7)*shadowOffset]
				,[radius*1.28,1,radius*1.28],[0,0,0,1],[0,0,0,.3],[1,0,4,0]);
		}
		for(const ball of latest.balls)if(ball.active)
			draw(sphere,[ball.x,radius+.012,ball.y],[radius,radius,radius],ball.rotation,colors[ball.number],billiardsMaterials.ball,[ball.number%4,Math.floor(ball.number/4)]);
		const now=performance.now();pocketDrops=pocketDrops.filter(drop=>now-drop.started<900);
		for(const drop of pocketDrops)
		{
			const pocket=pockets[drop.index],progress=(now-drop.started)/720;
			draw(sphere,pocketDropPose(pocket,radius,progress)
				,[radius,radius,radius],drop.rotation,colors[drop.number],billiardsMaterials.ball,[drop.number%4,Math.floor(drop.number/4)]);
		}
		if(latest.phase==='aim')
		{
			const cue=latest.balls.find(ball=>ball.number===0&&ball.active),angle=latest.aim,dx=Math.cos(angle),dz=Math.sin(angle);
			if(cue){const pose=cuePose(cue.x,cue.y,angle,tableWidth,tableHeight,radius),guideRotation=[0,-Math.sin(angle/2),0,Math.cos(angle/2)];
				draw(cube,pose.position,pose.scale,pose.rotation,[.75,.57,.31,1],billiardsMaterials.wood);
				draw(cube,[cue.x+dx*.38,.017,cue.y+dz*.38],[.66,.003,.006],guideRotation,[.94,.76,.26,.72],[.6,0,0,0]);}
		}
	}

	module.billiardsAssets=json=>{const data=JSON.parse(json);upload(ballTexture,data.ballAtlas);upload(woodTexture,data.wood);assetsReady=true;};
	module.billiardsInput=controls;
	module.billiardsRender=json=>{if(assetsReady)render(json);};
	session.phpBilliards={get state(){return latest?structuredClone(latest):null;},camera};
	Object.defineProperty(canvas,'phpBilliardsDebug',{configurable:true,value:{camera,get state(){return latest?structuredClone(latest):null;}}});

	return async() => {
		abort.abort();
		if(document.fullscreenElement===ui)await document.exitFullscreen().catch(()=>{});
		for(const item of [cube,sphere,disc,...Object.values(tableMeshes??{})]){gl.deleteVertexArray(item.vao);gl.deleteBuffer(item.buffer);gl.deleteBuffer(item.elements);}
		gl.deleteTexture(ballTexture);gl.deleteTexture(woodTexture);gl.deleteProgram(program);audio?.close();
		if(next?.parentNode===parent)parent.insertBefore(canvas,next);else parent.append(canvas);
		if(originalStyle===null)canvas.removeAttribute('style');else canvas.setAttribute('style',originalStyle);
		ui.remove();delete canvas.dataset.phpBilliards;delete canvas.phpBilliardsDebug;
	};
}
