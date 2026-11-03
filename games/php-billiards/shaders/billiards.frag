#version 300 es
precision highp float;
in vec3 vPosition;
in vec3 vNormal;
in vec2 vUv;
in vec4 vTint;
flat in float vLayer;
uniform bool uInstanced;
uniform vec4 uBase;
uniform vec3 uEye;
uniform vec4 uMaterial;
uniform vec2 uTableSize;
uniform float uSpot;
uniform highp sampler2DArray uBallSkins;
uniform sampler2D uWood;
uniform sampler2D uFelt;
out vec4 color;

float distribution(float nDotH,float rough){float a=rough*rough;float a2=a*a;float d=nDotH*nDotH*(a2-1.0)+1.0;return a2/(3.14159*d*d);}
float specularAaRoughness(float rough,vec3 normal){vec3 dx=dFdx(normal),dy=dFdy(normal);
	float variance=.6*(dot(dx,dx)+dot(dy,dy));return clamp(sqrt(rough*rough+min(variance,.1)),rough,.5);}
float random(vec3 p){return fract(sin(dot(p,vec3(12.9898,78.233,37.719)))*43758.5453);}
float noise3(vec3 p){vec3 cell=floor(p),f=fract(p);f=f*f*(3.0-2.0*f);
	float n000=random(cell),n100=random(cell+vec3(1.0,0.0,0.0)),n010=random(cell+vec3(0.0,1.0,0.0)),n110=random(cell+vec3(1.0,1.0,0.0));
	float n001=random(cell+vec3(0.0,0.0,1.0)),n101=random(cell+vec3(1.0,0.0,1.0)),n011=random(cell+vec3(0.0,1.0,1.0)),n111=random(cell+vec3(1.0));
	return mix(mix(mix(n000,n100,f.x),mix(n010,n110,f.x),f.y),mix(mix(n001,n101,f.x),mix(n011,n111,f.x),f.y),f.z);}
vec3 bumpNormal(vec3 position,vec3 normal,float height){vec3 sigmaX=dFdx(position),sigmaY=dFdy(position);
	vec3 r1=cross(sigmaY,normal),r2=cross(normal,sigmaX);float determinant=dot(sigmaX,r1);
	vec3 gradient=sign(determinant)*(dFdx(height)*r1+dFdy(height)*r2);
	return normalize(abs(determinant)*normal-gradient);}
vec4 hash4(vec2 id,float seed){return fract(sin(vec4(dot(id,vec2(127.1,311.7)),dot(id,vec2(269.5,183.3))
	,dot(id,vec2(419.2,371.9)),dot(id,vec2(97.3,233.1)))+seed)*43758.5453);}
// Knots live in grain-space cells rather than in the tile, so each gets its own place, size, shape, and edge. A knot is
// a branch cross-section: roughly concentric growth rings around an off-centre pith inside a lopsided outline, replacing the
// board grain; about half are drawn out along the grain. None is darker than the knot once baked into wood.svg (a 30%
// dark stroke and a 20% pale ring); they vary only by fading lighter. Rows are staggered and short blocks of cells stay
// clear. A knot never reaches more than half a cell past its own, so the two nearest rows and columns hold every knot
// that can touch this point.
// Returns the grain displacement across u, in u-units (x), dark edge opacity (y), interior coverage (z), and how much the
// rings darken the knot's interior (w).
vec4 woodKnots(vec2 uv,float footprint,float centre){vec2 density=vec2(1.6,9.0),cell=uv/vec2(2.7,7.1)*density;vec4 result=vec4(0.0);
	float row=floor(cell.y),rowStep=fract(cell.y)<.5?-1.0:1.0;
	for(int j=0;j<2;j++){float line=row+float(j)*rowStep,shift=fract(sin(line*78.233+.77)*43758.5453);
		float along=cell.x+shift,column=floor(along),columnStep=fract(along)<.5?-1.0:1.0;
		for(int i=0;i<2;i++){vec2 id=vec2(column+float(i)*columnStep,line);
			vec4 a=hash4(id,0.0),b=hash4(id,1.7),c=hash4(id,4.3);
			// Clear and knotty blocks mirror across the board's centre line, so opposite rails share the same density
			// while every knot still comes from its own cell.
			vec2 block=vec2(id.x,floor((centre+abs((id.y+.5)/density.y-centre))*density.y));
			if(a.x>.85*smoothstep(.1,.45,hash4(floor(block/vec2(2.0,3.0)),3.1).x))continue;
			float stretch=mix(1.8,4.0,fract(b.x*13.7));
			vec2 radius=vec2(mix(.03,.07,a.w)*stretch,mix(.008,.018,a.w)*mix(.8,1.25,fract(b.y*7.9)));
			vec2 offset=uv/vec2(2.7,7.1)-(id+vec2(.2+.6*a.y-shift,.2+.6*a.z))/density;
			float tilt=(b.y-.5)*.3/stretch;offset=vec2(offset.x*cos(tilt)+offset.y*sin(tilt),offset.y*cos(tilt)-offset.x*sin(tilt));
			// Sweet-potato shaped: long, tapering to points, one end fuller than the other, and slightly bent. Scaling
			// across the grain by (1-u^2)^(1/2-t) turns every ellipse level into a spindle with half-width (1-u^2)^t.
			vec2 q=offset/radius;q.y-=(c.x-.5)*.7*(q.x*q.x-.35);
			// The knot forces the board grain outward: each point samples the grain from nearer the knot's centre line,
			// so lines that would cross it are pushed aside by exactly its half-width, and the push fades over a couple
			// of half-widths, bowing the grain well clear of the knot. Its outline becomes a grain line, and its
			// neighbours run parallel to it, bunching against the sides and closing at the tips. A smooth sign keeps the centre line unbroken past the tips, and a window keeps each knot's pull
			// inside the cells this search covers.
			float taper=mix(.65,.9,c.z),fuller=1.0+.35*(c.y-.5)*2.0*q.x,spread=fuller*pow(max(1.0-q.x*q.x,0.0),taper);
			float envelope=1.7*fuller*max(1.0-q.x*q.x/1.69,0.0),soft=1.5*max(envelope-spread,0.0)+.1;
			result.x-=q.y/sqrt(q.y*q.y+soft*soft)*envelope*exp(-max(abs(q.y)-spread,0.0)/2.2)*radius.y*7.1
				*(1.0-smoothstep(.05,.075,abs(offset.y)))*(1.0-smoothstep(.26,.36,abs(offset.x)));
			q.y/=fuller*pow(max(1.0-q.x*q.x,.02),taper-.5);
			float angle=atan(q.y,q.x),e=length(q),slope=max(length(q/radius)/max(e,1e-4),1e-4);
			float outline=1.0+.04*sin(2.0*angle+6.3*b.z);
			// The dark edge thickens and thins around each knot on its own two harmonics.
			float width=max(.0016*clamp(1.0+.75*sin(angle+6.3*b.w)+.45*sin(3.0*angle+6.3*c.z),.2,2.4),footprint);
			result.y=max(result.y,.3*mix(.3,1.0,c.w)*exp(-pow((e-outline)/slope/width,2.0)));
			float inside=1.0-smoothstep(outline-.15,outline-.03,e);
			if(inside>result.z)
			{
				// Ring count follows the knot's size: two for the smallest, never more than five for the largest.
				float bands=mix(2.0,4.8,a.w);
				// Only roughly concentric: a smooth warp drifts the rings apart unevenly, and finer noise lets single
				// bands pinch and swell on their own. The pith sits off centre so the bands crowd one side.
				vec2 drift=vec2(noise3(vec3(q*1.7,c.x*31.0)),noise3(vec3(q*1.7,c.y*31.0+7.0)))-.5;
				float growth=pow(length(q-.45*(vec2(c.y,c.z)-.5)+.35*drift),.85)*bands
					+.12*sin(2.0*angle+6.3*fract(c.w*17.3))+.35*(noise3(vec3(q*3.1,c.z*23.0))-.5);
				// Rings are fine lines on the board's own scale, a hair thinner and lighter than its grain lines (about
				// 1.3 mm wide and 4.5-8.5% darker, where the grain's run 1.6 mm and 8%). Below a pixel a line widens
				// and fades together, keeping its average tone.
				float ring=abs(fract(growth+.5)-.5)/max(bands*slope,1e-4),width=max(.0008,footprint*.8);
				float visible=1.0-smoothstep(.4,.9,footprint*bands/min(radius.x,radius.y));
				result.z=inside;
				result.w=exp(-pow(ring/width,2.0))*.0008/width*mix(.045,.085,fract(c.w*29.3))*visible;
			}
			}}
	return result;}
// Grain runs along u. A gentle wave and a slow drift bend the grain lines across it, and two copies of the texture at
// hashed offsets crossfade over large irregular regions so its 37 cm repeat never lines up along a rail. Offsets only,
// never rotation, keep the grain direction; explicit gradients keep region seams and branches on the right mip.
vec3 woodGrain(vec2 uv,vec2 dx,vec2 dy,float centre){vec4 knots=woodKnots(uv,length((abs(dx)+abs(dy))/vec2(2.7,7.1)),centre);
	float drift=noise3(vec3(uv*vec2(.31,.8),3.7))-.5;
	uv.y+=.16*sin(uv.x*1.7+drift*2.0)+drift*.45+knots.x;
	float region=noise3(vec3(uv*.28,9.1))*5.0,index=floor(region),fade=smoothstep(.25,.75,fract(region));
	vec2 first=vec2(random(vec3(index,1.3,7.1)),random(vec3(index,5.2,2.9)))*17.0;
	vec2 second=vec2(random(vec3(index+1.0,1.3,7.1)),random(vec3(index+1.0,5.2,2.9)))*17.0;
	vec3 grain=mix(textureGrad(uWood,uv+first,dx,dy).rgb,textureGrad(uWood,uv+second,dx,dy).rgb,fade),dark=vec3(.161,.063,.024);
	// Inside a knot the board grain gives way to the wood's average tone carrying the knot's own rings.
	vec3 average=textureLod(uWood,vec2(.5),8.0).rgb;
	vec3 knot=average*(1.0-knots.w);
	return mix(mix(grain,knot,knots.z),dark,knots.y);}
// Triplanar wood; projections carrying under 1% of the blend are skipped, with derivatives taken before branching.
// The rails meet in 45-degree miters at the corners: the end boards run their grain along z, and an offset keeps their
// pattern from continuing across the joint, so the change of grain alone marks the seam.
vec3 wood(vec3 p,vec3 n){vec3 blend=pow(abs(n),vec3(6.0));blend*=step(.01,blend/max(.001,blend.x+blend.y+blend.z));
	blend/=max(.001,blend.x+blend.y+blend.z);vec2 scale=vec2(2.7,7.1);vec3 px=dFdx(p),py=dFdy(p),result=vec3(0.0);
	float miter=abs(p.x)-abs(p.z)-(uTableSize.x-uTableSize.y)*.5,endBoard=step(0.0,miter);
	vec2 top=mix(p.xz,p.zx+vec2(31.7,17.3),endBoard),topX=mix(px.xz,px.zx,endBoard),topY=mix(py.xz,py.zx,endBoard);
	if(blend.x>0.0)result+=woodGrain(p.zy*scale,px.zy*scale,py.zy*scale,0.0)*blend.x;
	if(blend.y>0.0)result+=woodGrain(top*scale,topX*scale,topY*scale,17.3*endBoard)*blend.y;
	if(blend.z>0.0)result+=woodGrain(p.xy*scale,px.xy*scale,py.xy*scale,0.0)*blend.z;
	return result;}
// The cue from tip to butt, by distance from the tip (u, metres) and turn around the shaft (v): a leather tip chalked
// on its face, ivory ferrule, honey maple shaft, steel collar between black rings, a figured forearm with four ebony
// points edged in the player's veneer, rings, Irish linen wrap, rings, an ebony butt sleeve with diamond inlays, and a
// rubber bumper. Hands back each zone's roughness, clearcoat, and whether it is metal.
// Every edge is antialiased by its own screen-space rate of change, taken before any branch. The point veneers are
// measured around the cue, since the points run nearly along it, and all patterns wrap whole times around the turn
// seam. Veneers and rings are wide enough to hold their shape when the cue turns side-on.
float cueEdge(float value,float at,float aa){return smoothstep(at-aa,at+aa,value);}
float cueBand(float value,float from,float to,float aa){return cueEdge(value,from,aa)*(1.0-cueEdge(value,to,aa));}
vec3 cueSurface(vec2 uv,vec3 accent,out float rough,out float coat,out bool metal){float d=uv.x,turn=uv.y,angle=turn*6.2832;
	float aa=max(fwidth(d),.0002);
	vec3 shaftGrain=texture(uWood,vec2(d*1.1,turn)).rgb;float figure=texture(uWood,vec2(d*2.6,turn+.37)).r;
	// Distance around the cue from the nearest point edge, in metres; positive inside a point.
	float radius=mix(.0109,.0128,clamp((d-.686)/.299,0.0,1.0)),across=abs(fract(turn*4.0)-.5)*2.0;
	float point=((d-.76)/.225-across)*radius*.7854,pointAa=max(fwidth(point),.00015);
	float diamond=abs(d-1.285)/.024+abs(fract(turn*4.0+.125)-.5)*.0225/.0065,diamondAa=max(fwidth(diamond),.02);
	vec3 ebony=vec3(.05,.035,.03),black=vec3(.02),ivory=vec3(.93,.91,.85);
	metal=false;coat=1.0;rough=.42;
	if(d<.007){rough=.7;coat=0.0;return mix(vec3(.3,.18,.11),vec3(.3,.48,.72),1.0-smoothstep(.0008,.0016,d));}
	if(d<.032){rough=.25;coat=.6;return ivory;}
	// Half-way between pale maple and the warm cue wood, so the shaft reads as honey maple rather than bleached.
	if(d<.655)return mix(vec3(.75,.57,.31)*shaftGrain*1.45,vec3(.9,.77,.57)*(.75+.55*shaftGrain.r),.5);
	if(d<.686)
	{
		float collar=cueBand(d,.658,.683,aa);metal=collar>.5;rough=mix(.42,.22,collar);coat=mix(1.0,.2,collar);
		return mix(black,vec3(.78,.78,.8),collar);
	}
	if(d<.985)
	{
		// Four ebony points run from the wrap toward the joint, each edged with black, ivory, and accent veneers.
		vec3 wood=vec3(.62,.38,.2)*(.55+.9*figure)*(1.0+.12*(noise3(vec3(d*700.0,cos(angle)*9.0,sin(angle)*9.0))-.5));
		vec3 surface=mix(wood,black,cueBand(point,-.0058,-.0042,pointAa));
		surface=mix(surface,ivory,cueBand(point,-.0042,-.0024,pointAa));
		surface=mix(surface,accent,cueBand(point,-.0024,0.0,pointAa));
		return mix(surface,ebony*(.8+.4*figure),cueEdge(point,0.0,pointAa));
	}
	if(d<.995||(d>1.225&&d<1.235))
	{
		float start=d<1.0?.985:1.225;
		return mix(black,accent,cueBand(d,start+.003,start+.007,aa));
	}
	if(d<1.225)
	{
		// Irish linen: black thread wound in a fine diagonal weave, a whole number of threads around, flecked with grey.
		rough=.85;coat=0.0;
		float weave=sin(d*2600.0+angle*35.0)*sin(d*2600.0-angle*35.0),fleck=step(.93,random(floor(vec3(d*1500.0,turn*110.0,1.0))));
		float fine=1.0-smoothstep(.0004,.0012,aa);
		return vec3(.045,.045,.05)*(1.0+.35*weave*fine)+vec3(.22)*fleck*fine;
	}
	if(d<1.335)
	{
		// Ebony sleeve with an accent diamond under each point and ivory rings near both ends.
		vec3 sleeve=mix(ebony*(.8+.4*figure),accent,1.0-cueEdge(diamond,1.0,diamondAa));
		return mix(sleeve,ivory,cueBand(d,1.24,1.244,aa)+cueBand(d,1.326,1.33,aa));
	}
	rough=.85;coat=0.0;return vec3(.03);}
vec3 ballSkin(vec2 uv){uv=vec2(uv.x,1.0-uv.y);vec2 size=vec2(textureSize(uBallSkins,0).xy);
	vec2 dx=dFdx(uv),dy=dFdy(uv);float footprint=max(length(dx*size),length(dy*size));
	float limit=min(1.0,64.0/max(footprint,.0001));return textureGrad(uBallSkins,vec3(uv,vLayer),dx*limit,dy*limit).rgb;}
float sightDisc(vec2 point,vec2 centre){float distanceToCentre=length(point-centre),edge=max(fwidth(distanceToCentre),.00018);
	return 1.0-smoothstep(.006-edge,.006+edge,distanceToCentre);}
float railSightMask(vec2 point){float result=0.0,offset=.0936625;
	for(int step=1;step<8;step++){if(step==4)continue;float x=-uTableSize.x*.5+float(step)*uTableSize.x/8.0;
		result=max(result,sightDisc(point,vec2(x,uTableSize.y*.5+offset)));
		result=max(result,sightDisc(point,vec2(x,-uTableSize.y*.5-offset)));}
	for(int step=1;step<4;step++){float z=-uTableSize.y*.5+float(step)*uTableSize.y/4.0;
		result=max(result,sightDisc(point,vec2(uTableSize.x*.5+offset,z)));
		result=max(result,sightDisc(point,vec2(-uTableSize.x*.5-offset,z)));}
	return result;}
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
// felt.svg keeps its noise within about 6% of mid-grey so the pocket-scale macro layer never reads as dirt. The
// micro-grain and loose-fibre layers stretch that range back out, or the fuzz would vanish with the macro contrast.
float feltDetail(float value){return clamp((value-.5)*8.5+.5,0.0,1.0);}
vec3 feltLayers(vec2 feltUv){float macro=texture(uFelt,feltUv*.55).r,grain=feltDetail(texture(uFelt,feltUv*7.0+vec2(.17,.43)).r)-.5;
	float looseFiber=smoothstep(.56,.62,feltDetail(texture(uFelt,feltUv*15.0+vec2(.61,.23)).r));return vec3(macro,grain,looseFiber);}
void main(){vec4 tint=uInstanced?vTint:uBase;vec3 N=normalize(vNormal),V=normalize(uEye-vPosition),base=tint.rgb;float mode=uMaterial.z;
	float cueRough=uMaterial.x,cueCoat=1.0;bool cueMetal=false;
	// Unlit overlays for aiming aids: 7 is a flat tint, 8 is an antialiased ring on the unit disc.
	if(mode>6.5&&mode<7.5){color=vec4(base,tint.a);return;}
	if(mode>7.5&&mode<8.5){float r=length(vUv-.5)*2.0,w=max(fwidth(r),.02);
		float ring=smoothstep(.8-w,.8+w,r)*(1.0-smoothstep(1.0-w,1.0,r))+.18*(1.0-smoothstep(.8-w,.8+w,r));
		if(ring<.002)discard;color=vec4(base,tint.a*ring);return;}
	if(mode>3.5&&mode<4.5){float edge=length(vUv-.5)*2.0,opacity=(1.0-smoothstep(.18,1.0,edge))*tint.a;
		if(opacity<.002)discard;color=vec4(vec3(0.0),opacity);return;}
	if(mode>0.5&&mode<1.5)base=ballSkin(vUv);
	else if(mode>5.5)base=cueSurface(vUv,tint.rgb,cueRough,cueCoat,cueMetal);
	else if(mode>1.5&&mode<2.5){base*=wood(vPosition,N)*1.45;
		if(mode<2.5){float sight=railSightMask(vPosition.xz)*smoothstep(.78,.96,N.y)*step(.08,vPosition.y);
			float pearl=noise3(vPosition*190.0+vec3(4.3,8.1,2.6));vec3 ivory=vec3(.72,.68,.52)+vec3(.12,.13,.15)*pearl;
			base=mix(base,ivory,sight);}
		}
	else if(mode>2.5&&mode<3.5){
		// The cloth is mapped from above; cushion pads and pocket facings run the texture along their own faces.
		vec2 along=vec2(-N.z,N.x);along/=max(length(along),.001);float topWeight=smoothstep(.6,.8,abs(N.y));
		vec3 felt=feltLayers(vPosition.xz*1.6)*topWeight+feltLayers(vec2(dot(vPosition.xz,along),vPosition.y)*1.6)*(1.0-topWeight);
		float macro=felt.x,grain=felt.y,looseFiber=felt.z;
		float height=(macro-.5)*.00055+grain*.00021+looseFiber*.00009;
		N=bumpNormal(vPosition,N,height);float top=smoothstep(.62,.92,N.y);
		float track=exp(-pow(abs(vPosition.z-.055*sin(vPosition.x*5.0))*18.0,2.0))*top;
		base*=.92+macro*.13+grain*.08+looseFiber*.05;base=mix(base,base*1.04,track*.035);
		// Head and foot spots: thin muted cream stickers on the bed, one under the rack's apex ball and its mirror at
		// the head end. They sit flat, so the cloth's fuzz does not show through them.
		float spot=length(vec2(abs(vPosition.x)-uSpot,vPosition.z)),edge=max(fwidth(spot),.0002);
		spot=(1.0-smoothstep(.009-edge,.009+edge,spot))*step(.9,top);
		// Soft, faint mottling, fading out once its blotches shrink below a pixel.
		float speckle=(noise3(vec3(vPosition.xz*500.0,7.0))-.5)*.08*(1.0-smoothstep(.002,.004,length(fwidth(vPosition.xz))));
		base=mix(base,vec3(.44,.43,.39)*(1.0+speckle),spot);N=normalize(mix(N,vec3(0.0,1.0,0.0),spot));}
	float rough=mode>5.5?cueRough:uMaterial.x;if(mode>0.5&&mode<1.5)rough=clamp(rough+(random(floor(vPosition*900.0))-.5)*.012,.01,.05);
	float ior=uMaterial.y>1.0?uMaterial.y:1.5,f0Value=pow((ior-1.0)/(ior+1.0),2.0),nv=max(dot(N,V),.001);
	bool metallic=(mode>4.5&&mode<5.5)||cueMetal;
	bool varnishedWood=(mode>1.5&&mode<2.5)||mode>5.5;
	if(varnishedWood||metallic)rough=specularAaRoughness(rough,N);
	vec3 f0=metallic?mix(vec3(.04),base,.82):vec3(f0Value),key=vec3(-.45,2.8,-.7),fill=vec3(1.8,1.45,2.15);
	float skyAmbient=clamp(N.y*.5+.5,0.0,1.0);
	vec3 ambient=mix(vec3(.15,.21,.17),vec3(.25,.27,.3),skyAmbient);
	vec3 diffuseBase=metallic?base*.14:base;
	vec3 lit=diffuseBase*ambient+lightPbr(key,vec3(1.0,.94,.82),3.7,N,V,diffuseBase,f0,rough,.14)
		+lightPbr(fill,vec3(.58,.72,1.0),.58,N,V,diffuseBase,f0,rough,.18);
	if(mode>0.5&&mode<1.5){lit+=base*.035*(1.0-max(dot(N,normalize(key-vPosition)),0.0));
		float coat=coatSpec(key,N,V,.018,.16)*3.7+coatSpec(fill,N,V,.028,.2)*.58;
		lit+=vec3(.55*coat/(1.0+coat))*uMaterial.w;}
	else if(varnishedWood){float keyRough=specularAaRoughness(.11,N),fillRough=specularAaRoughness(.14,N);
		float coat=coatSpec(key,N,V,keyRough,.12)*3.7+coatSpec(fill,N,V,fillRough,.16)*.58;
		lit+=vec3(.55*coat/(1.0+coat))*uMaterial.w*cueCoat;}
	else if(mode>2.5&&mode<3.5){float backLight=max(dot(-N,normalize(key-vPosition)),0.0);
		vec3 fiberTint=mix(base,sqrt(max(base,vec3(0.0))),.28);
		lit+=base*(.04+.055*backLight)+fiberTint*pow(1.0-nv,1.8)*uMaterial.w*.68;}
	else lit+=base*pow(1.0-nv,3.0)*.08;
	color=vec4(lit,tint.a);
}
