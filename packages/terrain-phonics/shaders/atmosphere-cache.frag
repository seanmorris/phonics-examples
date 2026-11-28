#version 300 es
precision highp float;
precision highp int;
precision highp int;
// ATMOSPHERE
uniform vec2 uSkyCacheSize;
uniform float uSkyCacheAltitude;
out vec4 outColor;
void main()
{
	vec2 uv = gl_FragCoord.xy / uSkyCacheSize;
	float longitude = (uv.x - 0.5) * 2.0 * TERRAIN_PI;
	// Quadratic latitude spends more rows on the narrow horizon scattering band.
	float latitudeCoordinate = uv.y * 2.0 - 1.0;
	float latitude = sign(latitudeCoordinate) * latitudeCoordinate * latitudeCoordinate * TERRAIN_PI * 0.5;
	vec3 ray = vec3(cos(latitude) * cos(longitude), sin(latitude), cos(latitude) * sin(longitude));
	vec3 transmittance;
	vec3 radiance;
	// Integrate scattering only: a tiny bright solar disk cannot be resolved faithfully in this map.
	terrainIntegrateAtmosphere(ray, uSkyCacheAltitude, 1e20, false, transmittance, radiance);
	// Share an exponent among the RGB channels so twilight uses the same relative precision as daylight.
	radiance = max(radiance, vec3(0.0));
	float peak = max(radiance.r, max(radiance.g, radiance.b));
	float exponent = ceil(log2(max(peak, exp2(-32.0))));
	// A square-root mantissa also preserves the faint blue in a red sunset.
	outColor = vec4(sqrt(radiance * exp2(-exponent)), (exponent + 64.0) / 255.0);
}
