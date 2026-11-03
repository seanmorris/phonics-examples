#version 300 es
precision highp float;
precision highp int;

uniform sampler2D indexedAtlas;
uniform sampler2D atlasRects;
uniform sampler2D paletteMap;
uniform int lightRows;
uniform int paletteRows;
uniform int palettePage;
uniform int fixedLightMap;
uniform int extraLight;
uniform int cameraComponent;
uniform float surfaceAlpha;
uniform int surfaceLight;
uniform vec4 viewBlend;

in vec4 textureUvs;
in float viewDepth;
in vec2 sectorClearance;
flat in ivec2 textureIds;
flat in ivec2 lightLevels;
flat in ivec2 sectorComponents;
out vec4 color;

void main()
{
	if(min(sectorClearance.x, sectorClearance.y) <= .001) discard;
	vec2 textureUv = gl_FrontFacing ? textureUvs.xy : textureUvs.zw;
	int textureId = gl_FrontFacing ? textureIds.x : textureIds.y;
	int lightLevel = gl_FrontFacing ? lightLevels.x : lightLevels.y;
	if(surfaceLight >= 0) lightLevel = surfaceLight;
	int sectorComponent = gl_FrontFacing ? sectorComponents.x : sectorComponents.y;
	if(sectorComponent != cameraComponent) discard;
	if(textureId < 0) discard;
	vec4 rectValue = texelFetch(atlasRects, ivec2(textureId, 0), 0);
	ivec2 origin = ivec2(round(rectValue.xy));
	ivec2 size = max(ivec2(1), ivec2(round(rectValue.zw)));
	ivec2 local = ivec2(floor(textureUv));
	local = ivec2(mod(vec2(local), vec2(size)) + vec2(size)) % size;
	vec2 indexed = texelFetch(indexedAtlas, origin + local, 0).rg;
	if(indexed.g < .5) discard;
	int sourceIndex = int(round(indexed.r * 255.0));
	int distanceShade = int(max(0.0, floor((viewDepth - 160.0) / 96.0)));
	int effectiveLight = min(255, lightLevel + extraLight);
	int lightMap = clamp(int(round(float(255 - effectiveLight) / 8.0)) + distanceShade, 0, lightRows - 1);
	int colorMap = fixedLightMap >= 0 ? fixedLightMap : lightMap;
	color = texelFetch(paletteMap, ivec2(sourceIndex, palettePage * paletteRows + colorMap), 0);
	color.rgb = mix(color.rgb, viewBlend.rgb, viewBlend.a);
	color.a *= surfaceAlpha;
}
