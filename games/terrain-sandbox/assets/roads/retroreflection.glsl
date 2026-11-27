#define TERRAIN_MAX_SPOT_LIGHTS 2
#ifndef TERRAIN_NO_SPOT_LIGHTS
uniform int uSpotLightCount;
uniform vec4 uSpotLights[TERRAIN_MAX_SPOT_LIGHTS * 3];
#endif

// Sheeting returns light toward its source over a longer range than matte pavement.
float retroreflection(vec3 point, vec3 eye, vec3 normal, vec3 sun, float day)
{
	vec3 view = normalize(eye-point);
	if(dot(normal,view)<0.) normal = -normal;
	float returned = .28*day*pow(max(dot(sun,view),0.),40.)*max(dot(normal,sun),0.);
#ifndef TERRAIN_NO_SPOT_LIGHTS
	for(int i=0; i<uSpotLightCount; i++) {
		vec4 place = uSpotLights[3*i], axis = uSpotLights[3*i+1], light = uSpotLights[3*i+2];
		vec3 delta = place.xyz-point;
		float d2 = max(dot(delta,delta),.01);
		vec3 incident = delta*inversesqrt(d2);
		float cone = clamp(dot(-incident,axis.xyz)*axis.w+light.w,0.,1.);
		float range = 1.-smoothstep(.25,1.,d2*place.w/16.);
		float alignment = smoothstep(.91,.996,dot(incident,view));
		returned += 32.*light.r/d2*cone*cone*range*alignment*max(dot(normal,incident),0.);
	}
#endif
	return 1.-exp(-returned);
}
