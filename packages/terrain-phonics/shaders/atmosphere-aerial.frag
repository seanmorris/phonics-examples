#version 300 es
precision highp float;
precision highp int;
// ATMOSPHERE
uniform vec3 uAerialCacheGrid;
uniform float uAerialCacheMaxDistance;
uniform float uAerialCacheAltitude;
uniform int uAerialCacheOutput;
out vec4 outColor;
void main()
{
	float slice = floor(gl_FragCoord.x / uAerialCacheGrid.x);
	vec2 uv = vec2(mod(gl_FragCoord.x, uAerialCacheGrid.x), gl_FragCoord.y) / uAerialCacheGrid.xy;
	float longitude = (uv.x - 0.5) * 2.0 * TERRAIN_PI;
	float latitudeCoordinate = uv.y * 2.0 - 1.0;
	// A cubic latitude warp preserves the very narrow horizon band even with sixteen rows.
	float latitude = latitudeCoordinate * latitudeCoordinate * latitudeCoordinate * TERRAIN_PI * 0.5;
	vec3 ray = vec3(cos(latitude) * cos(longitude), sin(latitude), cos(latitude) * sin(longitude));
	// A 100-metre scale keeps samples useful across terrain viewing distances while preserving distance zero.
	float distanceMeters = 100.0 * (exp(slice / (uAerialCacheGrid.z - 1.0)
		* log(1.0 + uAerialCacheMaxDistance / 100.0)) - 1.0);
	vec3 transmittance;
	vec3 radiance;
	terrainIntegrateAtmosphere(ray, uAerialCacheAltitude, distanceMeters, transmittance, radiance);
	vec3 encoded = uAerialCacheOutput == 0 ? transmittance
		: clamp(log2(1.0 + max(vec3(0.0), radiance) * 16.0) / 16.0, 0.0, 1.0);
	outColor = vec4(encoded, 1.0);
}
