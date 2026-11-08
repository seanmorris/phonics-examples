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
// 1 in the depth-writing pass: a pixel is solid or absent. 0 in the blended passes: its alpha is used as it is.
uniform int cutout;

in vec2 textureUv;
in float viewDepth;
flat in int textureId;
flat in int lightLevel;
flat in float opacity;
out vec4 color;
uniform vec4 viewBlend;

void main()
{
	if(textureId < 0) discard;
	vec4 rectValue = texelFetch(atlasRects, ivec2(textureId, 0), 0);
	ivec2 origin = ivec2(round(rectValue.xy));
	ivec2 size = max(ivec2(1), ivec2(round(rectValue.zw)));
	ivec2 local = clamp(ivec2(floor(textureUv)), ivec2(0), size - 1);
	vec2 indexed = texelFetch(indexedAtlas, origin + local, 0).rg;
	float pixelAlpha = cutout == 1 ? step(.5, indexed.g) : indexed.g;
	if(pixelAlpha <= 0.0) discard;
	int sourceIndex = int(round(indexed.r * 255.0));
	int distanceShade = int(max(0.0, floor((viewDepth - 160.0) / 96.0)));
	int effectiveLight = min(255, lightLevel + extraLight);
	int lightMap = clamp(int(round(float(255 - effectiveLight) / 8.0)) + distanceShade, 0, lightRows - 1);
	int colorMap = fixedLightMap >= 0 ? fixedLightMap : lightMap;
	color = texelFetch(paletteMap, ivec2(sourceIndex, palettePage * paletteRows + colorMap), 0);
	color.a *= opacity * pixelAlpha;
	color.rgb = mix(color.rgb, viewBlend.rgb, viewBlend.a);
}
