#version 300 es
precision highp float;
layout(location=0) in vec3 aPosition;
layout(location=1) in vec2 aUv;
layout(location=2) in float aTexture;
layout(location=3) in float aLight;
layout(location=4) in float aOpacity;

uniform vec3 camera;
uniform vec2 forward;
uniform vec2 projection;
uniform float cameraPitch;
uniform float nearPlane;
uniform int pitchMode;

out vec2 textureUv;
out float viewDepth;
flat out int textureId;
flat out int lightLevel;
flat out float opacity;

void main()
{
	vec2 relative = aPosition.xy - camera.xy;
	// Match the map projection: screen-right is clockwise in WAD space.
	vec2 right = vec2(forward.y, -forward.x);
	float horizontalDepth = dot(relative, forward);
	float side = dot(relative, right);
	float height = aPosition.z - camera.z;
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
	textureUv = aUv;
	viewDepth = depth;
	textureId = int(round(aTexture));
	lightLevel = int(round(aLight));
	opacity = aOpacity;
}
