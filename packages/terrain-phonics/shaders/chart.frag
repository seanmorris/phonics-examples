#version 300 es
precision highp float;
precision highp int;
// LAYERS
uniform highp sampler2D uOverview,uMapInk,uFine,uFineInk;
uniform bool uPacked,uWide;
uniform vec4 uChart; // centre, span, viewport side
uniform vec4 uLocal; // world lower left, step, interior span
uniform vec2 uHeading,uViewportOrigin;
uniform float uExtent,uWaterLevel;
uniform vec3 uWaterColor;
uniform vec3 uPaintColor[5];
out vec4 color;
float elevation(sampler2D tex,ivec2 c) {
	vec4 t=texelFetch(tex,c,0);
	if(!uPacked) return t.r;
	uvec4 b=uvec4(t*255.+.5);
	return uintBitsToFloat(b.x|(b.y<<8)|(b.z<<16)|(b.w<<24));
}
vec4 chartSample(vec2 pos,out int ink) {
	vec2 q=(pos/uExtent+.5)*256.;
	ivec2 a=ivec2(floor(q)),b=a+1;
	vec2 f=fract(q);
	float stepSize=uExtent/256.;
	float h00,h10,h01,h11;
	if(!uWide && all(greaterThanEqual(pos,uLocal.xy)) && all(lessThan(pos,uLocal.xy+(uLocal.w-1.)*uLocal.z))) {
		q=pos/uLocal.z; a=ivec2(floor(q)); b=a+1; f=fract(q);
		int mask=textureSize(uFine,0).x-1;
		ink=int(texelFetch(uFineInk,ivec2(floor(q+.5))&mask,0).r*255.+.5);
		a=a&mask; b=b&mask;
		h00=elevation(uFine,a); h10=elevation(uFine,ivec2(b.x,a.y)); h01=elevation(uFine,ivec2(a.x,b.y)); h11=elevation(uFine,b);
		stepSize=uLocal.z;
	} else {
		a=clamp(a,0,256); b=clamp(b,0,256);
		ink=int(texelFetch(uMapInk,clamp(ivec2(floor(q+.5)),0,256),0).r*255.+.5);
		h00=elevation(uOverview,a); h10=elevation(uOverview,ivec2(b.x,a.y)); h01=elevation(uOverview,ivec2(a.x,b.y)); h11=elevation(uOverview,b);
	}
	float h=mix(mix(h00,h10,f.x),mix(h01,h11,f.x),f.y);
	return vec4(normalize(vec3(mix(h00-h10,h01-h11,f.y),stepSize,mix(h00-h01,h10-h11,f.x))),h);
}
void main() {
	vec2 uv=((gl_FragCoord.xy-uViewportOrigin)/uChart.w-.5)*vec2(1.,-1.);
	vec2 pos=uChart.xy+mat2(uHeading.x,uHeading.y,-uHeading.y,uHeading.x)*uv*uChart.z;
	if(any(greaterThan(abs(pos),vec2(uExtent*.5)))) { color=vec4(.07,.13,.15,1); return; }
	int ink;
	vec4 h=chartSample(pos,ink);
	vec3 albedo=layerSurface(vec3(pos.x,h.w,pos.y),h.xyz,uChart.z/uChart.w,uWaterLevel);
	if(ink>0 && ink<=5) albedo=uPaintColor[ink-1];
	float shade=clamp(.96-.44*(h.x+h.z),.55,1.42);
	if(h.w<uWaterLevel) { albedo=uWaterColor; shade=mix(1.25,.72,smoothstep(0.,80.,uWaterLevel-h.w)); }
	color=vec4(pow(max(vec3(0),shade*albedo),vec3(1./2.2)),1.);
}
