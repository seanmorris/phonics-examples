#version 300 es
precision highp float;
precision highp int;
// ATMOSPHERE
// OPTICS
// SPOT LIGHTS
in vec3 vRelative, vWorld, vNormal;
in vec3 vPaint0, vPaint1;
uniform vec3 uEye;
uniform float uWaterLevel, uWorldSize, uTerrainStyle;
uniform vec4 uBedContacts[2];
uniform vec3 uBedDatum; // X/Z dip and vertical spacing in metres.
uniform sampler2D uTerrainShadowMap;
uniform float uTerrainShadowExtent;
uniform int uTerrainShadowReady;
// TerrainAppearance. Colours are linear. The canyon palette runs rust, shale, ledge, sandstone, red beds, cap, dust,
// then the colour of beds too fine to resolve.
uniform vec3 uGrassColor, uSoilColor, uRockColor, uRockBandColor, uSandColor, uSnowColor, uRoadColor;
uniform vec3 uCanyonColors[8], uCanyonBounce;
uniform vec3 uBanding; // Strata phase per metre, band contrast and band sharpness.
uniform vec4 uSlopeBlend; // Start and end slopes of cover to rock, then of snow fading off steep ground.
uniform vec4 uHeightBlend; // Sand start and end above the water, then alpine rock start and end from the snowline.
uniform vec2 uSnowline; // Mean snowline and the height over which snow becomes complete.
uniform float uMacroVariation;
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
float contact(float height, float base, float width, float footprint)
{
	float edge = max(width, footprint * 0.65);
	return smoothstep(base - edge, base + edge, height);
}

// Project onto the two upright planes so weathering runs down exposed rock faces.
float cliffNoise(vec3 p, vec2 scale, float xWeight)
{
	return mix(smoothNoise(p.xy / scale), smoothNoise(p.zy / scale), xWeight);
}

float fracture(vec2 face, float footprint)
{
	float column = floor(face.x / 30.0);
	float center = mix(0.19, 0.81, hashCell(vec2(column, 79.2)));
	float breaks = smoothNoise(vec2(column, face.y / 35.0));
	float offset = abs(fract(face.x / 30.0) - center - 0.14*(breaks - 0.5)) * 30.0;
	float edge = max(0.15, min(footprint, 3.0));
	return (1.0 - smoothstep(0.12, 0.28 + edge, offset)) * smoothstep(0.30, 0.68, breaks);
}

void canyonMaterial(float footprint, vec3 weights, float slope, float macro,
	out vec3 rock, out vec3 debris, inout vec3 normal)
{
	float cliff = smoothstep(0.08, 0.45, slope);
	float xWeight = weights.x / max(weights.x + weights.z, 0.001);
	float grooves = cliffNoise(vWorld, vec2(16.0, 74.0), xWeight) - 0.5;
	float vertical = grooves * (1.0 - smoothstep(6.0, 24.0, footprint));
	float worn = (cliffNoise(vWorld, vec2(61.0, 36.0), xWeight) - 0.5)
		* (1.0 - smoothstep(14.0, 65.0, footprint));

	// These are the same dipping contacts as the height sampler. Wrap inside the
	// basal bed so the cap-to-base transition also receives a filtered edge.
	float height = (vWorld.y - dot(vWorld.xz, uBedDatum.xy)) / uBedDatum.z;
	float pixel = fwidth(height);
	vec4 lower = uBedContacts[0], upper = uBedContacts[1];
	float period = upper.w - lower.x, start = lower.x + 0.8;
	float h = mod(height - start, period) + start;
	// Sharper bands narrow each contact. contact() still widens one to the pixel footprint. The floor keeps a program
	// that never bound an appearance from dividing by zero; every valid sharpness is at least 1.
	float sharpness = max(uBanding.z, 1.0);
	float shale = contact(h, lower.y, 0.09 / sharpness, pixel);
	float ledge = contact(h, lower.z, 0.023 / sharpness, pixel);
	float sandstone = contact(h, lower.w, 0.043 / sharpness, pixel);
	float redBeds = contact(h, upper.x, 0.06 / sharpness, pixel);
	float upperCliff = contact(h, upper.y, 0.045 / sharpness, pixel);
	float cap = contact(h, upper.z, 0.026 / sharpness, pixel);
	float basal = contact(h, upper.w, 0.07 / sharpness, pixel);

	vec3 rust = uCanyonColors[0], coral = uCanyonColors[3], distant = uCanyonColors[7];
	rock = mix(rust, uCanyonColors[1], shale);
	rock = mix(rock, uCanyonColors[2], ledge);
	rock = mix(rock, coral, sandstone);
	rock = mix(rock, uCanyonColors[4], redBeds);
	rock = mix(rock, coral, upperCliff);
	rock = mix(rock, uCanyonColors[5], cap);
	rock = mix(rock, rust, basal);
	rock = mix(distant, rock, uBanding.y);
	// Very small authored spacings can fit a whole section inside a pixel.
	rock = mix(rock, distant, smoothstep(0.7, 3.0, pixel));

	float thinBeds = clamp(1.0 - shale + redBeds - upperCliff + basal, 0.0, 1.0);
	float parting = (smoothNoise(vec2(height * 13.0, 0.43)) - 0.5)
		* (1.0 - smoothstep(0.025, 0.085, pixel));
	float joints = mix(fracture(vWorld.xy, footprint), fracture(vWorld.zy, footprint), xWeight)
		* (1.0 - smoothstep(1.0, 5.0, footprint));
	rock *= 1.0 + mix(0.06, 0.40, thinBeds)*parting
		+ cliff*(0.62*vertical + 0.35*worn - 0.28*joints*(1.0-thinBeds));

	vec3 dust = uCanyonColors[6] * (0.91 + 0.24*macro + 0.19*worn);
	// Small aprons of rubble mask the rock at the foot of the resistant beds.
	float apron = 1.0 - smoothstep(0.02, 0.42, min(abs(h-lower.z), abs(h-upper.z)));
	rock = mix(rock, dust, apron * smoothstep(-0.12, 0.34, vertical + 0.45*worn) * 0.64);
	// Angular fragments give loose slopes a distinct texture at walking distances.
	vec2 gravel = mat2(0.8,0.6,-0.6,0.8)*vWorld.xz / 2.4;
	vec2 cell = floor(gravel);
	float seed = hashCell(cell);
	vec2 stone = abs(fract(gravel) - vec2(0.36 + 0.28*seed, 0.5));
	float radius = stone.x + stone.y*(0.7+seed);
	float aa = max(0.025, footprint/2.4);
	float chips = (1.0 - smoothstep(0.18, 0.23 + aa, radius)) * (seed - 0.28);
	debris = mix(dust, rock, 0.51 + 0.15*macro) * (1.0 + 0.53*chips*(1.0-smoothstep(0.6,2.3,footprint)));

	// Finite screen-space gradients of broad grooves perturb diffuse lighting.
	float relief = cliff*5.0*grooves;
	vec3 dx = dFdx(vWorld), dy = dFdy(vWorld);
	vec3 u = cross(dy, normal), v = cross(normal, dx);
	float det = dot(dx, u);
	vec3 gradient = (dFdx(relief)*u + dFdy(relief)*v) * sign(det) / max(abs(det), 0.0001);
	normal = normalize(normal - gradient * (1.0 - smoothstep(6.0, 24.0, footprint)) / (1.0 + length(gradient)));
}
void main()
{
	vec3 n = normalize(vNormal);
	vec3 weights = pow(abs(n), vec3(4.0)); weights /= dot(weights, vec3(1.0));
	float detail = dot(weights, vec3(grain(vWorld.yz * 0.3), grain(vWorld.xz * 0.3), grain(vWorld.xy * 0.3)));
	float footprint = max(length(dFdx(vWorld)), length(dFdy(vWorld)));
	float macro = landVariation(vWorld.xz, footprint) * uMacroVariation;
	float slope = 1.0 - max(0.0, n.y);
	vec3 grass = uGrassColor * (0.85 + 0.15 * macro);
	vec3 rock, cover, lightingNormal = n;
	if(uTerrainStyle > 2.5)
		canyonMaterial(footprint, weights, slope, macro, rock, cover, lightingNormal);
	else
	{
		float strataPhase = vWorld.y * uBanding.x;
		float blur = fwidth(strataPhase);
		float strataFilter = exp(-0.125 * pow(blur, 2.0));
		// Clamping the sine adds odd harmonics: strong ones up to about the sharpness times the band frequency, and a
		// third harmonic at any sharpness above 1. The sharpening fades with the filter of the harmonic two orders
		// above the sharpness, before any of them passes the pixel rate and folds back.
		float sharpness = 1.0 + (uBanding.z - 1.0) * exp(-0.125 * pow(blur * (uBanding.z + 2.0), 2.0));
		float profile = clamp(sin(strataPhase) * sharpness, -1.0, 1.0);
		rock = mix(uRockColor, uRockBandColor, 0.5 + 0.5 * uBanding.y * profile * strataFilter);
		// Strong macro variation must not push the soil weight outside 0 to 1, past either colour.
		cover = uTerrainStyle > 1.5 ? mix(uSoilColor, rock, clamp(0.4 + 0.2 * macro, 0.0, 1.0)) : grass;
	}
	vec3 sand = uSandColor;
	vec3 snow = uSnowColor;
	vec3 albedo = mix(cover, rock, smoothstep(uSlopeBlend.x,uSlopeBlend.y,slope));
	albedo = mix(albedo, sand,
		(1.0 - smoothstep(uWaterLevel + uHeightBlend.x,uWaterLevel + uHeightBlend.y,vWorld.y)) * n.y);
	float snowline = uSnowline.x + 25.0 * macro;
	if(uTerrainStyle > 0.5 && uTerrainStyle < 1.5)
		albedo = mix(albedo, rock, smoothstep(snowline + uHeightBlend.z,snowline + uHeightBlend.w,vWorld.y));
	if(uTerrainStyle < 1.5)
		albedo = mix(albedo, snow, smoothstep(snowline,snowline + uSnowline.y,vWorld.y)
			* (1.0 - smoothstep(uSlopeBlend.z,uSlopeBlend.w,slope)));
	// Blend material weights, never categorical identifiers: a rock/road boundary must not become snow.
	float painted = clamp(dot(vPaint0 + vPaint1, vec3(1.0)), 0.0, 1.0);
	vec3 craft = mix(vec3(0.06,0.12,0.17), vec3(0.95,0.28,0.03), step(0.15,vWorld.y - uWaterLevel));
	albedo = albedo * (1.0 - painted) + rock * vPaint0.x + grass * vPaint0.y + sand * vPaint0.z
		+ snow * vPaint1.x + uRoadColor * vPaint1.y + craft * vPaint1.z;
	albedo *= 1.0 + 0.07 * detail * (1.0 - smoothstep(1.0,8.0,footprint));
	vec3 direct = terrainSunlight(max(0.0,vWorld.y)) / 3.14159265;
	float shadow = mountainShadow(vWorld);
	// Approximate diffuse sky fill follows solar irradiance and fades through twilight.
	float illumination = uSunIntensity / 20.0 * smoothstep(-0.1,0.05,uSunDirection.y);
	vec3 ambient = vec3(0.08,0.13,0.20) * (0.35 + 0.65 * max(0.0,n.y)) * illumination;
	if(uTerrainStyle > 2.5)
		ambient += uCanyonBounce * illumination * (1.0 - max(0.0,n.y));
	vec3 lit = albedo * (ambient + direct * max(0.0,dot(mix(lightingNormal,n,painted),uSunDirection)) * shadow);
	lit += terrainNightMaterial(albedo, mix(lightingNormal,n,painted), uNightAmbient);
	lit += albedo / 3.14159265 * terrainSpotLight(vWorld, mix(lightingNormal,n,painted), uWaterLevel, uWaterOptics.z);
	lit = waterColumnLight(lit, vRelative, uEye.y, uWaterLevel,
		vec3(illumination) + uNightAmbient + terrainSpotWaterColumn(uEye, vWorld, uWaterLevel));
	vec3 aerial = terrainAtmosphere(normalize(vRelative), uEye.y, length(vRelative), lit);
	color = vec4(terrainToneMap(aerial),1.0);
}
