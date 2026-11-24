#version 300 es
precision highp float;
precision highp int;
// LAYERS
uniform highp sampler2D uOverview,uMapInk,uFine,uFineInk;
uniform bool uPacked,uCircular;
uniform vec4 uChart; // center, span, viewport side
uniform vec4 uLocal; // world lower left, step, interior span
uniform vec2 uHeading,uViewportOrigin;
uniform float uExtent,uWaterLevel;
uniform vec3 uWaterColor;
uniform vec3 uPaintColor[5];
uniform vec4 uSlopeBlend,uHeightBlend;
uniform vec2 uSnowline,uMountainZones;
uniform vec3 uMountainAmounts;
uniform float uMacroVariation;
// MATERIAL NOISE
// MATERIAL STACK
out vec4 color;
float elevation(sampler2D tex,ivec2 c) {
	vec4 t=texelFetch(tex,c,0);
	if(!uPacked) return t.r;
	uvec4 b=uvec4(t*255.+.5);
	return uintBitsToFloat(b.x|(b.y<<8)|(b.z<<16)|(b.w<<24));
}
ivec2 chartInk(sampler2D tex,ivec2 at) {
	ivec4 ink=ivec4(texelFetch(tex,at,0)*255.+.5);
	return ivec2(ink.r,(ink.g<<16)|(ink.b<<8)|ink.a);
}
vec4 inkSample(ivec2 ink,float roadMask,float splineMask) {
	if(ink.x<1 || ink.x>5) return vec4(0.);
	float coverage=ink.x==5 ? roadMask*(ink.y==0 ? 1. : splineMask) : 1.;
	return vec4(uPaintColor[ink.x-1],1.)*coverage;
}
vec4 chartCell(sampler2D heights,sampler2D inks,ivec2 a,ivec2 b,vec2 f,float stepSize,out vec4 ink) {
	ivec2 ab=ivec2(a.x,b.y), ba=ivec2(b.x,a.y);
	float h00=elevation(heights,a), h10=elevation(heights,ba), h01=elevation(heights,ab), h11=elevation(heights,b);
	// The clipmap splits a cell between its (1,0) and (0,1) corners.
	vec2 rise = f.x+f.y <= 1. ? vec2(h10-h00,h01-h00) : vec2(h11-h01,h11-h10);
	float roadMask = roadSlopeMask(vec3(rise.x,stepSize,rise.y));
	ivec2 i00=chartInk(inks,a), i10=chartInk(inks,ba), i01=chartInk(inks,ab), i11=chartInk(inks,b);
	ivec4 codes=ivec4(i00.y,i10.y,i01.y,i11.y);
	vec4 weights=vec4(1.-f.x,f.x,1.-f.x,f.x)*vec4(1.-f.y,1.-f.y,f.y,f.y);
	vec4 marked=weights*vec4(notEqual(codes,ivec4(0)));
	float lateral=dot(marked,vec4((codes>>11)-4096)/2048.)/max(.0001,dot(marked,vec4(1.)));
	float edge=splinePavement(lateral,fwidth(lateral));
	ink=inkSample(i00,roadMask,edge)*weights.x+inkSample(i10,roadMask,edge)*weights.y
		+inkSample(i01,roadMask,edge)*weights.z+inkSample(i11,roadMask,edge)*weights.w;
	float h=mix(mix(h00,h10,f.x),mix(h01,h11,f.x),f.y);
	return vec4(normalize(vec3(mix(h00-h10,h01-h11,f.y),stepSize,mix(h00-h01,h10-h11,f.x))),h);
}
vec4 chartSample(vec2 pos,out vec4 ink) {
	if(all(greaterThanEqual(pos,uLocal.xy)) && all(lessThan(pos,uLocal.xy+(uLocal.w-1.)*uLocal.z))) {
		vec2 q=pos/uLocal.z;
		ivec2 a=ivec2(floor(q));
		int mask=textureSize(uFine,0).x-1;
		return chartCell(uFine,uFineInk,a&mask,(a+1)&mask,fract(q),uLocal.z,ink);
	}
	vec2 q=(pos/uExtent+.5)*256.;
	ivec2 a=ivec2(floor(q));
	return chartCell(uOverview,uMapInk,clamp(a,0,256),clamp(a+1,0,256),fract(q),uExtent/256.,ink);
}
// REGIONS
void main() {
	vec2 uv=((gl_FragCoord.xy-uViewportOrigin)/uChart.w-.5)*vec2(1.,-1.);
	if(uCircular && dot(uv,uv)>.25) discard;
	vec2 pos=uChart.xy+mat2(uHeading.x,uHeading.y,-uHeading.y,uHeading.x)*uv*uChart.z;
	if(any(greaterThan(abs(pos),vec2(uExtent*.5)))) { color=vec4(.07,.13,.15,1); return; }
	float alpha=regionOpacity(pos);
	if(alpha<=0.) discard;
	vec4 ink;
	vec4 h=chartSample(pos,ink);
	vec3 n=h.xyz;
	float footprint=uChart.z/uChart.w;
	vec3 albedo=materialSurface(vec3(pos.x,h.w,pos.y)-uMaterialOrigin,h.xyz,vec3(0.,1.,0.),footprint,uWaterLevel-uMaterialOrigin.y,landVariation(pos-uMaterialOrigin.xz,footprint)*uMacroVariation,n);
	albedo=albedo*(1.-ink.a)+ink.rgb;
	float shade=clamp(.96-.44*(h.x+h.z),.55,1.42);
	if(h.w<uWaterLevel) { albedo=uWaterColor; shade=mix(1.25,.72,smoothstep(0.,80.,uWaterLevel-h.w)); }
	color=vec4(pow(max(vec3(0),shade*albedo),vec3(1./2.2)),alpha);
}
