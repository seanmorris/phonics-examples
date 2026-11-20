#version 300 es
precision highp float;
precision highp int;
uniform highp sampler2D uPermutation;
uniform vec4 uShape; // bias, rolling, rise, ridge
uniform vec4 uProfile; // ridge rounding, wall height, floor width, wall width
uniform vec4 uField; // scale, frequency, warp amplitude, warp frequency
uniform vec2 uFieldResidual;
uniform vec4 uRock; // secondary, terrace spacing, strength, bedding
uniform vec4 uDetail; // amplitude, persistence, lacunarity, damping
uniform vec4 uRegion; // half extent, surround type, transition, sea level
uniform float uSlopeScale;
uniform int uOctaves;
uniform ivec2 uOriginCell, uPixelOrigin;
uniform float uStep;
uniform vec2 uWorldOffset;
uniform bool uPacked,uNaturalMountains;
layout(location=0) out vec4 color;
layout(location=1) out vec4 ink;

const vec2 gradient[8] = vec2[8](vec2(1,0),vec2(-1,0),vec2(0,1),vec2(0,-1),
	vec2(.7071067811865476),vec2(-.7071067811865476,.7071067811865476),
	vec2(.7071067811865476,-.7071067811865476),vec2(-.7071067811865476));
float fade(float x){ float t=min(x,1.-x); float y=t*t*t*(t*(6.*t-15.)+10.); return x>.5?1.-y:y; }
float derivative(float x){return 30.*x*x*(x-1.)*(x-1.);}
int perm(int i){return int(texelFetch(uPermutation,ivec2(i & 255,0),0).r*255.+.5);}
vec2 grad(int column,int row){return gradient[perm(column+row)&7];}

// Apply the integer part of each noise offset to the lattice address. Adding a large offset
// to the fractional coordinate first would throw away precision on cliffs.
vec3 sampleNoise(vec2 position, vec2 displacement,float frequency,float residual,ivec2 offset,vec2 fraction)
{
	float coarse=floor(frequency*65536.)/65536.;
	vec2 major=position*coarse, minor=position*(frequency-coarse)+position*residual+displacement*frequency;
	vec2 local=fract(major)+fract(minor)+fraction;
	ivec2 cell=ivec2(floor(major))+ivec2(floor(minor))+offset+ivec2(floor(local));
	vec2 q=fract(local);
	int left=perm(cell.x), right=perm(cell.x+1);
	vec2 a=grad(left,cell.y), b=grad(right,cell.y), c=grad(left,cell.y+1), d=grad(right,cell.y+1);
	float p=dot(a,q), r=dot(b,q-vec2(1,0)), s=dot(c,q-vec2(0,1)), t=dot(d,q-1.);
	float u=fade(q.x), w=fade(q.y), lo=mix(p,r,u), hi=mix(s,t,u);
	vec2 g=mix(mix(a,b,u),mix(c,d,u),w)+vec2(derivative(q.x)*mix(r-p,t-s,w),derivative(q.y)*(hi-lo));
	return vec3(mix(lo,hi,w),g);
}
float terrainHeight(vec2 world)
{
	if(uField.x==0. && (uDetail.x==0. || uOctaves==0)) return 0.;
	vec2 p=world, displacement=vec2(0);
	vec2 wx=vec2(0), wz=vec2(0);
	float f=uField.y;
	if(uField.z!=0.) {
		vec3 x=sampleNoise(world,vec2(0),uField.w,uFieldResidual.y,ivec2(271,-198),vec2(.13,.59));
		vec3 z=sampleNoise(world,vec2(0),uField.w,uFieldResidual.y,ivec2(-420,113),vec2(.73,.71));
		displacement=uField.z*vec2(x.x,z.x); p+=displacement;
		wx=x.yz*uField.z*uField.w; wz=z.yz*uField.z*uField.w;
	}
	vec3 a=sampleNoise(world,displacement,f,uFieldResidual.x,ivec2(13,-10),vec2(.17,.69));
	vec3 b=sampleNoise(world,displacement,f*2.,2.*uFieldResidual.x,ivec2(71,53),vec2(.43,.11));
	vec3 h=vec3(uShape.x,0,0)+uShape.y*a*vec3(1.,f,f)+uRock.x*b*vec3(1.,2.*f,2.*f);
	if(uShape.z!=0.) {
		float coast=tanh(p.x/4500.);
		h+=uShape.z*vec3(coast,(1.-coast*coast)/4500.,0.);
	}
	if(uShape.w!=0.) {
		float rounded=length(vec2(a.x,uProfile.x));
		h-=uShape.w*vec3(rounded,a.x/rounded*a.yz*f);
	}
	float strength=uRock.z;
	vec2 ds=vec2(0);
	bool beds=uRock.w>0. && uRock.y>0. && strength>0.;
	if(uProfile.y>0. || beds) {
		float dist=abs(a.x)-uProfile.z, width=uProfile.w;
		vec2 slope=sign(a.x)*a.yz*f;
		if(beds) {
			float amount=strength*uRock.w;
			vec3 e=sampleNoise(world,displacement,22.*f,22.*uFieldResidual.x,ivec2(43,-82),vec2(.7,.7));
			dist+=amount*(.025-.029*e.x);
			width+=.105*amount;
			slope-=.029*amount*e.yz*22.*f;
			float channel=clamp((e.x+.13)/.62,0.,1.);
			strength=amount*(1.-.8*fade(channel));
			ds=-amount*.8*derivative(channel)*22.*f/.62*e.yz;
			ds+=ds.x*wx+ds.y*wz;
		}
		float t=clamp(dist/width,0.,1.);
		h+=uProfile.y*vec3(fade(t),derivative(t)/width*slope);
	}
	h.yz+=h.y*wx+h.z*wz;
	h*=uField.x;
	if(uRock.y>0. && strength>0.) {
		float start=0.,end=1.,width=1.;
		vec2 dip=beds?vec2(.006,-.003):vec2(0);
		float level=(h.x-dot(dip,world))/uRock.y, frac=fract(level);
		if(beds) {
			const float contacts[8]=float[8](-5.,-3.06,-1.85,-1.29,-.05,1.29,2.04,5.);
			const vec2 ramps[7]=vec2[7](vec2(.03,.98),vec2(.03,.97),vec2(.33,.79),vec2(.14,.84),vec2(.03,.97),vec2(.19,.81),vec2(.26,.76));
			level=mod(level+5.,10.)-5.;
			int i=0; while(i<6 && level>=contacts[i+1]) i++;
			width=contacts[i+1]-contacts[i];
			frac=(level-contacts[i])/width;
			start=ramps[i].x; end=ramps[i].y;
		}
		float t=clamp((frac-start)/(end-start),0.,1.);
		float delta=width*uRock.y*(fade(t)-frac);
		float k=1.+strength*(derivative(t)/(end-start)-1.);
		h.x+=strength*delta;
		h.yz=k*h.yz+(1.-k)*dip+delta*ds;
	}
	float slope=dot(h.yz,h.yz)/(uSlopeScale*uSlopeScale), amp=uDetail.x;
	f*=8.;
	for(int i=0;i<12;i++) {
		if(i>=uOctaves || amp==0.) break;
		vec2 offset=vec2(91.73,-23.49)+float(i)*vec2(19.17,-31.29);
		float k=float(i+1);
		h.x+=amp/(1.+uDetail.w*k*k*slope)*sampleNoise(world,vec2(0),f,0.,ivec2(floor(offset)),fract(offset)).x;
		f*=uDetail.z; amp*=uDetail.y;
	}
	return h.x;
}
void main()
{
	vec2 world=uWorldOffset+vec2(uOriginCell+ivec2(gl_FragCoord.xy)-uPixelOrigin)*uStep;
	float h=terrainHeight(world);
	float outside=max(abs(world.x),abs(world.y))-uRegion.x;
	if(outside>0. && uRegion.y>0. && !(uRegion.y==3. && uNaturalMountains)) {
		float broad=sampleNoise(world,vec2(0),.0005,0.,ivec2(31,7),vec2(0)).x;
		float t=smoothstep(0.,uRegion.z*(1.+.35*broad),outside);
		float target=uField.x*(.45+.09*broad);
		if(uRegion.y==1.) target=uRegion.w-100.-abs(broad)*45.;
		if(uRegion.y==3.) {
			float ridges=sampleNoise(world,vec2(0),.00031,0.,ivec2(-38,119),vec2(.17,.26)).x;
			target=uField.x*(.42-length(vec2(ridges,.04))+.24*broad);
		}
		h=mix(h,target,t);
	}
	ink=vec4(0,0,0,1);
	uint bits=floatBitsToUint(h);
	color=uPacked?vec4(uvec4(bits,bits>>8,bits>>16,bits>>24)&255u)/255.:vec4(h,0,0,1);
}
