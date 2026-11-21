const int STIPPLE_PATTERN = 5;
const int FRACTAL_PATTERN = 6;
const int VEIN_PATTERN = 7;
uniform int uLayerCount;
uniform vec3 uLayerColor[12];
uniform vec4 uLayerRange[12],uLayerBlend[12],uLayerMode[12];
uniform vec4 uLayerPattern[12];
uniform vec3 uLayerPatternColor[12];
uniform vec2 uPaletteOffset;
float layerCorner(ivec2 cell) {
	uvec2 p=uvec2(cell);
	uint h=p.x*1597334677u ^ p.y*3812015801u;
	h=(h^(h>>16))*2246822519u;
	return float(h>>8)*(2./16777216.)-1.;
}
float layerValueNoise(vec2 uv) {
	vec2 t=fract(uv);
	t=t*t*(3.-2.*t);
	ivec2 c=ivec2(floor(uv));
	return mix(mix(layerCorner(c),layerCorner(c+ivec2(1,0)),t.x),
		mix(layerCorner(c+ivec2(0,1)),layerCorner(c+1),t.x),t.y);
}
float fractalMask(vec3 pos,float scale,float stretch,float footprint,bool veins) {
	vec3 p=vec3(pos.x+uPaletteOffset.x,pos.y/stretch,pos.z+uPaletteOffset.y)/scale;
	float pixel=footprint/(scale*min(1.,stretch));
	if(veins && pixel>=.28) return .37;
	float field=0., amplitude=.46, coarse=0.;
	const mat2 turn=mat2(.8,.6,-.6,.8);
	for(int octave=0;octave<3;octave++) {
		if(pixel>=.8) break;
		// Tilt each projection through world space so a stain continues around corners.
		float n=layerValueNoise(p.xz+p.y*vec2(.64,.83));
		if(octave==0) coarse=n;
		field+=amplitude*n*(1.-smoothstep(.22,.8,pixel));
		// Rotate the finer bands and bend them slightly around the coarser patches.
		p.xz=turn*(3.25*p.xz)+vec2(13.7,27.9)+n*vec2(1.25,-.85);
		p.y=-3.25*p.y+17.2;
		amplitude*=.72;
		pixel*=3.25;
	}
	// Each unresolved band converges to its mean, including on the minimap.
	float patches=smoothstep(-.43,.43,field);
	if(!veins) return patches;
	// Dark seams taper and break along the broad stains, keeping the stone between them visible.
	float seams=(1.-smoothstep(.024,.11,abs(field+.06)))*smoothstep(-.52,.25,coarse);
	float ink=.60*patches+.40*seams;
	return mix(ink,.37,smoothstep(.075,.28,footprint/(scale*min(1.,stretch))));
}
float layerNoise(vec2 xz,float scale,float footprint) {
	return (1.-smoothstep(.3,1.,footprint/scale))*layerValueNoise((xz+uPaletteOffset)/scale);
}

float patternMask(vec2 uv, int kind) {
	if(kind==STIPPLE_PATTERN) {
		ivec2 cell=ivec2(floor(uv));
		float seed=.5+.5*layerCorner(cell+ivec2(89,13));
		vec2 center=vec2(.5)+.25*vec2(layerCorner(cell),layerCorner(cell+ivec2(29,7)));
		float radius=mix(.07,.18,seed);
		return smoothstep(.10,.40,seed)*(1.-smoothstep(radius-.03,radius+.05,length(fract(uv)-center)));
	}
	if(kind==3) {
		ivec2 cell=ivec2(floor(uv));
		float grit=.5+.5*layerCorner(cell);
		vec2 center=vec2(.5)+.12*vec2(grit-.5,layerCorner(cell+ivec2(31,17)));
		float radius=mix(.12,.29,grit);
		return grit*(1.-smoothstep(radius-.04,radius+.04,length(fract(uv)-center)));
	}
	float noise=layerValueNoise(uv);
	if(kind==2 || kind==4) return 1.-smoothstep(.04,.31,abs(noise));
	return smoothstep(-.55,.55,noise);
}

float patternVisibility(float footprint,float scale,int kind) {
	// Narrow streaks and small flecks disappear earlier than large patches of color.
	float start=kind==STIPPLE_PATTERN ? .045 : (kind==1 ? .2 : (kind==3 ? .06 : .11));
	return 1.-smoothstep(start,start*3.,footprint/scale);
}

float projectedPattern(vec3 pos,vec3 weight,float scale,float stretch,vec2 offset,int kind) {
	vec2 verticalScale=scale*vec2(1.,stretch);
	float amount=0.;
	if(weight.x>0.) amount+=weight.x*patternMask((pos.zy+offset)/verticalScale,kind);
	if(weight.y>0.) amount+=weight.y*patternMask((pos.xz+offset)/scale,kind);
	if(weight.z>0.) amount+=weight.z*patternMask((pos.xy+offset.yx)/verticalScale,kind);
	return amount;
}

vec3 layerColor(int layer,vec3 pos,vec3 normal,float footprint,float beddingHeight,float beddingPixel) {
	vec3 base=uLayerColor[layer];
	vec4 pattern=uLayerPattern[layer]; // kind, amount, horizontal scale, vertical stretch
	int kind=int(pattern.x);
	if(kind==0 || pattern.y<=0.) return base;
	if(kind==FRACTAL_PATTERN || kind==VEIN_PATTERN)
		return mix(base,uLayerPatternColor[layer],pattern.y*fractalMask(pos,pattern.z,pattern.w,footprint,kind==VEIN_PATTERN));

	float visibility=patternVisibility(footprint,pattern.z,kind);
	float partings=0.;
	if(kind==4) {
		// Fine sediment lines follow the dipped bed, even where the weathering streaks run across it.
		float spacing=pattern.z*.19;
		float resolved=1.-smoothstep(.28,1.05,beddingPixel/spacing);
		if(resolved>0.) {
			float grain=layerValueNoise(vec2(beddingHeight/spacing+uPaletteOffset.y,.618));
			partings=resolved*smoothstep(-.28,.62,grain);
		}
	}
	if(visibility<=0.) return mix(base,uLayerPatternColor[layer],pattern.y*.52*partings);

	// Project onto the ground and both upright planes, in world space. Blending the
	// projections keeps the tint continuous where cliff faces turn a corner.
	vec3 weight=abs(normal);
	weight*=weight;
	weight/=max(.0001,dot(weight,vec3(1.)));
	vec2 offset=uPaletteOffset+vec2(73.37,19.81);
	float amount=projectedPattern(pos,weight,pattern.z,pattern.w,offset,kind);
	return mix(base,uLayerPatternColor[layer],pattern.y*max(visibility*amount,.52*partings));
}
float repeatingMask(float height,vec2 range,float fade,float period,float footprint) {
	float width=clamp(range.y-range.x,0.,period);
	if(width==period) return 1.;
	float center=.5*(range.x+range.y);
	float distanceToCenter=abs(mod(height-center+.5*period,period)-.5*period);
	// Use the unwrapped height derivative. A pixel that straddles the wrap still sees an ordinary bed contact.
	float edge=max(fade,.65*footprint);
	float mask=1.-smoothstep(.5*width-edge,.5*width+edge,distanceToCenter);
	return mix(mask,width/period,smoothstep(.16,.55,footprint/period));
}

vec3 layerSurface(vec3 pos,vec3 normal,float footprint,float water) {
	float masks[12];
	float beddingHeight=pos.y-dot(pos.xz,vec2(.006,-.003));
	float beddingPixel=fwidth(beddingHeight);
	vec3 result=layerColor(0,pos,normal,footprint,beddingHeight,beddingPixel);
	float slope=1.-clamp(normal.y,0.,1.);
	vec3 sectionColor=vec3(0.);
	float sectionCoverage=0.;
	masks[0]=1.;
	// Contiguous sibling beds share their elevation noise, pixel width, slope and parent.
	bool reuseMask=false;
	float h=0., heightPixel=0., cliffMask=0.;
	for(int i=1;i<12;i++) {
		if(i>=uLayerCount) break;
		vec4 r=uLayerRange[i],b=uLayerBlend[i],m=uLayerMode[i];
		if(!reuseMask) {
			float noise=b.z>0. ? layerNoise(pos.xz,b.w,footprint) : 0.;
			h=(m.y==2. ? beddingHeight : pos.y-(m.y==1. ? water : 0.))+b.z*noise;
			if(m.z>0.) {
				h+=.5*m.z;
				heightPixel=fwidth(h);
			}
			float sf=max(.00001,b.y);
			cliffMask=smoothstep(r.z-sf,r.z,slope)*(1.-smoothstep(r.w,r.w+sf,slope));
			if(m.x>=0.) cliffMask*=masks[int(m.x)];
		}
		reuseMask=m.w==1.;
		float fade=max(.0001,b.x), heightMask=0.;
		if(cliffMask>0.) {
			heightMask=m.z>0. ? repeatingMask(h,r.xy,fade,m.z,heightPixel)
				: smoothstep(r.x-fade,r.x,h)*(1.-smoothstep(r.y,r.y+fade,h));
		}
		float mask=heightMask*cliffMask;
		masks[i]=mask;

		if(mask>0.) {
			vec3 shade=layerColor(i,pos,normal,footprint,beddingHeight,beddingPixel);
			if(m.w==0.) result=mix(result,shade,mask);
			else {
				sectionColor+=shade*heightMask;
				sectionCoverage+=heightMask;
			}
		}
		if(m.w==2.) {
			// A full section shares one slope and parent mask. Blend its adjacent beds before applying them.
			if(sectionCoverage>0.) result=mix(result,sectionColor/sectionCoverage,cliffMask);
			sectionColor=vec3(0.);
			sectionCoverage=0.;
		}
	}
	return result;
}
