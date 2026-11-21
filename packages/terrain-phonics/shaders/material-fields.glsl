	#ifdef MATERIAL_STACK
	vec4 sizes=uFieldScale;
	#else
	const vec4 sizes=vec4(48.,3.,13.,23.);
	#endif
	// Noise on the upright planes runs down the fall line. Blending the two planes narrows its spread, and dividing
	// by the length of the blend weights restores it. Each noise fades out before its features shrink to two pixels.
	float xWeight = smoothstep(0.3, 0.7, weights.x / max(weights.x + weights.z, 0.001));
	float spread = inversesqrt(xWeight*xWeight + (1.0-xWeight)*(1.0-xWeight));
	float gullyFade = 1.0 - smoothstep(9.0, 24.0, footprint), rillFade = 1.0 - smoothstep(2.4, 6.0, footprint);
	float streakFade = 1.0 - smoothstep(1.0, 2.5, footprint), blotchFade = 1.0 - smoothstep(6.0, 16.0, footprint);
	float clumpFade = 1.0 - smoothstep(1.5, 4.0, footprint), tuftFade = 1.0 - smoothstep(0.5, 1.3, footprint);
	vec3 blotchGradient = smoothNoiseGradient(pos.xz / 31.0 + 5.17);
	// Let nearby gullies bend differently across the face as they descend. Carry the warp's derivative through
	// the noise so diffuse relief follows the same bends as the color.
	float phase = pos.y / 64.0 + 2.8*blotchGradient.x;
	float meander = 7.0*sin(phase);
	vec3 facePosition = pos + vec3(meander, 0.0, -meander);
	vec3 meanderSlope = 7.0*cos(phase)*vec3(2.8*blotchGradient.y/31.0, 1.0/64.0, 2.8*blotchGradient.z/31.0);
	vec4 gullyGradient = cliffNoiseGradient(facePosition, sizes.x*vec2(1.,2.75), xWeight);
	gullyGradient.yzw += (gullyGradient.y - gullyGradient.w) * meanderSlope;
	vec4 rillGradient = cliffNoiseGradient(pos + 71.3, vec2(12.0, 20.0), xWeight);
	float gullyNoise = (gullyGradient.x - 0.5) * spread, blotchNoise = blotchGradient.x - 0.5;
	float gully = gullyNoise * gullyFade, rill = (rillGradient.x - 0.5) * spread * rillFade;
	float blotch = blotchNoise * blotchFade;
	float streak = (cliffNoise(facePosition - 23.9, vec2(5.0, 21.0), xWeight) - 0.5) * spread * streakFade;
	float clump = (smoothNoise(pos.xz / 7.5 - 21.4) - 0.5) * clumpFade;
	float tuft = (smoothNoise(pos.xz / 2.3 + 9.7) - 0.5) * tuftFade;
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
	float above = pos.y - snowline + 60.0*rib + 50.0*blotch + 70.0*macro;
	// GLSL leaves smoothstep() undefined when its first edge is not below the second, so the fall is written as a rise.
	float lowland = 1.0 - smoothstep(uMountainZones.x - 45.0, uMountainZones.x + 35.0, above);
	// Soil holds on steeper ground low down.
	float steep = smoothstep(uSlopeBlend.x, uSlopeBlend.y, slope + 0.15*rib - 0.07*lowland);

	// One staggered grid of cells holds tree crowns and talus blocks, each block turned and sized at random.
	vec2 grid = mat2(0.8,0.6,-0.6,0.8) * pos.xz / sizes.y;
	vec2 cell = floor(grid);
	float seed = hashCell(cell), seed2 = hashCell(cell + 41.7);
	vec2 offset = fract(grid) - 0.5 - 0.25*(vec2(seed, seed2) - 0.5);
	float turn = 6.2832 * seed2;
	offset = mat2(cos(turn), sin(turn), -sin(turn), cos(turn)) * offset;
	// The pixel has a floor because smoothstep() is undefined for two equal edges. Crowns, blocks and boulders fade out
	// before a cell spans three pixels.
	float pixel = max(footprint, 0.001), aa = footprint / sizes.y, cellFade = 1.0 - smoothstep(0.4, 1.0, 3.*aa);
	// Large blocks and crowns reach past their cell, which cuts them off. The cut fades over a pixel, so it is no
	// hard step between two cells.
	vec2 inside = min(fract(grid), 1.0 - fract(grid));
	float within = smoothstep(0.0, pixel / sizes.y, min(inside.x, inside.y));
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
	float boulder = (1.0 - smoothstep(0.1 + 0.2*boulderSeed, 0.16 + 0.2*boulderSeed + footprint / (2.7*sizes.y),
		max(boulderOffset.x, boulderOffset.y * (0.7 + 0.5*fract(boulderSeed * 3.1))))) * step(0.4, boulderPlace)
		* smoothstep(0.0, pixel / (2.7*sizes.y), min(boulderInside.x, boulderInside.y));
	float boulders = mix(1.0, mix(0.84, 0.98 + 0.3*boulderSeed, boulder) / 0.88, 1.0 - smoothstep(1.2, 3.0, 3.*aa));

	// Shrubs mottle the meadow, tundra keeps meadow green in its hollows, and wet grass fills level floors. Shrubs
	// blend into the meadow over a few meters rather than ending at a line, and they fill most openings in the forest.
	// Shrub crowns show more faintly than tree crowns. Ledges on steep ground lie in the shade of the rock around them.
	float shrubs = max(patchy(0.4 + 0.3*steep, ragged + 0.4*blotch, 0.6*max(raggedDetail, blotchFade)),
		0.7*lowland);
	float level = 1.0 - smoothstep(0.006, 0.03, slope + 0.03*blotch);
	// Forest ends in clumps of single crowns, and avalanches keep chutes open down the gullies of steeper slopes.
	float chutes = min(rib, 0.0) * smoothstep(0.1, 0.2, slope) * upright;
	float trees = patchy(lowland * (1.0 - smoothstep(0.2, 0.33, slope + 0.2*rib)) * (1.0 - level),
		ragged + 0.4*blotch + 1.2*chutes + 0.3*(crown - 0.5)*cellFade,
		0.6*max(raggedDetail, max(blotchFade, gullyFade)));
	// Gullies hold shade and water, so everything in them is darker than on the ribs.
	float hollows = 1.0 + 0.25*rib*upright;
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
	// Granite slabs: columns across the face, each broken by joints that cross it diagonally at its own angle and
	// irregular spacing.
	vec2 face = (xWeight > 0.5 ? facePosition.zy : facePosition.xy) + vec2(3.0*gully, 0.0);
	float column = floor(face.x / sizes.z), across = fract(face.x / sizes.z);
	// Neighboring columns share the direction in which their joints dip.
	float lean = hashCell(vec2(column, 5.3));
	float tilt = (0.15 + 0.45*lean) * sign(hashCell(vec2(floor(column / 4.0), 8.9)) - 0.5);
	float along = (face.y + tilt * across * sizes.z) / sizes.w + hashCell(vec2(column, 1.9));
	vec2 slabCell = vec2(column, floor(along));
	float slab = hashCell(slabCell);
	// Each slab steps down over the one below it, so a joint shades the foot of the slab above it. `riser` is the
	// distance up from the joint below and `below` the distance down from the joint above, in meters.
	float rise = sizes.w * inversesqrt(1.0 + tilt*tilt);
	float riser = fract(along) * rise, below = (1.0 - fract(along)) * rise, side = min(across, 1.0 - across) * sizes.z;
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
	float lichen = uMountainAmounts.z * patchy(0.55, blotch + ragged + 0.26*streak, max(raggedDetail, blotchFade));
	// Bare rock covers the steep ground and, nearing the snowline, the ridges. Rock follows the ribs and vegetation the
	// gullies between them. Gentle bare ground is a block field.
	float alpine = smoothstep(uHeightBlend.z, uHeightBlend.w, above);
	float bare = patchy(max(steep, alpine), 0.5*rib + 0.5*ragged, max(raggedDetail, gullyFade));

	float groundShade = hollows * (1.0 - .30*stipple);
	float meadowShade = (1.0 + .2*clump + .2*rill) * (1.0 - .25*steep) * groundShade;
	float tundraCover = patchy(smoothstep(uMountainZones.y-35.,uMountainZones.y+35.,above),
		.8*ragged+.3*rill,max(raggedDetail,rillFade));
	float cliffFraction = mix(.4, 1., smoothstep(.05,.16,slope));
	float cliffCover = bare * cliffFraction;
	float stainCover = cliffCover * sheets * clamp(.5+.42*streak+.4*rill,0.,.85);
	// Convert the nested rubble / cliff / stain mixture to ordered source-over coverages.
	float blockCover = bare*(1.-cliffFraction)/max(.000001,1.-cliffCover);
	float graniteCover = (cliffCover-stainCover)/max(.000001,1.-stainCover);
	vec3 lichenTint = mix(vec3(1.), vec3(.88,.94,.84)-.22*stipple,lichen);
	vec3 jointTint = tone*lichenTint;
	float shore = (1.-smoothstep(water+uHeightBlend.x,water+uHeightBlend.y,pos.y))*normal.y;
	float snow = smoothstep(snowline,snowline+uSnowline.y,pos.y)*(1.-smoothstep(uSlopeBlend.z,uSlopeBlend.w,slope));
	float relief = (1.0 - smoothstep(snowline, snowline + uSnowline.y, pos.y)
		* (1.0 - smoothstep(uSlopeBlend.z, uSlopeBlend.w, slope)))
		* smoothstep(0.06, 0.25, abs(dot(normalize(relative), normal))) * (1.0 - smoothstep(3.0, 10.0, footprint));
	vec3 facet = (vec3(hashCell(slabCell + 31.7), slab, hashCell(slabCell + 63.1)) - 0.5) * vec3(0.18, 0.05, 0.18);
	// Light the shallower ribs with the world-space gradient of (12 + 10 * blotch) * groove + 1.6 * rill.
	// The relief takes the gully and blotch noise before the
	// footprint fades them, since the footprint jumps at triangle edges. Each gully bottom is rounded by the view
	// distance and over at least a pixel and a half across the gully; the footprint, measured along the ground seen
	// edge-on, is far longer than a pixel across the gully there. The rills fade with the footprint and the distance.
	float viewDistance = length(relative);
	vec3 gullySlope = spread * gullyGradient.yzw;
	float gullyPixel = abs(dot(gullySlope, dFdx(pos))) + abs(dot(gullySlope, dFdy(pos)));
	float groove = sqrt(gullyNoise*gullyNoise + pow(max(0.01 + 0.00004*viewDistance, 1.5*gullyPixel), 2.0));
	float rills = 1.6 * spread * rillFade * (1.0 - smoothstep(200.0, 700.0, viewDistance));
	vec3 lift = upright * (10.0*groove*vec3(blotchGradient.y, 0.0, blotchGradient.z) / 31.0
		+ (12.0 + 10.0*blotchNoise) * gullyNoise / groove * gullySlope + rills * rillGradient.yzw);
