// Spot lights from Scene::setSpotLights(), such as a vehicle's headlights. Each takes three vec4s: world position in
// metres and the inverse square of the range; unit axis and cone scale; irradiance on the axis at one metre, in scene
// units, and cone offset. SpotLights::uniforms() documents the layout.
#define TERRAIN_MAX_SPOT_LIGHTS 2
uniform int uSpotLightCount;
uniform vec4 uSpotLights[TERRAIN_MAX_SPOT_LIGHTS * 3];

// Irradiance in scene units on a surface at a world position with a unit normal. Light falls off with the square of
// distance and the cosine of incidence, and fades out at the range and across the cone's edge. The part of its path
// below the water level loses the extinction per metre. Nothing casts a shadow.
vec3 terrainSpotLight(vec3 position, vec3 normal, float waterLevel, float extinction)
{
	vec3 irradiance = vec3(0.0);
	int count = min(uSpotLightCount, TERRAIN_MAX_SPOT_LIGHTS);
	for(int i = 0; i < count; ++i)
	{
		vec4 place = uSpotLights[3 * i], axis = uSpotLights[3 * i + 1], light = uSpotLights[3 * i + 2];
		vec3 toLight = place.xyz - position;
		// A surface within 10 cm of the lamp receives what it would at 10 cm.
		float squared = max(dot(toLight, toLight), 0.01);
		float inverse = inversesqrt(squared);
		vec3 direction = toLight * inverse;
		float range = clamp(1.0 - squared * squared * place.w * place.w, 0.0, 1.0);
		float cone = clamp(dot(-direction, axis.xyz) * axis.w + light.w, 0.0, 1.0);
		float wet = clamp((waterLevel - min(position.y, place.y)) / max(abs(place.y - position.y), 0.0001), 0.0, 1.0);
		irradiance += light.rgb * (range * range * cone * cone * max(dot(normal, direction), 0.0)
			* exp(-extinction * wet * squared * inverse) / squared);
	}
	return irradiance;
}

// Lamp light that the water between the eye and a submerged point scatters toward the eye, in the water's units of
// illumination, where full sunlight is 1. It uses the irradiance on level water where the sightline enters it.
vec3 terrainSpotWaterColumn(vec3 eye, vec3 position, float waterLevel)
{
	if(uSpotLightCount == 0 || min(eye.y, position.y) >= waterLevel) return vec3(0.0);
	float entry = eye.y > waterLevel ? (eye.y - waterLevel) / (eye.y - position.y) : 0.0;
	return terrainSpotLight(mix(eye, position, entry), vec3(0.0, 1.0, 0.0), waterLevel, 0.0) / 20.0;
}
