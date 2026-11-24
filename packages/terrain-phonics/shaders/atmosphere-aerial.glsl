// Trilinear sampling of a 32-azimuth x 16-latitude x 16-distance single-scattering cache.
// Atlas X packs complete longitude rows for successive distances. Latitude concentrates near the horizon.
uniform sampler2D uAerialCacheTransmittance;
uniform sampler2D uAerialCacheRadiance;
uniform vec3 uAerialCacheGrid;
uniform float uAerialCacheMaxDistance;
uniform float uAerialCacheAltitude;
uniform int uAerialCacheReady;

#define TERRAIN_SOLAR_TABLE
vec3 terrainCachedSunlight(float altitude)
{
	ivec2 size=textureSize(uAerialCacheTransmittance,0);
	float cell=altitude-floor(uAerialCacheAltitude)+floor(.5*float(size.x));
	if(uAerialCacheReady!=0 && size.y>int(uAerialCacheGrid.y) && cell>=0. && cell<float(size.x-1)) {
		vec2 uv=(vec2(cell,uAerialCacheGrid.y)+.5)/vec2(size);
		vec4 high=textureLod(uAerialCacheTransmittance,uv,0.);
		if(high.a>=1.) {
			vec3 low=textureLod(uAerialCacheRadiance,uv,0.).rgb;
			return uSunIntensity*(256.*high.rgb+low)/257.;
		}
	}
	return terrainSunlight(altitude);
}

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
	// Extinction is linear in this atlas. Filter its angles in hardware and wrap longitude inside
	// the selected distance slice. Radiance is logarithmic and must be decoded at each corner.
	if(!radiance) {
		float width=uAerialCacheGrid.x, x=mod(pixel.x,width);
		vec2 origin=vec2(float(slice)*width,clamp(pixel.y,0.,uAerialCacheGrid.y-1.))+.5;
		vec2 extent=vec2(textureSize(atlas,0));
		vec3 left=textureLod(atlas,(origin+vec2(min(x,width-1.),0.))/extent,0.).rgb;
		if(x>width-1.) return mix(left,textureLod(atlas,origin/extent,0.).rgb,x-(width-1.));
		return left;
	}
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
		|| abs(terrainSamplingAltitude(eyeAltitude) - uAerialCacheAltitude) > 0.251)
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
	// At distance zero the integrated ray transmits all light and scatters none.
	vec3 transmittance = mix(low == 0 ? vec3(1.) : terrainAerialSlice(uAerialCacheTransmittance, pixel, low, false),
		terrainAerialSlice(uAerialCacheTransmittance, pixel, high, false), weight);
	vec3 radiance = mix(low == 0 ? vec3(0.) : terrainAerialSlice(uAerialCacheRadiance, pixel, low, true),
		terrainAerialSlice(uAerialCacheRadiance, pixel, high, true), weight);
	return mix(terrainNightSky(normalize(ray).y), max(vec3(0.0), sceneLinear), transmittance) + radiance;
}
