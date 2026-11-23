#version 300 es
precision highp float;
layout(location=0) in vec2 q;
layout(location=1) in vec3 foot;
layout(location=2) in vec3 heading; // horizontal tangent and turn handedness
uniform mat4 vp;
uniform vec3 eye;
uniform vec3 sun;
uniform float day;
// RETROREFLECTION
out vec2 uv;
out float elevation;
flat out float brightness;
flat out float retro;
flat out int panel;
void main() {
	vec2 tangent = heading.xy, right = vec2(-tangent.y,tangent.x);
	panel = gl_VertexID < 6 ? 1 : 0;
	// The face preserves the source art's proportions above a 1.35 m post.
	vec2 offset = (gl_VertexID < 12 ? right : tangent) * (q.x-.5) * (panel == 1 ? 3.84 : .13);
	float h = panel == 1 ? 1.35 + 4.80*q.y : mix(-.3,1.35,q.y);
	vec3 world = foot + vec3(offset.x,h,offset.y);
	gl_Position = vp*vec4(world-eye,1.);
	elevation = world.y;
	uv = vec2(heading.z > 0. ? q.x : 1.-q.x, 1.-q.y);
	vec3 center = foot + vec3(0.,3.75,0.);
	vec3 view = normalize(eye-center);
	vec3 normal = vec3(tangent.x,0.,tangent.y);
	if(dot(normal,view) < 0.) normal = -normal;
	brightness = mix(.075, .76+.24*max(0.,dot(normal,sun)), day);
	retro = retroreflection(center,eye,normal,sun,day);
}
