// Spot lights from Scene::setSpotLights(), such as a vehicle's headlights. Each takes three vec4s: world position in
// meters and the inverse square of the range; unit axis and cone scale; irradiance on the axis at one meter, in scene
// units, and cone offset. SpotLights::uniforms() documents the layout.
// RETROREFLECTION
// Summed lamp irradiance, in scene units, above which it grows with its logarithm. SpotLights::KNEE matches it.
#define TERRAIN_SPOT_KNEE 1.0
#ifdef TERRAIN_NO_SPOT_LIGHTS
// SpotLights::shaderSource(false) defines TERRAIN_NO_SPOT_LIGHTS for the programs Scene draws while no lamp is set.
// The compiler folds these zeros away, so those programs cost what they did before lamps existed.
vec3 terrainSpotLight(vec3 position, vec3 normal, float waterLevel, float extinction) { return vec3(0.0); }
vec3 terrainSpotWaterColumn(vec3 eye, vec3 position, float waterLevel) { return vec3(0.0); }
#else
// Irradiance in scene units on a surface at a world position with a unit normal. Light falls off with the square of
// distance and the cosine of incidence, and fades out at the range and across the cone's edge. The part of its path
// below the water level loses the extinction per meter. Nothing casts a shadow.
//
// Ground near a low lamp receives hundreds of times what the ground 50 m ahead does, more than the tone map can show
// without turning the near ground white. A soft knee compresses the sum: with m the largest channel of the light the
// lamps would give with their cones wide open and K the knee, the light through the cones is scaled by
// K ln(1 + m / K) / m. Below K it is nearly linear; ten times K comes out as 2.4 K, a hundred times K as 4.6 K. The
// scale keeps the lamps' color. Because it ignores the cones, a beam fades across its whole band between the inner
// and outer cone. Scaled by the light through the cones instead, a lamp facing a nearby slope would stay near full
// brightness until close to its outer cone and draw a flat disc with a hard rim.
vec3 terrainSpotLight(vec3 position, vec3 normal, float waterLevel, float extinction)
{
	vec3 open = vec3(0.0), irradiance = vec3(0.0);
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
		vec3 arriving = light.rgb * (range * range * max(dot(normal, direction), 0.0)
			* exp(-extinction * wet * squared * inverse) / squared);
		open += arriving;
		irradiance += arriving * (cone * cone);
	}
	float peak = max(open.r, max(open.g, open.b));
	return irradiance * (TERRAIN_SPOT_KNEE * log(1.0 + peak / TERRAIN_SPOT_KNEE) / max(peak, 1e-6));
}

// Lamp light that the water between the eye and a submerged point scatters toward the eye, in the water's units of
// illumination, where full sunlight is 1. It uses the irradiance on level water where the sightline enters it.
vec3 terrainSpotWaterColumn(vec3 eye, vec3 position, float waterLevel)
{
	if(uSpotLightCount == 0 || min(eye.y, position.y) >= waterLevel) return vec3(0.0);
	float entry = eye.y > waterLevel ? (eye.y - waterLevel) / (eye.y - position.y) : 0.0;
	return terrainSpotLight(mix(eye, position, entry), vec3(0.0, 1.0, 0.0), waterLevel, 0.0) / 20.0;
}
#endif
