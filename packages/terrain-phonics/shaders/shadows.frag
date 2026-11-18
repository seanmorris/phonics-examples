#version 300 es
precision highp float;
precision highp int;
uniform sampler2D uTerrainHeights;
uniform float uTerrainShadowExtent;
uniform float uTerrainShadowSize;
uniform vec3 uShadowSunDirection;
out vec4 color;

float coarseHeight(vec2 position)
{
	vec2 pixel = clamp(position / uTerrainShadowExtent + 0.5, 0.0, 1.0) * 32.0;
	ivec2 low = ivec2(floor(pixel));
	ivec2 high = min(low + ivec2(1), ivec2(32));
	vec2 weight = fract(pixel);
	float a = mix(texelFetch(uTerrainHeights, low, 0).r,
		texelFetch(uTerrainHeights, ivec2(high.x, low.y), 0).r, weight.x);
	float b = mix(texelFetch(uTerrainHeights, ivec2(low.x, high.y), 0).r,
		texelFetch(uTerrainHeights, high, 0).r, weight.x);
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
	vec3 origin = vec3(position.x, coarseHeight(position) + 3.0, position.y);
	float visibility = 1.0;
	float spacing = uTerrainShadowExtent / 32.0;
	// This bounded coarse march resolves broad mountains, not small objects or local relief.
	for(int i = 1; i <= 32; ++i)
	{
		float distanceAlong = spacing * float(i);
		vec3 probe = origin + uShadowSunDirection * distanceAlong;
		if(any(greaterThan(abs(probe.xz), vec2(uTerrainShadowExtent * 0.5)))) break;
		float difference = probe.y - coarseHeight(probe.xz);
		// Fade only below an occluder: an unobstructed flat plane must remain fully sunlit at low sun angles.
		visibility = min(visibility, smoothstep(-max(2.0, distanceAlong * 0.004675), 0.0, difference));
		if(visibility <= 0.0) break;
	}
	color = vec4(vec3(visibility), 1.0);
}
