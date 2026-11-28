// Append after atmosphere.glsl with its skyRadiance renamed to terrainUncachedSkyRadiance.
// The maps cache single scattering only. Terrain's finite-distance aerial perspective remains numerical.
uniform sampler2D uSkyCacheEye;
uniform sampler2D uSkyCacheWater;
uniform vec2 uSkyCacheSize;
uniform vec2 uSkyCacheAltitudes;
uniform int uSkyCacheReady;

vec3 terrainSkyCacheTexel(sampler2D skyCache, ivec2 pixel)
{
	pixel.x = (pixel.x + int(uSkyCacheSize.x)) % int(uSkyCacheSize.x);
	pixel.y = clamp(pixel.y, 0, int(uSkyCacheSize.y) - 1);
	vec4 sampleValue = texelFetch(skyCache, pixel, 0);
	return sampleValue.rgb * sampleValue.rgb * exp2(round(sampleValue.a * 255.0) - 64.0);
}

vec3 terrainSkyCacheSample(sampler2D skyCache, vec3 ray)
{
	float latitude = asin(clamp(ray.y, -1.0, 1.0)) / (TERRAIN_PI * 0.5);
	vec2 uv = vec2(atan(ray.z, ray.x) / (2.0 * TERRAIN_PI) + 0.5,
		0.5 + 0.5 * sign(latitude) * sqrt(abs(latitude)));
	vec2 pixel = uv * uSkyCacheSize - 0.5;
	ivec2 low = ivec2(floor(pixel));
	vec2 weight = fract(pixel);
	// Decode each texel before blending: adjacent texels may use different exponents.
	vec3 a = mix(terrainSkyCacheTexel(skyCache, low),
		terrainSkyCacheTexel(skyCache, low + ivec2(1, 0)), weight.x);
	vec3 b = mix(terrainSkyCacheTexel(skyCache, low + ivec2(0, 1)),
		terrainSkyCacheTexel(skyCache, low + ivec2(1, 1)), weight.x);
	return mix(a, b, weight.y);
}

vec3 skyRadiance(vec3 ray, float altitude, float angularFootprint, bool includeSolarDisk)
{
	if(uSkyCacheReady == 0) return terrainUncachedSkyRadiance(ray,altitude,angularFootprint,includeSolarDisk);
	ray = normalize(ray);
	altitude = terrainSamplingAltitude(altitude);
	vec3 radiance;
	if(abs(altitude - uSkyCacheAltitudes.x) <= abs(altitude - uSkyCacheAltitudes.y))
		radiance = terrainSkyCacheSample(uSkyCacheEye, ray);
	else radiance = terrainSkyCacheSample(uSkyCacheWater, ray);
	if(includeSolarDisk)
		radiance += terrainSolarDisk(ray,altitude,terrainTransmittance(altitude,ray.y),angularFootprint);
	return radiance + terrainNightSkyThroughLayers(altitude,ray.y);
}

vec3 skyRadiance(vec3 ray, float altitude, float angularFootprint)
{
	return skyRadiance(ray,altitude,angularFootprint,true);
}

vec3 skyRadiance(vec3 ray, float altitude)
{
	return skyRadiance(ray,altitude,0.0);
}
