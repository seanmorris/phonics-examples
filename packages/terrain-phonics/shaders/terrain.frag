#version 300 es
precision highp float;
precision highp int;
// ATMOSPHERE
// OPTICS
in vec3 vRelative, vWorld, vNormal;
in vec3 vPaint0, vPaint1;
uniform vec3 uEye;
uniform float uWaterLevel, uWorldSize, uTerrainStyle;
uniform sampler2D uTerrainShadowMap;
uniform float uTerrainShadowExtent;
uniform int uTerrainShadowReady;
out vec4 color;
float mountainShadow(vec3 position)
{
	if(uSunDirection.y <= 0.0) return 0.0;
	if(uTerrainShadowReady == 0) return 1.0;
	vec2 uv = position.xz / uTerrainShadowExtent + 0.5;
	if(any(lessThan(uv, vec2(0.0))) || any(greaterThan(uv, vec2(1.0)))) return 1.0;
	return texture(uTerrainShadowMap, uv).r;
}
float grain(vec2 p)
{
	return sin(p.x * 1.73 + sin(p.y * 2.1)) * sin(p.y * 1.43 + sin(p.x * 1.9));
}
float hashCell(vec2 cell)
{
	vec3 p = fract(vec3(cell.xyx) * 0.1031);
	p += dot(p, p.yzx + 33.33);
	return fract((p.x + p.y) * p.z);
}
float smoothNoise(vec2 p)
{
	vec2 cell = floor(p), f = fract(p);
	vec2 w = f * f * f * (f * (f * 6.0 - 15.0) + 10.0);
	return mix(mix(hashCell(cell), hashCell(cell + vec2(1.0,0.0)), w.x),
		mix(hashCell(cell + vec2(0.0,1.0)), hashCell(cell + vec2(1.0)), w.x), w.y);
}
float landVariation(vec2 position, float footprint)
{
	float broad = (smoothNoise(position / 420.0) - 0.5)
		* (1.0 - smoothstep(95.0,420.0,footprint));
	float middle = (smoothNoise(position / 95.0 + 17.31) - 0.5)
		* (1.0 - smoothstep(20.0,95.0,footprint));
	float small = (smoothNoise(position / 22.0 - 53.73) - 0.5)
		* (1.0 - smoothstep(5.0,22.0,footprint));
	return broad * 1.2 + middle * 0.55 + small * 0.25;
}
void main()
{
	vec3 n = normalize(vNormal);
	vec3 weights = pow(abs(n), vec3(4.0)); weights /= dot(weights, vec3(1.0));
	float detail = dot(weights, vec3(grain(vWorld.yz * 0.3), grain(vWorld.xz * 0.3), grain(vWorld.xy * 0.3)));
	float footprint = max(length(dFdx(vWorld)), length(dFdy(vWorld)));
	float macro = landVariation(vWorld.xz, footprint);
	float slope = 1.0 - max(0.0, n.y);
	vec3 grass = vec3(0.055,0.105,0.026) * (0.85 + 0.15 * macro);
	float strataPhase = vWorld.y * 0.12;
	float strataFilter = exp(-0.125 * pow(fwidth(strataPhase), 2.0));
	vec3 rock = mix(vec3(0.24,0.20,0.16), vec3(0.43,0.30,0.20),
		0.5 + 0.5 * sin(strataPhase) * strataFilter);
	vec3 sand = vec3(0.52,0.43,0.26);
	vec3 snow = vec3(0.82,0.88,0.94);
	vec3 cover = uTerrainStyle > 1.5 ? mix(vec3(0.22,0.115,0.052), rock, 0.4 + 0.2 * macro) : grass;
	vec3 albedo = mix(cover, rock, smoothstep(0.08,0.38,slope));
	albedo = mix(albedo, sand, (1.0 - smoothstep(uWaterLevel + 3.0,uWaterLevel + 18.0,vWorld.y)) * n.y);
	float snowline = 340.0 + 25.0 * macro;
	if(uTerrainStyle > 0.5 && uTerrainStyle < 1.5)
		albedo = mix(albedo, rock, smoothstep(snowline - 170.0,snowline - 30.0,vWorld.y));
	if(uTerrainStyle < 1.5)
		albedo = mix(albedo, snow, smoothstep(snowline,snowline + 65.0,vWorld.y)
			* (1.0 - smoothstep(0.25,0.65,slope)));
	// Blend material weights, never categorical identifiers: a rock/road boundary must not become snow.
	float painted = clamp(dot(vPaint0 + vPaint1, vec3(1.0)), 0.0, 1.0);
	vec3 craft = mix(vec3(0.06,0.12,0.17), vec3(0.95,0.28,0.03), step(0.15,vWorld.y - uWaterLevel));
	albedo = albedo * (1.0 - painted) + rock * vPaint0.x + grass * vPaint0.y + sand * vPaint0.z
		+ snow * vPaint1.x + vec3(0.13,0.12,0.105) * vPaint1.y + craft * vPaint1.z;
	albedo *= 1.0 + 0.07 * detail * (1.0 - smoothstep(1.0,8.0,footprint));
	vec3 direct = terrainSunlight(max(0.0,vWorld.y)) / 3.14159265;
	float shadow = mountainShadow(vWorld);
	// Approximate diffuse sky fill follows solar irradiance and fades through twilight.
	float illumination = uSunIntensity / 20.0 * smoothstep(-0.1,0.05,uSunDirection.y);
	vec3 ambient = vec3(0.08,0.13,0.20) * (0.35 + 0.65 * max(0.0,n.y)) * illumination;
	vec3 lit = albedo * (ambient + direct * max(0.0,dot(n,uSunDirection)) * shadow);
	lit = waterColumnLight(lit, vRelative, uEye.y, uWaterLevel, illumination);
	vec3 aerial = terrainAtmosphere(normalize(vRelative), uEye.y, length(vRelative), lit);
	color = vec4(terrainToneMap(aerial),1.0);
}
