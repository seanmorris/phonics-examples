#version 300 es
precision highp float;
layout(location=0) in vec2 q;
layout(location=1) in vec3 foot;
layout(location=2) in vec3 heading; // horizontal tangent and signed artwork number
uniform mat4 vp;
uniform vec3 eye;
uniform vec3 sun;
uniform float day;
uniform sampler2D artwork;
out vec2 uv;
out vec3 relative;
flat out vec3 sheetNormal;
out float elevation;
flat out float brightness;
flat out int panel;
void main() {
	vec2 tangent = heading.xy, right = vec2(-tangent.y,tangent.x);
	int kind = int(abs(heading.z)+.5)-1;
	bool back = kind>0 && dot(eye.xz-foot.xz,tangent)>0.;
	panel = gl_VertexID < 6 ? (back ? 2 : 1) : 0;
	float bottom = kind==0 ? 1.35 : 1.60;
	vec2 size = kind==0 ? vec2(3.84,4.80) : vec2(2.65);
	vec2 offset = (gl_VertexID < 12 ? right : tangent) * (q.x-.5) * (panel != 0 ? size.x : .13);
	float h = panel != 0 ? bottom+size.y*q.y : mix(-.3,bottom,q.y);
	vec3 world = foot + vec3(offset.x,h,offset.y);
	relative = world-eye;
	gl_Position = vp*vec4(relative,1.);
	elevation = world.y;
	vec2 atlas = vec2(textureSize(artwork,0));
	vec2 cell = vec2(kind%4,kind/4)*atlas/vec2(4.,2.);
	uv = (cell+4.5+vec2(heading.z>0. ? q.x : 1.-q.x,1.-q.y)*vec2(255.,kind==0 ? 319. : 255.))/atlas;
	vec3 center = foot + vec3(0.,bottom+.5*size.y,0.);
	vec3 view = normalize(eye-center);
	sheetNormal = vec3(tangent.x,0.,tangent.y);
	if(dot(sheetNormal,view) < 0.) sheetNormal = -sheetNormal;
	brightness = mix(.075, .76+.24*max(0.,dot(sheetNormal,sun)), day);
}
