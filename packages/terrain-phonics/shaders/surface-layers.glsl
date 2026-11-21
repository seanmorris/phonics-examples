const int STIPPLE_PATTERN = 5;
const int FRACTAL_PATTERN = 6;
const int VEIN_PATTERN = 7;
uniform int uLayerCount;
uniform vec4 uFieldScale;
uniform vec4 uLayerEffect[16]; // relief strength, flattening, opacity, unrestricted range
uniform vec3 uLayerColor[16];
uniform vec4 uLayerRange[16],uLayerBlend[16],uLayerMode[16];
uniform vec4 uLayerPattern[16];
uniform vec3 uLayerPatternColor[16];
uniform vec2 uPaletteOffset;
float layerCorner(ivec2 cell) {
	uvec2 p=uvec2(cell);
	uint h=p.x*1597334677u ^ p.y*3812015801u;
	h=(h^(h>>16))*2246822519u;
	return float(h>>8)*(2./16777216.)-1.;
}
// Value and partials with respect to the two interpolation weights.
vec3 layerCellSample(ivec2 cell,vec2 t) {
	float a=layerCorner(cell), b=layerCorner(cell+ivec2(1,0));
	float c=layerCorner(cell+ivec2(0,1)), d=layerCorner(cell+1);
	float lower=mix(a,b,t.x), upper=mix(c,d,t.x);
	return vec3(mix(lower,upper,t.y),mix(b-a,d-c,t.y),upper-lower);
}
float layerValueNoise(vec2 uv) {
	vec2 t=fract(uv);
	return layerCellSample(ivec2(floor(uv)),t*t*(3.-2.*t)).x;
}
const mat3 MATERIAL_TURN=mat3(0.,.8,.6, -.8,.36,-.48, -.6,-.48,.64);
// Value and spatial gradient from the same eight corners, including on an upright face.
vec4 materialVolumeSample(vec3 p) {
	const ivec2 slice=ivec2(61,149);
	ivec3 cell=ivec3(floor(p));
	vec3 f=fract(p), t=f*f*(3.-2.*f);
	// Salt the integer lattice so the landscape seed does not cost subpixel precision.
	ivec2 base=cell.xz+cell.y*slice+ivec2(uPaletteOffset);
	vec3 lower=layerCellSample(base,t.xz), upper=layerCellSample(base+slice,t.xz);
	vec3 sampleValue=mix(lower,upper,t.y);
	return vec4(sampleValue.x,6.*f*(1.-f)*vec3(sampleValue.y,upper.x-lower.x,sampleValue.z));
}
float materialVolumeNoise(vec3 p) {
	return materialVolumeSample(p).x;
}

// Signed volume noise gives cliffs and level ground the same grain. At the ridge's 1.6 m
// pattern scale the five bands run from 53 cm down to 7 mm. Each returns to its mean
// before its cells are less than two pixels wide.
float stippleGrain(vec3 pos,float scale,float stretch,float footprint) {
	vec3 p=vec3(pos.x,pos.y/stretch,pos.z)*(3./scale);
	float pixel=3.*footprint/(scale*min(1.,stretch));
	float sum=0., amplitude=.65;
	for(int octave=0;octave<5;octave++) {
		if(pixel>=.5) break;
		sum+=amplitude*(1.-smoothstep(.16,.5,pixel))*materialVolumeNoise(p);
		p=MATERIAL_TURN*p*3.+vec3(13.7,7.1,27.9);
		pixel*=3.;
		amplitude*=.80;
	}
	return sum;
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
	if(kind==3) {
		ivec2 cell=ivec2(floor(uv));
		float grit=.5+.5*layerCorner(cell);
		vec2 center=vec2(.5)+.12*vec2(grit-.5,layerCorner(cell+ivec2(31,17)));
		float radius=mix(.12,.29,grit);
		return grit*(1.-smoothstep(radius-.04,radius+.04,length(fract(uv)-center)));
	}
	if(kind==8) return .5+.5*sin(6.28318*(uv.x+.23*layerValueNoise(uv*.42)));
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

float layerPatternWeight(int layer,vec3 pos,vec3 normal,float footprint,float beddingHeight,float beddingPixel) {

	vec4 pattern=uLayerPattern[layer]; // kind, amount, horizontal scale, vertical stretch
	int kind=int(pattern.x);
	if(kind==0 || pattern.y<=0.) return 0.;
	#if !defined(MATERIAL_STACK) || defined(PATTERN_STIPPLE)
	if(kind==STIPPLE_PATTERN) {
		float visible=patternVisibility(footprint,pattern.z*min(1.,pattern.w),kind);
		if(visible<=0.) return 0.;
		float grain=stippleGrain(pos,pattern.z,pattern.w,footprint);
		float ink=clamp(.18+grain,0.,1.);
		return pattern.y*visible*ink*ink;
	}
	#endif
	#if !defined(MATERIAL_STACK) || defined(PATTERN_FRACTAL) || defined(PATTERN_VEINS)
	if(kind==FRACTAL_PATTERN || kind==VEIN_PATTERN)
		return pattern.y*fractalMask(pos,pattern.z,pattern.w,footprint,kind==VEIN_PATTERN);
	#endif

	float visibility=patternVisibility(footprint,pattern.z,kind);
	float partings=0.;
	#if !defined(MATERIAL_STACK) || defined(PATTERN_BEDDED)
	if(kind==4) {
		// Fine sediment lines follow the dipped bed, even where the weathering streaks run across it.
		float spacing=pattern.z*.19;
		float resolved=1.-smoothstep(.28,1.05,beddingPixel/spacing);
		if(resolved>0.) {
			float grain=layerValueNoise(vec2(beddingHeight/spacing+uPaletteOffset.y,.618));
			partings=resolved*smoothstep(-.28,.62,grain);
		}
	}
	#endif
	if(visibility<=0.) return pattern.y*.52*partings;

	// Project onto the ground and both upright planes, in world space. Blending the
	// projections keeps the tint continuous where cliff faces turn a corner.
	vec3 weight=abs(normal);
	weight*=weight;
	weight/=max(.0001,dot(weight,vec3(1.)));
	vec2 offset=uPaletteOffset+vec2(73.37,19.81);
	float amount=projectedPattern(pos,weight,pattern.z,pattern.w,offset,kind);
	return pattern.y*max(visibility*amount,.52*partings);
}

vec3 layerColor(int layer,vec3 pos,vec3 normal,float footprint,float beddingHeight,float beddingPixel) {
	return mix(uLayerColor[layer],uLayerPatternColor[layer],layerPatternWeight(layer,pos,normal,footprint,beddingHeight,beddingPixel));
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
	float masks[16];
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
	for(int i=1;i<16;i++) {
		if(i>=uLayerCount) break;
		vec4 r=uLayerRange[i],b=uLayerBlend[i],m=uLayerMode[i];
		#ifdef MATERIAL_STACK
		if(uLayerEffect[i].z==0. && m.w==0.) { masks[i]=0.; reuseMask=false; continue; }
		#endif
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
		#ifdef MATERIAL_STACK
		float coverage=cliffMask*uLayerEffect[i].z;
		#else
		float coverage=cliffMask;
		#endif
		float mask=heightMask*coverage;
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
			if(sectionCoverage>0.) result=mix(result,sectionColor/sectionCoverage,coverage);
			sectionColor=vec3(0.);
			sectionCoverage=0.;
		}
	}
	return result;
}

// Keep screen derivatives of the scalar field independent of a layer's coverage boundary.
vec3 patternGradient(vec3 pos,vec3 normal,float height) {
	vec3 dx=dFdx(pos), dy=dFdy(pos), u=cross(dy,normal), v=cross(normal,dx);
	float det=dot(dx,u);
	vec3 gradient=(dFdx(height)*u+dFdy(height)*v)*sign(det)/max(.0001,abs(det));
	return -gradient/(1.+length(gradient));
}
void materialRange(int i,vec3 pos,float slope,float footprint,float water,float bed,out float edge,out float coverage) {
	vec4 r=uLayerRange[i],b=uLayerBlend[i],m=uLayerMode[i];
	float h=(m.y==2. ? bed : pos.y-(m.y==1. ? water : 0.));
	if(b.z>0.) h+=b.z*layerNoise(pos.xz,b.w,footprint);
	float sf=max(.00001,b.y),fade=max(.0001,b.x);
	coverage=smoothstep(r.z-sf,r.z,slope)*(1.-smoothstep(r.w,r.w+sf,slope));
	edge=m.z>0. ? repeatingMask(h+.5*m.z,r.xy,fade,m.z,fwidth(h))
		: smoothstep(r.x-fade,r.x,h)*(1.-smoothstep(r.y,r.y+fade,h));
}
