// SurfaceLayers::journeyBlock packs one std140 record per landscape. Arrays have 16-byte strides.
struct JourneyPalette {
	vec4 originAndVariation, slopeBlend, heightBlend, snowAndZones, amountsAndDamping;
	vec4 fieldScale, seedAndCount, bounce;
	vec3 colors[16];
	vec4 ranges[16], blends[16], modes[16], effects[16];
	vec2 finishes[16];
	vec4 patterns[16];
	vec3 patternColors[16];
};
layout(std140) uniform JourneyPalettes { JourneyPalette palettes[JOURNEY_REGIONS]; };
uniform float uJoinWidth;
#define PALETTE palettes[materialRegion]
#define uLayerCount int(PALETTE.seedAndCount.z)
#define uPaletteOffset PALETTE.seedAndCount.xy
#define uMacroVariation PALETTE.originAndVariation.w
#define uFieldScale PALETTE.fieldScale
#define uSlopeBlend PALETTE.slopeBlend
#define uHeightBlend PALETTE.heightBlend
#define uSnowline PALETTE.snowAndZones.xy
#define uMountainZones PALETTE.snowAndZones.zw
#define uMountainAmounts PALETTE.amountsAndDamping.xyz
#define uRockSlopeDamping PALETTE.amountsAndDamping.w
#define uLayerColor PALETTE.colors
#define uLayerRange PALETTE.ranges
#define uLayerBlend PALETTE.blends
#define uLayerMode PALETTE.modes
#define uLayerEffect PALETTE.effects
#define uLayerFinish PALETTE.finishes
#define uLayerPattern PALETTE.patterns
#define uLayerPatternColor PALETTE.patternColors
