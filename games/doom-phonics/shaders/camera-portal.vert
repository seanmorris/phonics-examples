#version 300 es
precision highp float;
layout(location=0) in vec2 aPosition;
layout(location=1) in vec2 aHeight;
layout(location=2) in float aOtherSector;

uniform sampler2D sectorHeights;
uniform vec3 camera;
uniform vec2 forward;
uniform vec2 projection;
uniform float cameraPitch;
uniform float nearPlane;
uniform int pitchMode;

float sectorPlaneHeight(int sector, int plane)
{
	vec2 heights = texelFetch(sectorHeights, ivec2(sector, 0), 0).rg;
	vec3 slope = texelFetch(sectorHeights, ivec2(sector, plane + 1), 0).xyz;
	return heights[plane] + dot(slope, vec3(aPosition, 1.0));
}

float planeHeight(int sector, int selector)
{
	if(selector < 2) return sectorPlaneHeight(sector, selector);
	int plane = (selector - 2) / 2;
	float frontHeight = sectorPlaneHeight(sector, plane);
	float backHeight = sectorPlaneHeight(int(aOtherSector + .5), plane);
	return selector % 2 == 0 ? min(frontHeight, backHeight) : max(frontHeight, backHeight);
}

void main()
{
	float worldZ = planeHeight(int(aHeight.x + .5), int(aHeight.y + .5));
	vec2 relative = aPosition - camera.xy;
	vec2 right = vec2(forward.y, -forward.x);
	float horizontalDepth = dot(relative, forward);
	float side = dot(relative, right);
	float height = worldZ - camera.z;
	float pitchCos = cos(cameraPitch);
	float pitchSin = sin(cameraPitch);
	bool classicPitch = pitchMode == 1;
	float depth = classicPitch ? horizontalDepth : horizontalDepth * pitchCos + height * pitchSin;
	float vertical = classicPitch ? height - tan(cameraPitch) * horizontalDepth
		: height * pitchCos - horizontalDepth * pitchSin;
	float farPlane = 16384.0;
	float clipZ = ((farPlane + nearPlane) / (farPlane - nearPlane)) * depth
		- (2.0 * farPlane * nearPlane / (farPlane - nearPlane));
	gl_Position = vec4(side * projection.x, vertical * projection.y, clipZ, depth);
}
