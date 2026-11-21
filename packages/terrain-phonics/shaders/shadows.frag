#version 300 es
precision highp float;
precision highp int;
uniform highp sampler2D uTerrainHeights;
uniform bool uPacked;
float sampleHeight(ivec2 c) {
	vec4 t=texelFetch(uTerrainHeights,c,0);
	if(!uPacked) return t.r;
	uvec4 b=uvec4(t*255.+.5);
	return uintBitsToFloat(b.x|(b.y<<8)|(b.z<<16)|(b.w<<24));
}
uniform float uTerrainShadowExtent;
uniform float uTerrainShadowSize;
uniform vec2 uShadowHeightRange; // Minimum terrain elevation and encoded span, in meters.
uniform vec3 uShadowSunDirection;
out vec4 color;

float coarseHeight(vec2 position)
{
	int side=textureSize(uTerrainHeights,0).x-1;
	vec2 pixel = clamp(position / uTerrainShadowExtent + 0.5, 0.0, 1.0) * float(side);
	ivec2 low = ivec2(floor(pixel));
	ivec2 high = min(low + ivec2(1), ivec2(side));
	vec2 weight = fract(pixel);
	float a = mix(sampleHeight(low),
		sampleHeight(ivec2(high.x, low.y)), weight.x);
	float b = mix(sampleHeight(ivec2(low.x, high.y)),
		sampleHeight(high), weight.x);
	return mix(a, b, weight.y);
}

void main()
{
	if(uShadowSunDirection.y <= 0.0)
	{
		color = vec4(0.0, 0.0, 0.0, 1.0);
		return;
	}
	vec2 position = (gl_FragCoord.xy / uTerrainShadowSize - 0.5) * uTerrainShadowExtent;
	float horizontal = length(uShadowSunDirection.xz);
	vec2 direction = uShadowSunDirection.xz / max(horizontal,.00001);
	float grade = uShadowSunDirection.y / max(horizontal,.00001);
	float stride = uTerrainShadowExtent / float(textureSize(uTerrainHeights,0).x - 1);
	float run = stride, ceiling = uShadowHeightRange.x, softness = 2.;
	// Start one overview texel away so a near rim cannot fall between kilometer-long steps.
	// Widen the spacing after the nearby samples to retain coverage to the far side of the map.
	for(int i = 1; i <= 32; ++i)
	{
		if(uShadowHeightRange.x + uShadowHeightRange.y - grade*run <= ceiling) break;
		vec2 probe = position + direction*run;
		if(any(greaterThan(abs(probe), vec2(uTerrainShadowExtent * 0.5)))) break;
		float projected = coarseHeight(probe) - grade*run;
		if(projected > ceiling)
		{
			ceiling = projected;
			softness = max(2.,run*.004675);
		}
		if(i >= 12) stride *= 1.25;
		run += stride;
	}
	float visibility = smoothstep(-softness,0.,coarseHeight(position)+3.-ceiling);
	int code = int(round(clamp((ceiling-uShadowHeightRange.x)/uShadowHeightRange.y,0.,1.)*65535.));
	color = vec4(visibility,vec2(code >> 8,code & 255)/255.,1.);
}
