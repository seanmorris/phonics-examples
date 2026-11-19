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
	// Log RGBA8 stores [0,4095.9375] linear radiance, with finer steps near black. Alpha is unused.
	outColor = vec4(clamp(log2(1.0 + max(vec3(0.0), radiance) * 16.0) / 16.0, 0.0, 1.0), 1.0);
}
