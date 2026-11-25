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
in float elevation;
flat in float brightness;
flat in int panel;
out vec4 color;
void main() {
	if(elevation < waterLevel) discard;
	if(panel == 0) {
		color = vec4(vec3(.43,.46,.47)*brightness,1.);
		return;
	}
	vec4 ink = texture(artwork,uv);
	if(ink.a < .5) discard;
	if(panel == 2) { color = vec4(vec3(.43,.46,.47)*brightness,1.); return; }
	vec3 paint = ink.rgb/max(ink.a,.01);
	float retro = retroreflection(eye+relative,eye,sheetNormal,sun,day);
	// Only the yellow sheeting returns the beam. The black chevron retains its silhouette at full brightness.
	color = vec4(mix(paint*brightness,vec3(1.,.965,.49)*paint.r,retro),1.);
}
