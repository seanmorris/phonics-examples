#version 300 es
precision highp float;
layout(location=0) in vec2 aPosition;
layout(location=1) in float aSector;
layout(location=2) in float aUpperExtent;

uniform sampler2D sectorHeights;
uniform vec3 camera;
uniform vec2 forward;
uniform vec2 projection;
uniform float cameraPitch;
uniform float nearPlane;
uniform int pitchMode;
uniform int cameraComponent;

void main()
{
	int sector = int(aSector + .5);
	vec4 sectorData = texelFetch(sectorHeights, ivec2(sector, 0), 0);
	if(int(round(sectorData.a)) != cameraComponent)
	{
		gl_Position = vec4(2.0, 2.0, 2.0, 1.0);
		return;
	}
	float ceiling = sectorData.g;
	ceiling += dot(texelFetch(sectorHeights, ivec2(sector, 2), 0).xyz, vec3(aPosition, 1.0));
	float worldZ = ceiling + aUpperExtent * 16384.0;
	vec2 relative = aPosition - camera.xy;
	vec2 right = vec2(forward.y, -forward.x);
	float horizontalDepth = dot(relative, forward);
	float side = dot(relative, right);
	float height = worldZ - camera.z;
	float pitchCos = cos(cameraPitch);
	float pitchSin = sin(cameraPitch);
	bool classicPitch = pitchMode == 1;
	float depth = classicPitch ? horizontalDepth
		: horizontalDepth * pitchCos + height * pitchSin;
	float vertical = classicPitch ? height - tan(cameraPitch) * horizontalDepth
		: height * pitchCos - horizontalDepth * pitchSin;
	float farPlane = 16384.0;
	float clipZ = ((farPlane + nearPlane) / (farPlane - nearPlane)) * depth
		- (2.0 * farPlane * nearPlane / (farPlane - nearPlane));
	gl_Position = vec4(side * projection.x, vertical * projection.y, clipZ, depth);
}
