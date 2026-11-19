// Trilinear sampling of a 32-azimuth x 16-latitude x 16-distance single-scattering cache.
// Atlas X packs complete longitude rows for successive distances. Latitude concentrates near the horizon.
uniform sampler2D uAerialCacheTransmittance;
uniform sampler2D uAerialCacheRadiance;
uniform vec3 uAerialCacheGrid;
uniform float uAerialCacheMaxDistance;
uniform float uAerialCacheAltitude;
uniform int uAerialCacheReady;

vec3 terrainAerialTexel(sampler2D atlas, ivec2 pixel, int slice, bool radiance)
{
	pixel.x = (pixel.x + int(uAerialCacheGrid.x)) % int(uAerialCacheGrid.x);
	pixel.y = clamp(pixel.y, 0, int(uAerialCacheGrid.y) - 1);
	pixel.x += slice * int(uAerialCacheGrid.x);
	vec3 value = texelFetch(atlas, pixel, 0).rgb;
	return radiance ? (exp2(value * 16.0) - 1.0) / 16.0 : value;
}

vec3 terrainAerialSlice(sampler2D atlas, vec2 pixel, int slice, bool radiance)
{
	ivec2 low = ivec2(floor(pixel));
	vec2 weight = fract(pixel);
	vec3 a = mix(terrainAerialTexel(atlas, low, slice, radiance),
		terrainAerialTexel(atlas, low + ivec2(1, 0), slice, radiance), weight.x);
	vec3 b = mix(terrainAerialTexel(atlas, low + ivec2(0, 1), slice, radiance),
		terrainAerialTexel(atlas, low + ivec2(1, 1), slice, radiance), weight.x);
	return mix(a, b, weight.y);
}

vec3 terrainAtmosphere(vec3 ray, float eyeAltitude, float distanceMeters, vec3 sceneLinear)
{
	if(distanceMeters <= 0.0) return max(vec3(0.0), sceneLinear);
	if(uAerialCacheReady == 0 || distanceMeters > uAerialCacheMaxDistance
		|| abs(clamp(eyeAltitude, 0.0, uAtmosphereHeight) - uAerialCacheAltitude) > 0.251)
		return terrainUncachedAtmosphere(ray, eyeAltitude, distanceMeters, sceneLinear);
	ray = normalize(ray);
	float latitude = asin(clamp(ray.y, -1.0, 1.0)) / (TERRAIN_PI * 0.5);
	vec2 uv = vec2(atan(ray.z, ray.x) / (2.0 * TERRAIN_PI) + 0.5,
		0.5 + 0.5 * sign(latitude) * pow(abs(latitude), 1.0 / 3.0));
	vec2 pixel = uv * uAerialCacheGrid.xy - 0.5;
	float logRange = log(1.0 + uAerialCacheMaxDistance / 100.0);
	float sliceCoordinate = log(1.0 + distanceMeters / 100.0) / logRange * (uAerialCacheGrid.z - 1.0);
	int low = int(clamp(floor(sliceCoordinate), 0.0, uAerialCacheGrid.z - 1.0));
	int high = min(low + 1, int(uAerialCacheGrid.z) - 1);
	float lowDistance = 100.0 * (exp(float(low) / (uAerialCacheGrid.z - 1.0) * logRange) - 1.0);
	float highDistance = 100.0 * (exp(float(high) / (uAerialCacheGrid.z - 1.0) * logRange) - 1.0);
	float weight = clamp((distanceMeters - lowDistance) / max(1e-5, highDistance - lowDistance), 0.0, 1.0);
	vec3 transmittance = mix(terrainAerialSlice(uAerialCacheTransmittance, pixel, low, false),
		terrainAerialSlice(uAerialCacheTransmittance, pixel, high, false), weight);
	vec3 radiance = mix(terrainAerialSlice(uAerialCacheRadiance, pixel, low, true),
		terrainAerialSlice(uAerialCacheRadiance, pixel, high, true), weight);
	return mix(terrainNightSky(normalize(ray).y), max(vec3(0.0), sceneLinear), transmittance) + radiance;
}
