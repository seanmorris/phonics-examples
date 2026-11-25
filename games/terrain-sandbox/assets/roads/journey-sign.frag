#version 300 es
precision highp float;
precision highp int;
uniform sampler2D artwork;
uniform float waterLevel;
in vec2 uv;
in float elevation, brightness;
flat in float retro, front;
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
	color=vec4(paint*mix(brightness,1.22,retro),1.);
}
