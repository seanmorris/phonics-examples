#version 300 es
precision highp float;
precision highp int;
uniform sampler2D artwork;
uniform vec3 eye, sun;
uniform float waterLevel, day;
// RETROREFLECTION
in vec2 uv;
in vec3 relative;
flat in vec3 sheetNormal;
in float elevation, brightness;
flat in float front;
flat in int part;
out vec4 color;
void main() {
	if(elevation<waterLevel) discard;
	vec3 metal=vec3(.43,.47,.48)*brightness;
	if(part!=5 && part<16) { color=vec4(metal,1.); return; }
	vec4 ink=texture(artwork,uv);
	if(ink.a<.45 || (front<0. && part>=16)) discard;
	if(front<0.) { color=vec4(metal,1.); return; }
	vec3 paint=ink.rgb/max(.01,ink.a);
	float retro=retroreflection(eye+relative,eye,sheetNormal,sun,day);
	// Colored sheeting returns a bright tint. Black ink absorbs the beam and white lettering stays distinct.
	float peak=max(paint.r,max(paint.g,paint.b));
	vec3 sheeting=mix(paint,vec3(peak),.10)/(.22+.78*peak);
	color=vec4(mix(paint*brightness,sheeting,retro),1.);
}
