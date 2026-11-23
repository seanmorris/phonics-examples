#version 300 es
precision highp float;
layout(location=0) in vec2 q;
layout(location=1) in vec3 foot;
layout(location=2) in vec3 heading; // horizontal tangent and turn handedness
uniform mat4 vp;
uniform vec3 eye;
uniform vec3 sun;
uniform float day;
uniform int uSpotLightCount;
uniform vec4 uSpotLights[6];
out vec2 uv;
out float elevation;
flat out float brightness;
flat out float retro;
flat out int panel;
void main() {
	vec2 tangent = heading.xy, right = vec2(-tangent.y,tangent.x);
	panel = gl_VertexID < 6 ? 1 : 0;
	// The 1.92 x 2.40 m face preserves the source art's proportions. Two planes form the steel post.
	vec2 offset = (gl_VertexID < 12 ? right : tangent) * (q.x-.5) * (panel == 1 ? 1.92 : .13);
	float h = panel == 1 ? 1.35 + 2.40*q.y : mix(-.3,1.35,q.y);
	vec3 world = foot + vec3(offset.x,h,offset.y);
	gl_Position = vp*vec4(world-eye,1.);
	elevation = world.y;
	uv = vec2(heading.z > 0. ? q.x : 1.-q.x, 1.-q.y);
	vec3 center = foot + vec3(0.,2.55,0.);
	vec3 view = normalize(eye-center);
	vec3 normal = vec3(tangent.x,0.,tangent.y);
	if(dot(normal,view) < 0.) normal = -normal;
	brightness = mix(.075, .76+.24*max(0.,dot(normal,sun)), day);
	retro = .28*day*pow(max(dot(sun,view),0.),40.)*max(dot(normal,sun),0.);
	for(int i=0; i<uSpotLightCount; i++) {
		vec4 place = uSpotLights[3*i], axis = uSpotLights[3*i+1], light = uSpotLights[3*i+2];
		vec3 delta = place.xyz-center;
		float d2 = max(dot(delta,delta),.01);
		vec3 incident = delta*inversesqrt(d2);
		float cone = clamp(dot(-incident,axis.xyz)*axis.w+light.w,0.,1.);
		// Reflective sheeting returns light toward the lamp and remains legible beyond the lit pavement.
		float range = 1.-smoothstep(.25,1.,d2*place.w/16.);
		float alignment = smoothstep(.91,.996,dot(incident,view));
		retro += 32.*light.r/d2 * cone*cone * range * alignment * max(dot(normal,incident),0.);
	}
	retro = 1.-exp(-retro);
}
