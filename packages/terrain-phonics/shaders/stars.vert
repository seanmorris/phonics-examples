#version 300 es
precision highp float;
precision highp int;
uniform mat4 uViewProjection;
uniform float uPixelScale, uNightVisibility, uExposure;
out vec3 vColor;
out float vOpacity;

// Each ID is a permanent direction on the celestial hemisphere. Uniform height gives equal solid angle.
float randomUnit(uint value)
{
	value ^= value >> 16u;
	value *= 0x7feb352du;
	value ^= value >> 15u;
	value *= 0x846ca68bu;
	value ^= value >> 16u;
	return float(value >> 8u) / 16777216.0;
}

void main()
{
	uint id = uint(gl_VertexID) * 4u + 1u;
	float azimuth = randomUnit(id) * 6.28318530718;
	float height = randomUnit(id + 1u);
	float radius = sqrt(1.0 - height * height);
	vec3 ray = vec3(radius * cos(azimuth), height, radius * sin(azimuth));
	vec4 clip = uViewProjection * vec4(ray, 0.0);
	gl_Position = vec4(clip.xy, clip.w, clip.w);

	float magnitude = pow(randomUnit(id + 2u), 4.0);
	gl_PointSize = (2.4 + 1.8 * magnitude) * uPixelScale;
	vec3 tint = mix(vec3(0.72,0.82,1.0), vec3(1.0,0.89,0.76), randomUnit(id + 3u));
	vColor = sqrt(1.0 - exp(-uExposure * tint * (0.10 + 2.0 * magnitude)));
	vOpacity = uNightVisibility * smoothstep(0.012,0.19,height);
}
