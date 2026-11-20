#define SURFACE_LAYERS
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
	float noise=layerValueNoise(uv);
	if(kind==2) return 1.-smoothstep(.04,.31,abs(noise));
	return smoothstep(-.55,.55,noise);
}

vec3 layerColor(int layer, vec3 pos, vec3 normal, float footprint) {
	vec3 base=uLayerColor[layer];
	vec4 pattern=uLayerPattern[layer]; // kind, amount, horizontal scale, vertical stretch
	int kind=int(pattern.x);
	if(kind==0 || pattern.y<=0.) return base;

	// Narrow streaks and gravel disappear earlier than large patches of colour.
	float filterStart=kind==1 ? .2 : .06;
	float visibility=1.-smoothstep(filterStart,filterStart*3.,footprint/pattern.z);
	if(visibility<=0.) return base;

	// Project onto the ground and both upright planes, in world space. Blending the
	// projections keeps the tint continuous where cliff faces turn a corner.
	vec3 weight=abs(normal);
	weight*=weight;
	weight/=max(.0001,dot(weight,vec3(1.)));
	vec2 verticalScale=pattern.z*vec2(1.,pattern.w);
	vec2 offset=uPaletteOffset+vec2(73.37,19.81);
	float amount=0.;
	if(weight.x>0.) amount+=weight.x*patternMask((pos.zy+offset)/verticalScale,kind);
	if(weight.y>0.) amount+=weight.y*patternMask((pos.xz+offset)/pattern.z,kind);
	if(weight.z>0.) amount+=weight.z*patternMask((pos.xy+offset.yx)/verticalScale,kind);
	return mix(base,uLayerPatternColor[layer],pattern.y*visibility*amount);
}
vec3 layerSurface(vec3 pos,vec3 normal,float footprint,float water) {
	float masks[12];
	vec3 result=layerColor(0,pos,normal,footprint);
	float slope=1.-clamp(normal.y,0.,1.);
	masks[0]=1.;
	for(int i=1;i<12;i++) {
		if(i>=uLayerCount) break;
		vec4 r=uLayerRange[i],b=uLayerBlend[i],m=uLayerMode[i];
		float noise=b.z>0. ? layerNoise(pos.xz,b.w,footprint) : 0.;
		float h=pos.y+(b.z*noise)-(m.y==1.?water:(m.y==2.?dot(pos.xz,vec2(.006,-.003)):0.));
		if(m.z>0.) h=mod(h+.5*m.z,m.z);
		float fade=max(.0001,b.x), sf=max(.00001,b.y);
		float mask=smoothstep(r.x-fade,r.x,h)*(1.-smoothstep(r.y,r.y+fade,h));
		mask*=smoothstep(r.z-sf,r.z,slope)*(1.-smoothstep(r.w,r.w+sf,slope));
		if(m.z>0.) mask=mix(mask,clamp((r.y-r.x)/m.z,0.,1.),smoothstep(.25,1.,fwidth(pos.y)/m.z));
		if(m.x>=0.) mask*=masks[int(m.x)];
		masks[i]=mask;
		if(mask>0.) result=mix(result,layerColor(i,pos,normal,footprint),mask);
	}
	return result;
}
