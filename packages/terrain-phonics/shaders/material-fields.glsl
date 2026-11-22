	#ifdef MATERIAL_STACK
	vec4 sizes=uFieldScale;
	#else
	const vec4 sizes=vec4(48.,3.,13.,23.);
	#endif
	// The short stain streaks run down the upright planes. Keep their contrast where the two planes mix.
	float xWeight = smoothstep(0.3, 0.7, weights.x / max(weights.x + weights.z, 0.001));
	float spread = inversesqrt(xWeight*xWeight + (1.0-xWeight)*(1.0-xWeight));
	float gullyFade = coverBand(footprint,sizes.x), rillFade = coverBand(footprint,12.);
	float streakFade = 1.0 - smoothstep(1.0, 2.5, footprint), blotchFade = coverBand(footprint,31.);
	float clumpFade = coverBand(footprint,17.), tuftFade = coverBand(footprint,4.6);
	vec3 blotchGradient = coverNoise(pos.xz / 31.0 + 5.17);
	// Water stains wander on the exposed faces. The wider drainage channels follow
	// the local fall line, whose horizontal perpendicular points across the slope.
	float phase = pos.y / 64.0 + 2.8*(.5+blotchGradient.x);
	float meander = 7.0*sin(phase);
	vec3 facePosition = pos + vec3(meander, 0.0, -meander);
	vec2 crossSlope = vec2(-normal.z, normal.x) * inversesqrt(dot(normal.xz,normal.xz) + .04);
	vec4 gullyGradient = vec4(0.), rillGradient = vec4(0.);
	if(gullyFade > 0.) {
		gullyGradient = ribSample(pos, sizes.x, crossSlope);
		// Patchy weathering interrupts the cuts. Carry its gradient into the
		// lighting as well, so exposed flanks and their tints stay together.
		gullyGradient += .14 * blotchFade * vec4(blotchGradient.x, blotchGradient.y/31., 0., blotchGradient.z/31.);
	}
	if(rillFade > 0.) rillGradient = ribSample(pos + 71.3, 12., crossSlope);
	float gullyNoise = gullyGradient.x, blotchNoise = blotchGradient.x;
	float gully = gullyNoise * gullyFade, rill = rillGradient.x * rillFade;
	float blotch = blotchNoise * blotchFade;
	float streak = (cliffNoise(facePosition - 23.9, vec2(5.0, 21.0), xWeight) - 0.5) * spread * streakFade;
	// Stands follow the hillside for about a hundred meters. Independently oriented
	// clumps and tufts break up their margins while each is large enough to resolve.
	float standFade = coverBand(footprint,105.);
	float stand = 0., clump = 0., tuft = 0.;
	if(standFade > 0.) stand = standFade*coverNoise(pos.xz/105. + vec2(22.1,-7.3)).x;
	if(clumpFade > 0.) clump = clumpFade*coverNoise(mat2(.8,.6,-.6,.8)*pos.xz/17. + vec2(-21.4,8.7) + vec2(1.4,-.8)*stand).x;
	if(tuftFade > 0.) tuft = tuftFade*coverNoise(mat2(.6,-.8,.8,.6)*pos.xz/4.6 + vec2(5.2,9.7) + vec2(-.6,1.2)*clump).x;
	float ragged = .72*clump + .32*tuft, raggedDetail = max(clumpFade, tuftFade);
	// The two sides of a cut share the same weathering. Recenter their unsigned
	// variation as it fades to keep the treeline stable. Soil takes over on gentle ground.
	float upright = smoothstep(.035, .20, slope);
	float bottom = 0.01 + 0.012*footprint;
	float cut = sqrt(gully*gully + bottom*bottom);
	float rib = mix(blotch, 1.5*(cut - sqrt(.0243*gullyFade*gullyFade + bottom*bottom)) + .35*rill, upright);

	// Height above the snowline, raised on ribs and lowered in gullies. The broad variation keeps distant boundaries,
	// where the finer noise has faded, off the contours.
	float above = pos.y - snowline + 60.*rib + 64.*stand + 24.*blotch + 70.*macro;
	// GLSL leaves smoothstep() undefined when its first edge is not below the second, so the fall is written as a rise.
	float lowland = 1.0 - smoothstep(uMountainZones.x - 55.0, uMountainZones.x + 52.0, above);
	// Soil holds on steeper ground low down.
	float steep = smoothstep(uSlopeBlend.x, uSlopeBlend.y, slope + 0.15*rib - 0.07*lowland);

	// The forest crown silhouette supplies density and tilt to the close foliage.
	vec2 grid = mat2(0.8,0.6,-0.6,0.8) * pos.xz / sizes.y;
	vec2 cell = floor(grid);
	float seed = hashCell(cell), seed2 = hashCell(cell + 41.7);
	vec2 offset = fract(grid) - 0.5 - 0.25*(vec2(seed, seed2) - 0.5);
	float turn = 6.2832 * seed2;
	offset = mat2(cos(turn), sin(turn), -sin(turn), cos(turn)) * offset;
	// Fade individual crowns before a cell spans three pixels.
	float pixel = max(footprint, 0.001), aa = footprint / sizes.y, cellFade = 1.0 - smoothstep(0.4, 1.0, 3.*aa);
	// Fade a crown's silhouette over a pixel where it reaches the edge of its cell.
	vec2 inside = min(fract(grid), 1.0 - fract(grid));
	float within = smoothstep(0.0, pixel / sizes.y, min(inside.x, inside.y));
	// From far away each crown has a single silhouette. Up close it sets the density of
	// foliage, with leaves and gaps both inside the crown and between adjacent crowns.
	float silhouette = (1.0 - smoothstep(0.2, 0.34 + 0.2*fract(seed * 7.7) + aa, length(offset) + 0.25*tuft)) * within;
	float close = 1.0 - smoothstep(.10, .25, aa);
	float leaves = smoothstep(-.36, .4, .24*(silhouette-.5) + .62*tuft - stipple);
	float crown = mix(silhouette, leaves, close);
	// Only the silhouette carries a cell's own tint and tilt. Both vanish at its edge.
	float crowns = mix(1.0, (.7 + .55*crown + .2*(seed2-.5)*silhouette) / .89, cellFade);
	float crownRelief = silhouette * mix(1.0, .3 + .7*leaves, close);

	// Coarse outcrops bend the smaller fragments. A rounded crease in their
	// height gives the scree uneven facets, with grains of finer rubble between them.
	vec3 stonePosition = MATERIAL_TURN * pos / sizes.y;
	float boulderFade = 1.0 - smoothstep(.4,1.,aa);
	vec4 boulderSample = vec4(0.), chipSample = vec4(0.);
	const vec3 chipWarp = vec3(.86,-.65,.47);
	vec3 chips = MATERIAL_TURN*stonePosition;
	float fragmentFade = 1.-smoothstep(.18,.5,2.4*aa);
	if(boulderFade > 0.) boulderSample = materialVolumeSample(stonePosition/2.7 + vec3(11.3,5.1,17.3));
	float outcrop = boulderSample.x;
	if(cellFade > 0.) chipSample = materialVolumeSample(chips + chipWarp*outcrop);
	float rubbleNoise = chipSample.x, fragments = 0.;
	if(fragmentFade > 0.) fragments = materialVolumeNoise(2.4*MATERIAL_TURN*chips + vec3(-6.3,4.7,12.1) + .45*rubbleNoise);
	float crease = rubbleNoise - .32*outcrop;
	float rounded = sqrt(crease*crease+.014);
	float chip = .66*rubbleNoise + .25*outcrop - .38*(rounded-.30);
	float blocks = (1. + .26*chip*cellFade + .18*fragments*fragmentFade) * (1. - .23*stipple);
	float boulders = 1. + .22*outcrop*boulderFade;
	// Differentiate in world space. Screen differences on an oblique face can cross
	// a projected stone in a single pixel and leave a dotted line of shaded quads.
	// Use the geometric grade before tilting the lighting normal. As in the height
	// generator, damping is proportional to grade squared. At the recipe slope scale
	// and damping 1, an 8 m feature keeps half its gradient and wider forms keep more.
	float grade2 = dot(normal.xz,normal.xz) / max(normal.y*normal.y,1e-8);
	float smoothingWidth2 = 8.*8.*uRockSlopeDamping*grade2;
	vec3 outcropSlope = boulderSample.yzw*MATERIAL_TURN/2.7;
	vec3 rubbleSlope = chipSample.yzw*MATERIAL_TURN*MATERIAL_TURN + dot(chipSample.yzw,chipWarp)*outcropSlope;
	vec3 chipSlope = .66*rubbleSlope + .25*outcropSlope - .38*(crease/rounded)*(rubbleSlope-.32*outcropSlope);
	vec3 blockSlope = .13*rockReliefWeight(sizes.y,smoothingWidth2)*chipSlope*cellFade
		+ .17*rockReliefWeight(2.7*sizes.y,smoothingWidth2)*outcropSlope*boulderFade;
	blockSlope -= normal*dot(normal,blockSlope);
	vec3 blockTilt = -blockSlope/(1.+length(blockSlope));

	// Shrubs mottle the meadow, tundra keeps meadow green in its hollows, and wet grass fills level floors. Shrubs
	// blend into the meadow over a few meters rather than ending at a line, and they fill most openings in the forest.
	// Shrub crowns show more faintly than tree crowns. Ledges on steep ground lie in the shade of the rock around them.
	float shrubs = max(coverFraction(.4+.3*steep, 1.4*ragged+.6*stand+.3*blotch), .7*lowland);
	float level = 1.0 - smoothstep(.008, .055, slope + .024*stand + .012*blotch);
	// Forest ends in clumps of single crowns, and avalanches keep chutes open down the gullies of steeper slopes.
	float chutes = min(rib, 0.0) * smoothstep(0.1, 0.2, slope) * upright;
	float trees = coverFraction(lowland * (1.-smoothstep(.16,.36,slope+.2*rib)) * (1.-level),
		.95*stand + 1.05*ragged + .36*blotch + 1.2*chutes + .3*(crown-.5)*cellFade);
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
	float rubbleCover = .30*rubbleNoise*cellFade + .18*outcrop*boulderFade + .12*fragments*fragmentFade;
	float scree = coverFraction(talusShare, .8*rill - 1.5*gully + .65*ragged + 1.4*rubbleCover + 1.3*macro);
	// One family of granite fractures follows the face down, the other cuts across
	// the dip. Sample through the rock so both continue around a change of aspect.
	vec2 strike = mat2(.8,.6,-.6,.8)*pos.xz;
	vec3 bedrock = vec3(strike.x/sizes.z,(pos.y+.26*strike.x-.13*strike.y)/sizes.w,strike.y/sizes.z);
	float slabWidth = min(sizes.z,sizes.w);
	float jointPixel = footprint/slabWidth;
	float jointFade = 1.-smoothstep(.035,.12,jointPixel), slabFade = 1.-smoothstep(.12,.35,jointPixel);
	float split = 0., crosscut = 0.;
	if(slabFade > 0.) {
		split = materialVolumeNoise(bedrock*vec3(1.,.48,1.21) + vec3(17.4,2.9,-9.3));
		vec2 across = mat2(.6,-.8,.8,.6)*bedrock.xz;
		crosscut = materialVolumeNoise(vec3(.55*across.x,1.4*bedrock.y+.37*split,.62*across.y));
	}
	float chipOffset = .014*abs(rubbleNoise+.45*outcrop)*cellFade;
	float downDip = .85*bedrock.x + .32*bedrock.z + .19*bedrock.y + .14*abs(split+.4*crosscut) + chipOffset;
	float acrossDip = bedrock.y - .28*bedrock.x + .21*bedrock.z + .17*abs(crosscut-.3*split) - chipOffset;
	vec2 longitudinal = fractureProfile(downDip,73);
	vec2 transverse = fractureProfile(acrossDip,197);
	longitudinal *= smoothstep(-.25,.32,crosscut + blotchNoise + .6*rill + .22*ragged);
	transverse *= smoothstep(-.08,.46,split - blotchNoise - .5*rill - .22*ragged);
	float joints = mix(.035,max(longitudinal.x,transverse.x),jointFade);
	float slabTone = (.65*split + .35*crosscut)*slabFade;
	vec3 slabTilt = patternGradient(pos,normal,.075*sizes.z*split+.045*sizes.w*crosscut);
	// A fracture's two flanks occupy 13 percent of the joint spacing.
	vec3 jointTilt = rockReliefWeight(.13*sizes.z,smoothingWidth2)*.08*longitudinal.y*patternGradient(pos,normal,sizes.z*downDip)
		+ rockReliefWeight(.13*sizes.w,smoothingWidth2)*.06*transverse.y*patternGradient(pos,normal,sizes.w*acrossDip);
	vec3 facet = rockReliefWeight(slabWidth,smoothingWidth2)*slabFade*slabTilt + jointFade*jointTilt;
	// Water leaves short dark trails down the faces.
	float wet = patchy(0.25, streak + 0.3*rill, max(streakFade, rillFade));
	float tone = (1.0 + .17*slabTone) * (1.0 - 0.20*joints) * (1.0 - 0.11*wet)
		* (1.0 + 0.12*blotch + 0.1*macro) * hollows;
	// Iron stains broad sheets of the faces and runs down them in streaks; lichen tints the rock gray-green in patches.
	float sheets = coverFraction(.6*uMountainAmounts.y, 1.5*blotch - 1.6*gully + .7*slabTone + .42*rill + .22*ragged);
	float lichen = uMountainAmounts.z * patchy(0.55, blotch + ragged + 0.26*streak, max(raggedDetail, blotchFade));
	// Bare rock covers the steep ground and, nearing the snowline, the ridges. Rock follows the ribs and vegetation the
	// gullies between them. Gentle bare ground is a block field.
	float alpine = smoothstep(uHeightBlend.z, uHeightBlend.w, above);
	float bare = coverFraction(max(steep,alpine), .65*stand + 1.1*rib + .8*ragged);

	float groundShade = hollows * (1.0 - .30*stipple);
	float meadowShade = (1.0 + .2*clump + .2*rill) * (1.0 - .25*steep) * groundShade;
	float tundraCover = coverFraction(smoothstep(uMountainZones.y-40.,uMountainZones.y+40.,above),
		stand + .75*ragged + .35*rill);
	float cliffFraction = mix(.4, 1., smoothstep(.05,.16,slope));
	float cliffCover = bare * cliffFraction;
	float stainCover = cliffCover * sheets * clamp(.5+.42*streak+.4*rill,0.,.85);
	// Convert the nested rubble / cliff / stain mixture to ordered source-over coverages.
	float blockCover = bare*(1.-cliffFraction)/max(.000001,1.-cliffCover);
	float graniteCover = (cliffCover-stainCover)/max(.000001,1.-stainCover);
	vec3 lichenTint = mix(vec3(1.), vec3(.88,.94,.84)-.22*stipple,lichen);
	vec3 jointTint = tone*lichenTint*(1.0 - .18*stipple);
	float shore = (1.-smoothstep(water+uHeightBlend.x,water+uHeightBlend.y,pos.y))*normal.y;
	float snow = smoothstep(snowline,snowline+uSnowline.y,pos.y)*(1.-smoothstep(uSlopeBlend.z,uSlopeBlend.w,slope));
	float relief = (1.0 - smoothstep(snowline, snowline + uSnowline.y, pos.y)
		* (1.0 - smoothstep(uSlopeBlend.z, uSlopeBlend.w, slope)))
		* smoothstep(0.06, 0.25, abs(dot(normalize(relative), normal))) * (1.0 - smoothstep(3.0, 10.0, footprint));
	// A cut is deepest on its centerline and meets the surrounding face at zero slope.
	// Round the bottom over at least a pixel and a half across the cut. The footprint
	// measured along an edge-on face can be much longer than a pixel across it.
	float viewDistance = length(relative);
	vec3 gullySlope = gullyGradient.yzw;
	float gullyPixel = abs(dot(gullySlope, dFdx(pos))) + abs(dot(gullySlope, dFdy(pos)));
	float groove = sqrt(gullyNoise*gullyNoise + pow(max(0.01 + 0.00004*viewDistance, 1.5*gullyPixel), 2.0));
	const float cutWidth = .17;
	float flank = max(0., 1.-groove/cutWidth);
	float depth = 1.1 + .8*blotchNoise;
	vec3 ribSlope = (2.*depth/cutWidth)*flank*(gullyNoise/groove)*gullySlope
		- (.8/31.)*flank*flank*vec3(blotchGradient.y,0.,blotchGradient.z);
	float rills = .7 * (1.-smoothstep(2.4,6.,footprint)) * (1.0 - smoothstep(200.0, 700.0, viewDistance));
	// Narrow flanks lose lighting contrast before their broad color pattern disappears.
	float ribRelief = gullyFade*(1.-.65*smoothstep(.025,.13,footprint/sizes.x));
	vec3 lift = upright*(rockReliefWeight(sizes.x,smoothingWidth2)*ribRelief*ribSlope
		+ rockReliefWeight(12.,smoothingWidth2)*rills*rillGradient.yzw);
