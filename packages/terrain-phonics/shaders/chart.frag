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
vec4 inkSample(sampler2D tex,ivec2 at) {
	int index=int(texelFetch(tex,at,0).r*255.+.5);
	if(index<1 || index>5) return vec4(0.);
	return vec4(uPaintColor[index-1],1.);
}
vec4 paintAt(sampler2D tex,ivec2 a,ivec2 b,vec2 f) {
	return mix(mix(inkSample(tex,a),inkSample(tex,ivec2(b.x,a.y)),f.x),
		mix(inkSample(tex,ivec2(a.x,b.y)),inkSample(tex,b),f.x),f.y);
}
vec4 chartSample(vec2 pos,out vec4 ink) {
	vec2 q=(pos/uExtent+.5)*256.;
	ivec2 a=ivec2(floor(q)),b=a+1;
	vec2 f=fract(q);
	float stepSize=uExtent/256.;
	float h00,h10,h01,h11;
	if(all(greaterThanEqual(pos,uLocal.xy)) && all(lessThan(pos,uLocal.xy+(uLocal.w-1.)*uLocal.z))) {
		q=pos/uLocal.z; a=ivec2(floor(q)); b=a+1; f=fract(q);
		int mask=textureSize(uFine,0).x-1;

		a=a&mask; b=b&mask; ink=paintAt(uFineInk,a,b,f);
		h00=elevation(uFine,a); h10=elevation(uFine,ivec2(b.x,a.y)); h01=elevation(uFine,ivec2(a.x,b.y)); h11=elevation(uFine,b);
		stepSize=uLocal.z;
	} else {
		a=clamp(a,0,256); b=clamp(b,0,256);
		ink=paintAt(uMapInk,a,b,f);
		h00=elevation(uOverview,a); h10=elevation(uOverview,ivec2(b.x,a.y)); h01=elevation(uOverview,ivec2(a.x,b.y)); h11=elevation(uOverview,b);
	}
	float h=mix(mix(h00,h10,f.x),mix(h01,h11,f.x),f.y);
	return vec4(normalize(vec3(mix(h00-h10,h01-h11,f.y),stepSize,mix(h00-h01,h10-h11,f.x))),h);
}
void main() {
	vec2 uv=((gl_FragCoord.xy-uViewportOrigin)/uChart.w-.5)*vec2(1.,-1.);
	if(uCircular && dot(uv,uv)>.25) discard;
	vec2 pos=uChart.xy+mat2(uHeading.x,uHeading.y,-uHeading.y,uHeading.x)*uv*uChart.z;
	if(any(greaterThan(abs(pos),vec2(uExtent*.5)))) { color=vec4(.07,.13,.15,1); return; }
	vec4 ink;
	vec4 h=chartSample(pos,ink);
	vec3 n=h.xyz;
	float footprint=uChart.z/uChart.w;
	vec3 albedo=materialSurface(vec3(pos.x,h.w,pos.y),h.xyz,vec3(0.,1.,0.),footprint,uWaterLevel,landVariation(pos,footprint)*uMacroVariation,n);
	albedo=albedo*(1.-ink.a)+ink.rgb;
	float shade=clamp(.96-.44*(h.x+h.z),.55,1.42);
	if(h.w<uWaterLevel) { albedo=uWaterColor; shade=mix(1.25,.72,smoothstep(0.,80.,uWaterLevel-h.w)); }
	color=vec4(pow(max(vec3(0),shade*albedo),vec3(1./2.2)),1.);
}
