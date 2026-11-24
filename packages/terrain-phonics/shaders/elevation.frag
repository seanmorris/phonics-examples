#version 300 es
precision highp float;
precision highp int;
uniform highp sampler2D uPermutation, uRoadAtlas;
// Directory offset (zero when absent), blocks per row, signed minimum tile and tile size.
uniform ivec4 uRoadGrid;
uniform vec4 uRegion; // half extent, surround, transition, sea level
#ifdef JOINED_TERRAIN
uniform int uFormCount;
uniform float uJoinWidth;
uniform vec3 uFormOrigin[3]; // local X/Z origin, vertical offset to the common sea level
int formIndex = 0;
uniform vec4 uShapeSet[3];
#define uShape uShapeSet[formIndex]
uniform vec4 uProfileSet[3];
#define uProfile uProfileSet[formIndex]
uniform vec4 uFieldSet[3];
#define uField uFieldSet[formIndex]
uniform vec4 uNoiseOffsetSet[3];
#define uNoiseOffset uNoiseOffsetSet[formIndex]
uniform vec2 uFieldResidualSet[3];
#define uFieldResidual uFieldResidualSet[formIndex]
uniform vec2 uErosionSet[3];
#define uErosion uErosionSet[formIndex]
uniform vec4 uRockSet[3];
#define uRock uRockSet[formIndex]
uniform vec4 uDetailSet[3];
#define uDetail uDetailSet[formIndex]
uniform float uSlopeScaleSet[3];
#define uSlopeScale uSlopeScaleSet[formIndex]
uniform int uOctavesSet[3];
#define uOctaves uOctavesSet[formIndex]
#else
uniform vec4 uShape; // bias, rolling, rise, ridge
uniform vec4 uProfile; // ridge rounding, wall height, floor width, wall width
uniform vec4 uField; // scale, frequency, warp amplitude, warp frequency
uniform vec4 uNoiseOffset; // integer X/Z and sub-meter X/Z
uniform vec2 uFieldResidual;
uniform vec2 uErosion; // drainage frequency and its float rounding residual
uniform vec4 uRock; // secondary, terrace spacing, strength, bedding
uniform vec4 uDetail; // amplitude, persistence, lacunarity, damping
uniform float uSlopeScale;
uniform int uOctaves;
#endif
#ifdef JOINED_TERRAIN
#define FORM_UNIFORM(T,N) uniform T N[3]
#define FORM_VALUE(N) N[formIndex]
#else
#define FORM_UNIFORM(T,N) uniform T N
#define FORM_VALUE(N) N
#endif
#ifdef FORM_AXES
FORM_UNIFORM(vec4,uMacroAxesSet);
#endif
#if defined(FORM_AXES) || defined(COAST_PROFILE)
FORM_UNIFORM(vec4,uDetailCoastSet);
#endif
#ifdef DETAIL_SHAPE
FORM_UNIFORM(float,uDetailShapeSet);
#endif
#ifdef GULLY_CUT
FORM_UNIFORM(vec2,uGullySet);
#endif
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
#ifdef JOINED_TERRAIN
#define PERM_ROW formIndex
#else
#define PERM_ROW 0
#endif
int perm(int i){return int(texelFetch(uPermutation,ivec2(i & 255,PERM_ROW),0).r*255.+.5);}
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
// A float pair retains the low bits that matter for the steep contour band.
vec2 addPair(vec2 a,vec2 b)
{
	float sum=a.x+b.x, carry=sum-a.x;
	float tail=(a.x-(sum-carry))+(b.x-carry)+a.y+b.y;
	float lead=sum+tail;
	return vec2(lead,(sum-lead)+tail);
}
vec2 multiplyPair(vec2 a,vec2 b)
{
	float product=a.x*b.x;
	float ah=uintBitsToFloat(floatBitsToUint(a.x)&0xfffff000u), al=a.x-ah;
	float bh=uintBitsToFloat(floatBitsToUint(b.x)&0xfffff000u), bl=b.x-bh;
	float error=((ah*bh-product)+ah*bl+al*bh)+al*bl;
	return addPair(vec2(product,error),vec2(a.x*b.y+a.y*b.x,0.));
}
vec2 preciseFade(vec2 t)
{
	vec2 cubic=multiplyPair(multiplyPair(t,t),t);
	vec2 h=addPair(multiplyPair(t,vec2(6.,0.)),vec2(-15.,0.));
	return multiplyPair(cubic,addPair(multiplyPair(t,h),vec2(10.,0.)));
}
vec2 preciseGradient(float g)
{
	return vec2(g,abs(g)==1. || g==0. ? 0. : sign(g)*1.2101617152815436e-8);
}
vec2 preciseDot(vec2 g,vec2 dx,vec2 dz)
{
	return addPair(multiplyPair(preciseGradient(g.x),dx),multiplyPair(preciseGradient(g.y),dz));
}
vec2 preciseMix(vec2 a,vec2 b,vec2 t)
{
	return addPair(a,multiplyPair(addPair(b,-a),t));
}
float preciseContour(vec2 world,vec2 warp)
{
	vec2 frequency=vec2(uField.y,uFieldResidual.x);
	vec2 x=multiplyPair(addPair(vec2(world.x,0.),vec2(warp.x,0.)),frequency);
	vec2 z=multiplyPair(addPair(vec2(world.y,0.),vec2(warp.y,0.)),frequency);
	// The contour offsets are 13.17 and -9.31. Add the fractional parts without
	// discarding their decimal rounding residuals.
	x=addPair(x,vec2(.17,-1.7881393432617188e-9));
	z=addPair(z,vec2(.69,2.384185791015625e-9));
	ivec2 cell=ivec2(floor(vec2(x.x,z.x)))+ivec2(13,-10);
	x=addPair(x,vec2(-floor(x.x),0.));
	z=addPair(z,vec2(-floor(z.x),0.));
	vec2 u=preciseFade(x), v=preciseFade(z);
	vec2 x1=addPair(x,vec2(-1.,0.)), z1=addPair(z,vec2(-1.,0.));
	int left=perm(cell.x), right=perm(cell.x+1);
	vec2 low=preciseMix(preciseDot(grad(left,cell.y),x,z),preciseDot(grad(right,cell.y),x1,z),u);
	vec2 high=preciseMix(preciseDot(grad(left,cell.y+1),x,z1),preciseDot(grad(right,cell.y+1),x1,z1),u);
	return preciseMix(low,high,v).x;
}

#ifdef FORM_AXES
// Preserve the sub-meter remainder when rotating a far-away steep cliff.
vec2 axisPosition(vec2 axis,vec2 world,vec2 subcell)
{
	vec2 q=addPair(multiplyPair(vec2(world.x,0.),vec2(axis.x,0.)),
		multiplyPair(vec2(world.y,0.),vec2(axis.y,0.)));
	return addPair(q,vec2(dot(axis,subcell),0.));
}
#endif
#ifdef GULLY_CUT
float gullyCut(vec2 p,vec2 slope,vec2 settings)
{
	float grade=length(slope);
	if(grade<=.05 || settings.x==0.) return 0.;
	p/=3.*settings.y;
	ivec2 cell=ivec2(floor(p));
	vec2 local=fract(p), blend=vec2(fade(local.x),fade(local.y));
	vec2 across=3.*vec2(-slope.y,slope.x)/grade;
	float cut=0.;
	for(int j=0;j<2;j++) for(int i=0;i<2;i++) {
		float phase=float(perm(perm(cell.x+i)+cell.y+j))/256.;
		float t=dot(local-vec2(i,j),across)+phase;
		float q=max(0.,1.-4.*abs(fract(t+.5)-.5));
		cut+=(i==1?blend.x:1.-blend.x)*(j==1?blend.y:1.-blend.y)*q*q*(3.-2.*q);
	}
	return settings.x*cut*fade(clamp((grade-.05)/.45,0.,1.));
}
#endif

float terrainHeight(vec2 world,vec4 profile,vec2 subcell)
{
	if(uField.x==0. && (uDetail.x==0. || uOctaves==0)) return 0.;
	world+=uNoiseOffset.xy;
	subcell+=uNoiseOffset.zw;
	#ifdef FORM_AXES
	vec4 axes=FORM_VALUE(uMacroAxesSet), dc=FORM_VALUE(uDetailCoastSet);
	vec2 detailWorld=world, detailSubcell=subcell;
	if(any(notEqual(dc.xy,vec2(1,0)))) {
		vec2 fx=axisPosition(dc.xy,world,subcell), fz=axisPosition(vec2(-dc.y,dc.x),world,subcell);
		detailWorld=floor(vec2(fx.x,fz.x)); detailSubcell=fract(vec2(fx.x,fz.x))+vec2(fx.y,fz.y);
	}
	if(any(notEqual(axes,vec4(1,0,0,1)))) {
		vec2 xx=axisPosition(axes.xy,world,subcell), zz=axisPosition(axes.zw,world,subcell);
		world=floor(vec2(xx.x,zz.x)); subcell=fract(vec2(xx.x,zz.x))+vec2(xx.y,zz.y);
	}
	#endif
	vec2 displacement=subcell, p=world+subcell;
	vec2 wx=vec2(0), wz=vec2(0);
	float f=uField.y;
	if(uField.z!=0.) {
		vec3 x=sampleNoise(world,subcell,uField.w,uFieldResidual.y,ivec2(271,-198),vec2(.13,.59));
		vec3 z=sampleNoise(world,subcell,uField.w,uFieldResidual.y,ivec2(-420,113),vec2(.73,.71));
		displacement+=uField.z*vec2(x.x,z.x); p=world+displacement;
		wx=x.yz*uField.z*uField.w; wz=z.yz*uField.z*uField.w;
	}
	vec3 a=sampleNoise(world,displacement,f,uFieldResidual.x,ivec2(13,-10),vec2(.17,.69));
	vec3 b=sampleNoise(world,displacement,f*2.,2.*uFieldResidual.x,ivec2(71,53),vec2(.43,.11));
	bool beds=uRock.w>0. && uRock.y>0. && uRock.z>0.;
	float contourDistance=abs(a.x)-profile.z;
	// The narrow, unbedded wall can magnify an otherwise imperceptible noise error.
	// Use the compensated contour only where that wall can affect the height.
	if(uField.x>0. && profile.y>0. && !beds && contourDistance>-.00001 && contourDistance<profile.w+.00001)
		a.x=preciseContour(world,displacement);
	vec3 h=vec3(uShape.x,0,0)+uShape.y*a*vec3(1.,f,f)+uRock.x*b*vec3(1.,2.*f,2.*f);
	if(uShape.z!=0.) {
		#ifdef COAST_PROFILE
		vec2 coastAxis=FORM_VALUE(uDetailCoastSet).zw;
		float coast=tanh(dot(p,coastAxis));
		h+=uShape.z*vec3(coast,(1.-coast*coast)*coastAxis);
		#else
		float coast=tanh(p.x/4500.);
		h+=uShape.z*vec3(coast,(1.-coast*coast)/4500.,0.);
		#endif
	}
	if(uShape.w!=0.) {
		float rounded=length(vec2(a.x,profile.x));
		h-=uShape.w*vec3(rounded,a.x/rounded*a.yz*f);
	}
	float strength=uRock.z;
	vec2 ds=vec2(0);
	if(profile.y>0. || beds) {
		float dist=abs(a.x)-profile.z, width=profile.w;
		vec2 slope=sign(a.x)*a.yz*f;
		if(beds) {
			float amount=strength*uRock.w;
			vec3 e=sampleNoise(world,displacement,uErosion.x,uErosion.y,ivec2(43,-82),vec2(.7,.7));
			dist+=amount*(.025-.029*e.x);
			width+=.105*amount;
			slope-=.029*amount*e.yz*uErosion.x;
			float channel=clamp((e.x+.13)/.62,0.,1.);
			strength=amount*(1.-.8*fade(channel));
			ds=-amount*.8*derivative(channel)*uErosion.x/.62*e.yz;
			ds+=ds.x*wx+ds.y*wz;
		}
		float t=clamp(dist/width,0.,1.);
		h+=profile.y*vec3(fade(t),derivative(t)/width*slope);
	}
	h.yz+=h.y*wx+h.z*wz;
	h*=uField.x;
	if(uRock.y>0. && strength>0.) {
		float start=0.,end=1.,width=1.;
		vec2 dip=beds?vec2(.006,-.003):vec2(0);
		float level=(h.x-dot(dip,world)-dot(dip,subcell))/uRock.y, frac=fract(level);
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
	#ifdef FORM_AXES
	vec2 worldSlope=h.y*axes.xy+h.z*axes.zw;
	h.yz=vec2(dot(dc.xy,worldSlope),dot(vec2(-dc.y,dc.x),worldSlope));
	world=detailWorld; subcell=detailSubcell;
	#endif
	#ifdef GULLY_CUT
	h.x-=gullyCut(world+subcell,h.yz,FORM_VALUE(uGullySet));
	#endif
	float slope=dot(h.yz,h.yz)/(uSlopeScale*uSlopeScale), amp=uDetail.x;
	f*=8.;
	for(int i=0;i<12;i++) {
		if(i>=uOctaves || amp==0.) break;
		vec2 offset=vec2(91.73,-23.49)+float(i)*vec2(19.17,-31.29);
		float k=float(i+1);
		float v=sampleNoise(world,subcell,f,0.,ivec2(floor(offset)),fract(offset)).x;
		#ifdef DETAIL_SHAPE
		float shape=FORM_VALUE(uDetailShapeSet);
		v=(1.-abs(shape))*v+shape*(.185-sqrt(v*v+.0025));
		#endif
		h.x+=amp/(1.+uDetail.w*k*k*slope)*v;
		f*=uDetail.z; amp*=uDetail.y;
	}
	return h.x;
}
#ifdef JOINED_TERRAIN
uniform bool uBlendProfile;
vec3 contourProfile(int i)
{
	vec4 p=uProfileSet[i];
	return vec3(p.x,p.z,p.y>0. ? p.w : .5);
}
float extendEdge(float axis,float side)
{
	float edge=.5*uJoinWidth, distance=edge-side*axis;
	return distance<=0. ? axis : side*(edge-256.*distance/(256.+distance));
}
float joinedHeight(vec2 world)
{
	float edge=.5*uJoinWidth;
	vec2 t=clamp(world/uJoinWidth+.5,0.,1.);
	float x=fade(t.x), z=uFormCount==2 ? 0. : fade(t.y);
	vec3 weights=vec3(1.-x,x*(1.-z),x*z);
	bool mixed=uBlendProfile && world.x>-edge
		&& (world.x<edge || (uFormCount==3 && abs(world.y)<edge));
	vec3 shape=vec3(0.);
	if(mixed) {
		shape=weights.x*contourProfile(0)+weights.y*contourProfile(1);
		if(uFormCount==3) shape+=weights.z*contourProfile(2);
	}
	// Keep one copy of the noise evaluator in the shader. A pure landscape runs it once.
	vec3 heights=vec3(0.);
	for(int i=0;i<uFormCount;i++) {
		if(weights[i]<=0.) continue;
		formIndex=i;
		vec3 origin=uFormOrigin[i];
		vec4 profile=uProfile;
		vec2 at=world, fraction=vec2(0.);
		if(mixed) {
			profile=vec4(shape.x,profile.y,shape.yz);
			at.x=extendEdge(at.x,i==0 ? -1. : 1.);
			if(i>0 && uFormCount==3) at.y=extendEdge(at.y,i==1 ? -1. : 1.);
			// Keep sub-meter offsets separate when rebasing onto a large map.
			fraction=fract(at);
			at=floor(at);
		}
		heights[i]=terrainHeight(at-origin.xy,profile,fraction)+origin.z;
	}
	return mix(heights.x,mix(heights.y,heights.z,z),x);
}
#endif
vec4 roadRecord(int address)
{
	int width = textureSize(uRoadAtlas, 0).x;
	return texelFetch(uRoadAtlas, ivec2(address % width, address / width), 0);
}

int roadPointer(int table, int entry)
{
	return int(roadRecord(table + entry / 4)[entry % 4]);
}

// Evaluate the same compact planes as Edits::roadAt, in the same order at intersections.
void gradeRoads(vec2 world, inout float height, inout float material, inout vec3 marking)
{
	ivec2 tile = ivec2(floor(world / float(uRoadGrid.w))) - uRoadGrid.z;
	ivec2 block = tile / 8;
	int leaves = roadPointer(uRoadGrid.x, block.y * uRoadGrid.y + block.x);
	if(leaves == 0) return;
	int group = roadPointer(leaves, (tile.y % 8) * 8 + tile.x % 8);
	bool painted = false;
	int network=0, bestRole=0;
	float bestDistance=0.;
	while(group != 0) {
		ivec4 header = ivec4(roadRecord(group));
		vec4 params = roadRecord(header.x);
		float sum = 0., target = 0., coverage = 0., outside = 1.;
		float halfWidth = sqrt(params.x), radius = sqrt(params.x+params.y);
		float markReach = max(radius,halfWidth+min(4.,.75*halfWidth));
		float searchRadius = params.w == 5. ? max(radius,halfWidth+min(4.,.75*halfWidth)+min(4.,halfWidth*.5)) : radius;
		float closest = 1e30;
		int nearest = 0;
		vec3 mark = vec3(0.);
		group++;
		for(int i = 0; i < header.y; i += 4) {
			ivec4 samples = ivec4(roadRecord(group++));
			for(int j = 0; j < 4 && i+j < header.y; j++) {
				int address = header.x+samples[j];
				vec4 position = roadRecord(address);
				// World positions here are exact; subtracting the sample's high part first keeps the difference exact.
				vec4 segment = roadRecord(address + 2);
				vec2 delta = (world - position.xy) - segment.xy;
				float q = (dot(delta,delta) - params.x) / params.y;
				if(dot(delta,delta) >= searchRadius*searchRadius) continue;
				float t = clamp(dot(delta,segment.zw)/max(1e-12,dot(segment.zw,segment.zw)),0.,1.);
				vec2 toLine = delta - segment.zw*t;
				float d2 = dot(toLine,toLine);
				if(d2 < closest) {
					closest = d2; nearest = address;
					float side = segment.z*toLine.y-segment.w*toLine.x;
					mark = vec3((side < 0. ? -1. : 1.)*sqrt(d2),t,dot(toLine,segment.zw));
				}
				if(q >= 1.) continue;
				float weight = fade(1. - max(q,0.));
				vec4 plane = roadRecord(address + 1);
				vec2 tangent = vec2(position.w,plane.x);
				float crossSlope = dot(delta, vec2(-tangent.y,tangent.x));
				float crown = max(0., 1. - crossSlope*crossSlope / params.x);
				target += weight * (position.z + plane.y*dot(tangent,delta)
					+ plane.z*crossSlope + params.z*crown*crown*crown);
				sum += weight;
				coverage += weight * plane.w;
				outside *= 1. - weight;
			}
		}
		float influence = sum > 0. ? (1. - outside)*(coverage/sum) : 0.;
		if(sum > 0.) height = mix(height,target/sum,influence);
		bool covers = influence >= .5;
		bool joins = covers==painted && network!=0 && network==header.w;
		bool projected = false;
		if(covers) {
			material = params.w;
			if(!joins) marking = vec3(0.);
			painted = true;
		}
		if(params.w == 5. && (influence >= .5 || !painted) && closest <= markReach*markReach) {
			vec4 station = roadRecord(nearest+3);
			int caps = int(station.z);
			int end = (caps&1)!=0 && mark.y==0. ? 0 :
				((caps&4)!=0 || ((caps&2)!=0 && mark.y==1.) ? 1 : -1);
			bool pastEnd = (caps&4)!=0 || (end==0 && mark.z<0.) || (end==1 && mark.z>0.);
			float along = station.x+mark.y*station.y;
			bool forkCap=end>=0 && (caps&(8<<end))!=0;
			if(forkCap) pastEnd=false;
			if((caps&4)!=0 && (forkCap || station.w!=0.)) {
				vec4 pos=roadRecord(nearest);
				vec2 v=(world-pos.xy)-roadRecord(nearest+2).xy;
				if(pos.w*v.y-roadRecord(nearest+1).x*v.x<0.) mark.x=-mark.x;
			}
			if(end>=0 && station.w!=0.) {
				int continuation = int(roadRecord(header.x+int(station.w))[end]);
				if(continuation!=0) {
					pastEnd = false;
					int ptr = header.x+continuation;
					int count = int(roadRecord(ptr++).x);
					for(int k=0; k<count; k++) {
						vec4 segment = roadRecord(ptr++);
						vec4 phase = roadRecord(ptr++);
						int cap = int(roadRecord(ptr++).x);
						vec2 v = (world-segment.xy)-phase.xy;
						float f = dot(v,segment.zw)/max(1e-12,dot(segment.zw,segment.zw));
						float t = clamp(f,0.,1.);
						v -= t*segment.zw;
						float d2 = dot(v,v);
						if(d2 >= closest) continue;
						closest = d2;
						mark.x = (segment.z*v.y-segment.w*v.x<0. ? -1. : 1.)*sqrt(d2);
						along = phase.z+t*phase.w;
						pastEnd = (cap==1 && f<0.) || (cap==2 && f>1.);
					}
				}
			}
			if(!pastEnd) {
				float lateral=mark.x/halfWidth;
				int role=header.z>>1;
				float distanceToArm=abs(lateral);
				if(!joins || distanceToArm<bestDistance || (distanceToArm==bestDistance && role<=bestRole)) {
					bestDistance=distanceToArm; bestRole=role;
					if((role&1)!=0) lateral=-lateral;
					int crossCode=int(clamp(floor(lateral*1024.+.5)+2048.,1.,4095.));
					int phase=int(floor(mod(along,97.536)*(1024./97.536)+.5))&1023;
					int code=(crossCode<<12)|((role>>1)<<10)|phase;
					marking=vec3(code>>16,(code>>8)&255,code&255)/255.;
				}
				network=header.w;
				projected=true;
			}
		}
		if(covers && !projected) { marking=vec3(0.); network=0; }
		if((header.z&1)!=0) break;
	}
}

void main()
{
	vec2 world=uWorldOffset+vec2(uOriginCell+ivec2(gl_FragCoord.xy)-uPixelOrigin)*uStep;
	float outside=max(abs(world.x),abs(world.y))-uRegion.x;
	#ifdef JOINED_TERRAIN
	float h=joinedHeight(world);
#else
	float h=terrainHeight(world,uProfile,vec2(0));
	if(outside>0. && uRegion.y>0. && !(uRegion.y==3. && uNaturalMountains)) {
		vec2 field=world+uNoiseOffset.xy;
		float broad=sampleNoise(field,uNoiseOffset.zw,.0005,0.,ivec2(31,7),vec2(0)).x;
		float t=smoothstep(0.,uRegion.z*(1.+.35*broad),outside);
		float target=uField.x*(.45+.09*broad);
		if(uRegion.y==1.) target=uRegion.w-100.-abs(broad)*45.;
		if(uRegion.y==3.) {
			float ridges=sampleNoise(field,uNoiseOffset.zw,.00031,0.,ivec2(-38,119),vec2(.17,.26)).x;
			target=uField.x*(.42-length(vec2(ridges,.04))+.24*broad);
		}
		h=mix(h,target,t);
	}
	#endif
	float material = 0.;
	vec3 marking = vec3(0.);
	if(uRoadGrid.x != 0 && outside <= 0.) gradeRoads(world,h,material,marking);
	ink=vec4(material/255.,marking);
	uint bits=floatBitsToUint(h);
	color=uPacked?vec4(uvec4(bits,bits>>8,bits>>16,bits>>24)&255u)/255.:vec4(h,0,0,1);
}
