#version 300 es
precision highp float;
precision highp int;
// ATMOSPHERE
// OPTICS
// SPOT LIGHTS
// LAYERS
in vec3 vRelative, vWorld, vNormal;
in vec3 vPaint0, vPaint1;
uniform vec3 uEye;
uniform float uWaterLevel, uWorldSize, uTerrainStyle;
uniform vec4 uBedContacts[2];
uniform vec3 uBedDatum; // X/Z dip and vertical spacing in meters.
uniform highp sampler2D uTerrainShadowMap;
uniform vec2 uShadowHeightRange;
uniform vec2 uShadowReceiverRange;
uniform float uCraft;
uniform float uTerrainShadowExtent;
uniform int uTerrainShadowReady;
// TerrainAppearance. Colors are linear. The canyon palette runs rust, shale, ledge, sandstone, red beds, cap, dust,
// then the color of beds too fine to resolve.
uniform vec3 uGrassColor, uSoilColor, uRockColor, uRockBandColor, uSandColor, uSnowColor, uRoadColor;
uniform vec3 uCanyonColors[8], uCanyonBounce;
uniform vec3 uBanding; // Strata phase per meter, band contrast and band sharpness.
uniform vec4 uSlopeBlend; // Start and end slopes of cover to rock, then of snow fading off steep ground.
uniform vec4 uHeightBlend; // Sand start and end above the water, then alpine rock start and end from the snowline.
uniform vec2 uSnowline; // Mean snowline and the height over which snow becomes complete.
uniform float uMacroVariation;
// The mountain palette runs forest, meadow, tundra, granite, stain and talus.
uniform vec3 uMountainColors[6];
uniform vec2 uMountainZones; // Treeline and the foot of the tundra, in meters from the snowline.
uniform vec3 uMountainAmounts; // Talus, stain and lichen.
out vec4 color;
float mountainShadow(vec3 position)
{
	if(uSunDirection.y <= 0.0) return 0.0;
	if(uTerrainShadowReady == 0) return 1.0;
	vec2 uv = position.xz / uTerrainShadowExtent + 0.5;
	if(any(lessThan(uv, vec2(0.0))) || any(greaterThan(uv, vec2(1.0)))) return 1.0;
	vec3 sampleMap = texture(uTerrainShadowMap, uv).rgb;
	float ceiling = uShadowHeightRange.x + uShadowHeightRange.y * dot(sampleMap.gb,vec2(256.,1.)/257.);
	float ledge = smoothstep(-max(2.,.0001*uTerrainShadowExtent),0.,position.y+3.-ceiling);
	if(uCraft > .5 || uShadowReceiverRange.y <= 0.) return ledge;
	// At distance, coarse mesh triangles can cut straight through a cliff. The overview
	// compares caster and receiver on the same fixed terrain surface before filtering.
	vec2 distanceToEye = abs(position.xz-uEye.xz);
	return mix(ledge,sampleMap.r,smoothstep(uShadowReceiverRange.x,uShadowReceiverRange.y,max(distanceToEye.x,distanceToEye.y)));
}
#ifdef SURFACE_LAYERS
uniform highp sampler2D uShadowTriangles;
uniform vec3 uShadowGrid;

float localSunlight(vec2 world, float footprint, float broad)
{
	if(uShadowGrid.z <= 0. || uCraft > .5 || broad <= 0.) return broad;
	vec2 grid = (world-uShadowGrid.xy)/uShadowGrid.z;
	// The cache moves in blocks of at most eight cells. Its inner 28-cell radius is
	// resident on both sides of a snap. Anchor the blend to the moving eye within it.
	vec2 offset = abs(world-uEye.xz)/uShadowGrid.z;
	float reach = max(offset.x,offset.y);
	if(reach >= 28. || any(lessThan(grid,vec2(0.))) || any(greaterThanEqual(grid,vec2(64.)))) return broad;
	ivec2 cell = ivec2(floor(grid));
	vec2 f = fract(grid);
	bool upper = f.x+f.y > 1.;
	if(upper) f = 1.-f;
	vec4 record = texelFetch(uShadowTriangles,ivec2(2*cell.x+int(upper),cell.y),0);
	int kind = int(round(255.*record.r));
	float shade = float(kind);
	if(kind >= 2)
	{
		vec3 w = vec3(1.-f.x-f.y,f);
		vec3 cut = max(record.gba,vec3(1./255.));
		float side, area;
		bool singleLit;
		if(kind < 8)
		{
			int i = (kind-2)%3;
			side = 1.-w[(i+1)%3]/cut.x-w[(i+2)%3]/cut.y;
			area = cut.x*cut.y;
			singleLit = kind >= 5;
		}
		else
		{
			int lo = w.x <= min(w.y,w.z) ? 0 : (w.y <= w.z ? 1 : 2);
			int j = (lo+1)%3, k = (lo+2)%3;
			side = 1.-(w[j]-w[lo])/cut[j]-(w[k]-w[lo])/cut[k];
			area = dot(cut,cut.yzx)/3.;
			singleLit = kind == 9;
		}
		float pixel = footprint/uShadowGrid.z;
		float aa = clamp(pixel,.035,.4) / max(.05,min(cut.x,cut.y));
		shade = mix(smoothstep(-aa,aa,side),area,smoothstep(.15,.75,pixel));
		if(!singleLit) shade = 1.-shade;
	}
	return mix(min(broad,shade),broad,smoothstep(14.,28.,reach));
}
#endif
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

// Value noise with its derivatives along both axes.
vec3 smoothNoiseGradient(vec2 p)
{
	vec2 cell = floor(p), f = fract(p);
	vec2 w = f * f * f * (f * (f * 6.0 - 15.0) + 10.0), slope = 30.0 * f * f * (f * (f - 2.0) + 1.0);
	float a = hashCell(cell), b = hashCell(cell + vec2(1.0,0.0)), c = hashCell(cell + vec2(0.0,1.0));
	float d = hashCell(cell + vec2(1.0)), k = a - b - c + d;
	return vec3(mix(mix(a, b, w.x), mix(c, d, w.x), w.y), slope * vec2(b - a + k*w.y, c - a + k*w.x));
}

// cliffNoise() with its gradient in world space: the value, then its derivatives along x, y and z.
vec4 cliffNoiseGradient(vec3 p, vec2 scale, float xWeight)
{
	vec3 front = smoothNoiseGradient(p.xy / scale), side = smoothNoiseGradient(p.zy / scale);
	return vec4(mix(front.x, side.x, xWeight), (1.0 - xWeight) * front.y / scale.x,
		mix(front.z, side.z, xWeight) / scale.y, xWeight * side.y / scale.x);
}

// Tilt a normal by the screen-space gradient of a relief height in meters. `fade` scales the tilt.
vec3 bump(vec3 normal, float relief, float fade)
{
	vec3 dx = dFdx(vWorld), dy = dFdy(vWorld);
	vec3 u = cross(dy, normal), v = cross(normal, dx);
	float det = dot(dx, u);
	vec3 gradient = (dFdx(relief)*u + dFdy(relief)*v) * sign(det) / max(abs(det), 0.0001);
	return normalize(normal - gradient * fade / (1.0 + length(gradient)));
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
	normal = bump(normal, cliff*5.0*grooves, 1.0 - smoothstep(6.0, 24.0, footprint));
}

// Share of the ground that a patchy material covers. The noise mostly runs from -0.5 to 0.5, peaks near -0.9 and 0.9,
// and has been faded for the footprint; `detail` is how much of it is left. As it fades, the edge widens into a blend
// with about the same mean. The edge is never narrower than a pixel.
float patchy(float amount, float noise, float detail)
{
	float value = amount - 0.5 + noise;
	float edge = max(mix(0.5, 0.1, detail), fwidth(value));
	return smoothstep(-edge, edge, value);
}

// A patch whose amount must leave none at 0. The noise of patchy() reaches past 0.5, so it would still cover a few
// percent of the ground there; below an amount of 0.1 the cover also falls with the amount.
float sparse(float amount, float noise, float detail)
{
	return patchy(amount, noise, detail) * min(1.0, 10.0*amount);
}

// Small flecks stay tied to the ground and fade out before a fleck shrinks below a few pixels.
float mountainStipple(float footprint, vec3 weights)
{
	const float size = 1.6;
	float fade = patternVisibility(footprint,size,STIPPLE_PATTERN);
	if(fade <= 0.0) return 0.0;
	return fade * projectedPattern(vWorld,weights,size,1.0,vec2(73.37,19.81),STIPPLE_PATTERN);
}

// Granite mountains. Steep ground shows jointed slabs streaked down the fall line, talus lies on the moderately steep
// ground, and the cover runs from forest through subalpine meadow to tundra as the ground nears the snowline. Ribs and
// gullies running down the fall line move every boundary up or down, so none of them follows a contour.
void mountainMaterial(float footprint, vec3 weights, float slope, float macro, float snowline, float stipple,
	out vec3 rock, out vec3 cover, out float bare, inout vec3 normal)
{
	vec3 forest = uMountainColors[0], meadow = uMountainColors[1], tundra = uMountainColors[2];
	vec3 granite = uMountainColors[3], stain = uMountainColors[4], talus = uMountainColors[5];

	// Noise on the upright planes runs down the fall line. Blending the two planes narrows its spread, and dividing
	// by the length of the blend weights restores it. Each noise fades out before its features shrink to two pixels.
	float xWeight = smoothstep(0.3, 0.7, weights.x / max(weights.x + weights.z, 0.001));
	float spread = inversesqrt(xWeight*xWeight + (1.0-xWeight)*(1.0-xWeight));
	float gullyFade = 1.0 - smoothstep(9.0, 24.0, footprint), rillFade = 1.0 - smoothstep(2.4, 6.0, footprint);
	float streakFade = 1.0 - smoothstep(1.0, 2.5, footprint), blotchFade = 1.0 - smoothstep(6.0, 16.0, footprint);
	float clumpFade = 1.0 - smoothstep(1.5, 4.0, footprint), tuftFade = 1.0 - smoothstep(0.5, 1.3, footprint);
	vec3 blotchGradient = smoothNoiseGradient(vWorld.xz / 31.0 + 5.17);
	// Let nearby gullies bend differently across the face as they descend. Carry the warp's derivative through
	// the noise so diffuse relief follows the same bends as the color.
	float phase = vWorld.y / 64.0 + 2.8*blotchGradient.x;
	float meander = 7.0*sin(phase);
	vec3 facePosition = vWorld + vec3(meander, 0.0, -meander);
	vec3 meanderSlope = 7.0*cos(phase)*vec3(2.8*blotchGradient.y/31.0, 1.0/64.0, 2.8*blotchGradient.z/31.0);
	vec4 gullyGradient = cliffNoiseGradient(facePosition, vec2(48.0, 132.0), xWeight);
	gullyGradient.yzw += (gullyGradient.y - gullyGradient.w) * meanderSlope;
	vec4 rillGradient = cliffNoiseGradient(vWorld + 71.3, vec2(12.0, 20.0), xWeight);
	float gullyNoise = (gullyGradient.x - 0.5) * spread, blotchNoise = blotchGradient.x - 0.5;
	float gully = gullyNoise * gullyFade, rill = (rillGradient.x - 0.5) * spread * rillFade;
	float blotch = blotchNoise * blotchFade;
	float streak = (cliffNoise(facePosition - 23.9, vec2(5.0, 21.0), xWeight) - 0.5) * spread * streakFade;
	float clump = (smoothNoise(vWorld.xz / 7.5 - 21.4) - 0.5) * clumpFade;
	float tuft = (smoothNoise(vWorld.xz / 2.3 + 9.7) - 0.5) * tuftFade;
	// The edges of patches take every octave, so they stay ragged at every distance.
	float ragged = clump + 0.5*tuft, raggedDetail = max(clumpFade, tuftFade);
	// Gullies follow the zero crossings of the noise. Their rounded bottoms wind between wider ribs.
	// Each bottom is rounded over a few pixels so that it does not alias. The noise departs from zero by 0.19 on
	// average, and subtracting the expected depth keeps rib at about zero on average at every distance, so the
	// distance does not move a boundary. Level ground has no fall line, and the upright planes would stretch their
	// noise across it.
	float upright = smoothstep(0.015, 0.08, slope);
	float bottom = 0.01 + 0.012*footprint;
	float cut = sqrt(gully*gully + bottom*bottom);
	float rib = mix(blotch, 1.5*(cut - sqrt(0.036*gullyFade*gullyFade + bottom*bottom)) + 0.5*rill, upright);

	// Height above the snowline, raised on ribs and lowered in gullies. The broad variation keeps distant boundaries,
	// where the finer noise has faded, off the contours.
	float above = vWorld.y - snowline + 60.0*rib + 50.0*blotch + 70.0*macro;
	// GLSL leaves smoothstep() undefined when its first edge is not below the second, so the fall is written as a rise.
	float lowland = 1.0 - smoothstep(uMountainZones.x - 45.0, uMountainZones.x + 35.0, above);
	// Soil holds on steeper ground low down.
	float steep = smoothstep(uSlopeBlend.x, uSlopeBlend.y, slope + 0.15*rib - 0.07*lowland);

	// One staggered grid of cells holds tree crowns and talus blocks, each block turned and sized at random.
	vec2 grid = mat2(0.8,0.6,-0.6,0.8) * vWorld.xz / 3.0;
	vec2 cell = floor(grid);
	float seed = hashCell(cell), seed2 = hashCell(cell + 41.7);
	vec2 offset = fract(grid) - 0.5 - 0.25*(vec2(seed, seed2) - 0.5);
	float turn = 6.2832 * seed2;
	offset = mat2(cos(turn), sin(turn), -sin(turn), cos(turn)) * offset;
	// The pixel has a floor because smoothstep() is undefined for two equal edges. Crowns, blocks and boulders fade out
	// before a cell spans three pixels.
	float pixel = max(footprint, 0.001), aa = footprint / 3.0, cellFade = 1.0 - smoothstep(0.4, 1.0, footprint);
	// Large blocks and crowns reach past their cell, which cuts them off. The cut fades over a pixel, so it is no
	// hard step between two cells.
	vec2 inside = min(fract(grid), 1.0 - fract(grid));
	float within = smoothstep(0.0, pixel / 3.0, min(inside.x, inside.y));
	float size = 0.28 + 0.12*seed;
	float block = (1.0 - smoothstep(size - 0.05, size + 0.03 + aa,
		max(abs(offset.x), abs(offset.y) * (0.75 + 0.5*seed2)))) * within;
	float blocks = mix(1.0, mix(0.82, 0.88 + 0.24*seed, block) / 0.91, cellFade);
	// Crowns vary in size, and the tufts wobble their edges, so the grid does not show as a lattice.
	float crown = (1.0 - smoothstep(0.2, 0.34 + 0.2*fract(seed * 7.7) + aa, length(offset) + 0.25*tuft)) * within;
	float crowns = mix(1.0, mix(0.7, 1.15 + 0.2*seed2, crown) / 0.89, cellFade);
	// Boulders on a grid three times coarser carry the rubble's texture further out than the blocks do. Each is
	// turned and sized at random, and the blocks show between the smaller ones and in the cells that have none. Their
	// cells cut them off as the small cells cut the blocks.
	vec2 boulderGrid = grid / 2.7 + 0.37;
	float boulderSeed = hashCell(floor(boulderGrid) + 17.3);
	float boulderPlace = fract(boulderSeed * 29.3);
	float boulderTurn = 6.2832 * fract(boulderSeed * 13.7);
	vec2 boulderOffset = abs(mat2(cos(boulderTurn), sin(boulderTurn), -sin(boulderTurn), cos(boulderTurn))
		* (fract(boulderGrid) - 0.5 - 0.3*(vec2(boulderPlace, fract(boulderSeed * 7.3)) - 0.5)));
	vec2 boulderInside = min(fract(boulderGrid), 1.0 - fract(boulderGrid));
	float boulder = (1.0 - smoothstep(0.1 + 0.2*boulderSeed, 0.16 + 0.2*boulderSeed + footprint / 8.1,
		max(boulderOffset.x, boulderOffset.y * (0.7 + 0.5*fract(boulderSeed * 3.1))))) * step(0.4, boulderPlace)
		* smoothstep(0.0, pixel / 8.1, min(boulderInside.x, boulderInside.y));
	float boulders = mix(1.0, mix(0.84, 0.98 + 0.3*boulderSeed, boulder) / 0.88, 1.0 - smoothstep(1.2, 3.0, footprint));

	// Shrubs mottle the meadow, tundra keeps meadow green in its hollows, and wet grass fills level floors. Shrubs
	// blend into the meadow over a few meters rather than ending at a line, and they fill most openings in the forest.
	// Shrub crowns show more faintly than tree crowns. Ledges on steep ground lie in the shade of the rock around them.
	float shrubs = max(patchy(0.4 + 0.3*steep, ragged + 0.4*blotch, 0.6*max(raggedDetail, blotchFade)),
		0.7*lowland);
	vec3 vegetation = mix(meadow * (1.0 + 0.5*tuft), mix(meadow, forest, 0.55) * mix(1.0, crowns, 0.6), shrubs)
		* (1.0 + 0.2*clump + 0.2*rill) * (1.0 - 0.25*steep);
	vegetation = mix(vegetation, tundra * (1.0 + 0.3*clump + 0.4*tuft + 0.3*rill),
		patchy(smoothstep(uMountainZones.y - 35.0, uMountainZones.y + 35.0, above), 0.8*ragged + 0.3*rill,
			max(raggedDetail, rillFade)));
	float level = 1.0 - smoothstep(0.006, 0.03, slope + 0.03*blotch);
	vegetation = mix(vegetation, uGrassColor * (1.0 + 0.2*blotch + 0.3*tuft), lowland * level);
	// Forest ends in clumps of single crowns, and avalanches keep chutes open down the gullies of steeper slopes.
	float chutes = min(rib, 0.0) * smoothstep(0.1, 0.2, slope) * upright;
	float trees = patchy(lowland * (1.0 - smoothstep(0.2, 0.33, slope + 0.2*rib)) * (1.0 - level),
		ragged + 0.4*blotch + 1.2*chutes + 0.3*(crown - 0.5)*cellFade,
		0.6*max(raggedDetail, max(blotchFade, gullyFade)));
	// Gullies hold shade and water, so everything in them is darker than on the ribs.
	float hollows = 1.0 + 0.25*rib*upright;
	cover = mix(vegetation, forest * crowns * (1.0 + 0.3*blotch + 0.3*clump), trees) * hollows * (1.0 - 0.30*stipple);

	// Talus lies on moderately steep ground, runs down the gullies in tongues and thins where forest has grown over
	// it. It runs on a little under the bare rock of steeper ground, but it cannot rest on the steepest ground.
	float talusShare = uMountainAmounts.x * (1.0 - 0.85*lowland)
		* smoothstep(uSlopeBlend.x, mix(uSlopeBlend.x, uSlopeBlend.y, 0.4), slope)
		* (1.0 - smoothstep(mix(uSlopeBlend.x, uSlopeBlend.y, 0.6), mix(uSlopeBlend.x, uSlopeBlend.y, 0.9), slope));
	// The sign of the gully noise places the tongues. The gullies themselves, where the noise is near zero, outline
	// each region of one sign, and tongues placed in them would close into rings. Broad variation breaks the talus
	// into separate fans along a slope.
	float scree = sparse(talusShare, 0.5*rill - 0.9*gully + 0.3*ragged + 0.3*(seed - 0.5)*cellFade + 0.8*macro,
		0.6*max(rillFade, max(raggedDetail, gullyFade)));
	vec3 rubble = talus * blocks * boulders;
	cover = mix(cover, rubble * (1.0 + 0.15*blotch), scree);

	// Granite slabs: columns across the face, each broken by joints that cross it diagonally at its own angle and
	// irregular spacing.
	vec2 face = (xWeight > 0.5 ? facePosition.zy : facePosition.xy) + vec2(3.0*gully, 0.0);
	float column = floor(face.x / 13.0), across = fract(face.x / 13.0);
	// Neighboring columns share the direction in which their joints dip.
	float lean = hashCell(vec2(column, 5.3));
	float tilt = (0.15 + 0.45*lean) * sign(hashCell(vec2(floor(column / 4.0), 8.9)) - 0.5);
	float along = (face.y + tilt * across * 13.0) / 23.0 + hashCell(vec2(column, 1.9));
	vec2 slabCell = vec2(column, floor(along));
	float slab = hashCell(slabCell);
	// Each slab steps down over the one below it, so a joint shades the foot of the slab above it. `riser` is the
	// distance up from the joint below and `below` the distance down from the joint above, in meters.
	float rise = 23.0 * inversesqrt(1.0 + tilt*tilt);
	float riser = fract(along) * rise, below = (1.0 - fract(along)) * rise, side = min(across, 1.0 - across) * 13.0;
	float jointFade = 1.0 - smoothstep(0.4, 1.5, footprint), slabFade = 1.0 - smoothstep(1.5, 4.5, footprint);
	// The two upright planes give different slabs. Where the choice changes, and at every edge of a slab, the slab's
	// own tone, stain, facet and joints fade to their mean over about a pixel, so no edge is a hard step.
	float seam = smoothstep(0.5, 1.5, abs(xWeight - 0.5) / max(fwidth(xWeight), 0.0001));
	float settled = smoothstep(0.0, pixel, min(side, min(riser, below))) * seam;
	// The shaded foot starts over a pixel across the joint, and joints show in broken lengths.
	float foot = smoothstep(-pixel, pixel, riser < below ? riser : -below)
		* (1.0 - smoothstep(0.15, 0.9 + 0.7*footprint, riser < below ? riser : 0.0));
	float line = max(1.0 - smoothstep(0.15, 0.9 + 0.7*footprint, side), foot);
	float joints = mix(0.07, line * smoothstep(-0.1, 0.1, ragged), jointFade * seam);
	// Water leaves short dark trails down the faces.
	float wet = patchy(0.25, streak + 0.3*rill, max(streakFade, rillFade));
	float tone = (1.0 + 0.2*(slab - 0.5)*slabFade*settled) * (1.0 - 0.20*joints) * (1.0 - 0.11*wet)
		* (1.0 + 0.12*blotch + 0.1*macro) * hollows;
	// Iron stains broad sheets of the faces and runs down them in streaks; lichen tints the rock gray-green in patches.
	float sheets = sparse(0.6*uMountainAmounts.y, 0.4*blotch - 0.8*gully + 0.3*(slab - 0.5)*slabFade*settled,
		max(blotchFade, gullyFade));
	vec3 cliff = mix(granite, stain, sheets * clamp(0.5 + 0.42*streak + 0.4*rill, 0.0, 0.85)) * tone;
	float lichen = uMountainAmounts.z * patchy(0.55, blotch + ragged + 0.26*streak, max(raggedDetail, blotchFade));
	cliff *= mix(vec3(1.0), vec3(0.88, 0.94, 0.84) - 0.22*stipple, lichen);

	// Bare rock covers the steep ground and, nearing the snowline, the ridges. Rock follows the ribs and vegetation the
	// gullies between them. Gentle bare ground is a block field.
	float alpine = smoothstep(uHeightBlend.z, uHeightBlend.w, above);
	bare = patchy(max(steep, alpine), 0.5*rib + 0.5*ragged, max(raggedDetail, gullyFade));
	rock = mix(mix(rubble, cliff, 0.4), cliff, smoothstep(0.05, 0.16, slope));

	// Slabs tilt as facets, blocks and crowns rise as small domes, and ribs stand out between the gullies. The caller
	// lights its snow with this normal, so the relief fades out where the snow lies complete. Relief finer than a few
	// pixels would shimmer on ground seen edge-on and in the distance, so it fades there too.
	float relief = (1.0 - smoothstep(snowline, snowline + uSnowline.y, vWorld.y)
		* (1.0 - smoothstep(uSlopeBlend.z, uSlopeBlend.w, slope)))
		* smoothstep(0.06, 0.25, abs(dot(normalize(vRelative), normal))) * (1.0 - smoothstep(3.0, 10.0, footprint));
	vec3 facet = (vec3(hashCell(slabCell + 31.7), slab, hashCell(slabCell + 63.1)) - 0.5) * vec3(0.18, 0.05, 0.18);
	vec3 knob = vec3(offset.x, 0.0, offset.y) * (0.6*block*(scree + bare*(1.0 - steep)) + 0.5*crown*trees) * cellFade;
	normal = normalize(normal + (facet * bare * steep * slabFade * settled + knob) * relief);
	// Light the shallower ribs with the world-space gradient of (12 + 10 * blotch) * groove + 1.6 * rill.
	// The relief takes the gully and blotch noise before the
	// footprint fades them, since the footprint jumps at triangle edges. Each gully bottom is rounded by the view
	// distance and over at least a pixel and a half across the gully; the footprint, measured along the ground seen
	// edge-on, is far longer than a pixel across the gully there. The rills fade with the footprint and the distance.
	float viewDistance = length(vRelative);
	vec3 gullySlope = spread * gullyGradient.yzw;
	float gullyPixel = abs(dot(gullySlope, dFdx(vWorld))) + abs(dot(gullySlope, dFdy(vWorld)));
	float groove = sqrt(gullyNoise*gullyNoise + pow(max(0.01 + 0.00004*viewDistance, 1.5*gullyPixel), 2.0));
	float rills = 1.6 * spread * rillFade * (1.0 - smoothstep(200.0, 700.0, viewDistance));
	vec3 lift = upright * (10.0*groove*vec3(blotchGradient.y, 0.0, blotchGradient.z) / 31.0
		+ (12.0 + 10.0*blotchNoise) * gullyNoise / groove * gullySlope + rills * rillGradient.yzw);
	vec3 tangential = lift - normal * dot(normal, lift);
	normal = normalize(normal - tangential * relief / (1.0 + length(tangential)));
}

uniform float uPlayHalf;
void main()
{
	if(uPlayHalf>0. && max(abs(vWorld.x),abs(vWorld.z))>uPlayHalf) discard;
	vec3 n = normalize(vNormal);
	vec3 weights = pow(abs(n), vec3(4.0)); weights /= dot(weights, vec3(1.0));
	float detail = dot(weights, vec3(grain(vWorld.yz * 0.3), grain(vWorld.xz * 0.3), grain(vWorld.xy * 0.3)));
	float footprint = max(length(dFdx(vWorld)), length(dFdy(vWorld)));
	// Only uniform pavement can skip the underlying palette. Triangles that blend a
	// shoulder still evaluate its noise and derivatives all the way to the road edge.
	float roadGradient = fwidth(vPaint1.y);
	bool pavement = vPaint1.y >= .999999 && roadGradient <= .000001;
	float macro = pavement ? 0. : landVariation(vWorld.xz, footprint) * uMacroVariation;
	float slope = 1.0 - max(0.0, n.y);
	vec3 grass = uGrassColor * (0.85 + 0.15 * macro);
	vec3 rock = uRockColor, sand = uSandColor, snow = uSnowColor, lightingNormal = n;
	vec3 albedo;
	if(pavement) albedo = uRoadColor;
	else
#ifdef SURFACE_LAYERS
	if(uLayerCount > 0) albedo = layerSurface(vWorld,n,footprint,uWaterLevel)*(1.+.07*macro);
	else
#endif
	{
		vec3 cover;
		float bare = smoothstep(uSlopeBlend.x,uSlopeBlend.y,slope);
		float snowline = uSnowline.x + 25.0 * macro;
		float stipple = 0.0;
#ifndef SURFACE_LAYERS
		if(uTerrainStyle > 0.5 && uTerrainStyle < 1.5)
#endif
		{
			stipple = mountainStipple(footprint, weights);
			mountainMaterial(footprint, weights, slope, macro, snowline, stipple, rock, cover, bare, lightingNormal);
		}
#ifndef SURFACE_LAYERS
		else if(uTerrainStyle > 2.5)
			canyonMaterial(footprint, weights, slope, macro, rock, cover, lightingNormal);
		else
		{
			float strataPhase = vWorld.y * uBanding.x;
			float blur = fwidth(strataPhase);
			float strataFilter = exp(-0.125 * pow(blur, 2.0));
			// Clamping the sine adds odd harmonics and needs filtering before they fold back into the pixel rate.
			float sharpness = 1.0 + (uBanding.z - 1.0) * exp(-0.125 * pow(blur * (uBanding.z + 2.0), 2.0));
			float profile = clamp(sin(strataPhase) * sharpness, -1.0, 1.0);
			rock = mix(uRockColor, uRockBandColor, 0.5 + 0.5 * uBanding.y * profile * strataFilter);
			// Strong macro variation must not push the soil weight outside 0 to 1, past either color.
			cover = uTerrainStyle > 1.5 ? mix(uSoilColor, rock, clamp(0.4 + 0.2 * macro, 0.0, 1.0)) : grass;
		}
#endif
		albedo = mix(cover, rock, bare);
		albedo = mix(albedo, sand * (1.0 - 0.16*stipple),
			(1.0 - smoothstep(uWaterLevel + uHeightBlend.x,uWaterLevel + uHeightBlend.y,vWorld.y)) * n.y);
		if(uTerrainStyle < 1.5)
			albedo = mix(albedo, snow * (1.0 - 0.35*stipple), smoothstep(snowline,snowline + uSnowline.y,vWorld.y)
				* (1.0 - smoothstep(uSlopeBlend.z,uSlopeBlend.w,slope)));
	}
	// Blend material weights, never categorical identifiers: a rock/road boundary must not become snow.
	float painted = clamp(dot(vPaint0 + vPaint1, vec3(1.0)), 0.0, 1.0);
	vec3 craft = mix(vec3(0.06,0.12,0.17), vec3(0.95,0.28,0.03), step(0.15,vWorld.y - uWaterLevel));
	albedo = albedo * (1.0 - painted) + rock * vPaint0.x + grass * vPaint0.y + sand * vPaint0.z
		+ snow * vPaint1.x + uRoadColor * vPaint1.y + craft * vPaint1.z;
	albedo *= 1.0 + 0.07 * detail * (1.0 - smoothstep(1.0,8.0,footprint));
	vec3 direct = terrainSunlight(max(0.0,vWorld.y)) / 3.14159265;
	float facing = max(0.0,dot(mix(lightingNormal,n,painted),uSunDirection));
	float shadow = mountainShadow(vWorld);
	#ifdef SURFACE_LAYERS
	if(facing > 0. && uSunDirection.y > 0.)
	{
		shadow = localSunlight(vWorld.xz,footprint,shadow);
	}
	#endif
	// Approximate diffuse sky fill follows solar irradiance and fades through twilight.
	float illumination = uSunIntensity / 20.0 * smoothstep(-0.1,0.05,uSunDirection.y);
	vec3 ambient = vec3(0.08,0.13,0.20) * (0.35 + 0.65 * max(0.0,n.y)) * illumination;
	if(uTerrainStyle > 2.5)
		ambient += uCanyonBounce * illumination * (1.0 - max(0.0,n.y));
	vec3 lit = albedo * (ambient + direct * facing * shadow);
	lit += terrainNightMaterial(albedo, mix(lightingNormal,n,painted), uNightAmbient);
	lit += albedo / 3.14159265 * terrainSpotLight(vWorld, mix(lightingNormal,n,painted), uWaterLevel, uWaterOptics.z);
	lit = waterColumnLight(lit, vRelative, uEye.y, uWaterLevel,
		vec3(illumination) + uNightAmbient + terrainSpotWaterColumn(uEye, vWorld, uWaterLevel));
	vec3 aerial = terrainAtmosphere(normalize(vRelative), uEye.y, length(vRelative), lit);
	color = vec4(terrainToneMap(aerial),1.0);
}
