#version 300 es
precision highp float;
precision highp int;

uniform sampler2D indexedAtlas;
uniform sampler2D atlasRects;
uniform sampler2D paletteMap;
uniform int paletteRows;
uniform int palettePage;
uniform int fixedLightMap;
in vec2 textureUv;
flat in int textureId;
flat in float opacity;
flat in int useFixedColormap;
out vec4 color;

void main()
{
	vec4 rectangle = texelFetch(atlasRects, ivec2(textureId, 0), 0);
	ivec2 origin = ivec2(round(rectangle.xy));
	ivec2 size = max(ivec2(1), ivec2(round(rectangle.zw)));
	ivec2 local = clamp(ivec2(floor(textureUv)), ivec2(0), size - 1);
	vec2 indexed = texelFetch(indexedAtlas, origin + local, 0).rg;
	if(indexed.g < .5) discard;
	int colorMap = useFixedColormap > 0 && fixedLightMap >= 0 ? fixedLightMap : 0;
	color = texelFetch(
		paletteMap
		, ivec2(int(round(indexed.r * 255.0)), palettePage * paletteRows + colorMap)
		, 0
	);
	color.a *= opacity;
}
