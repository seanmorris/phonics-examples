#version 300 es
precision highp float;
layout(location=0) in vec3 aFrom;
layout(location=1) in vec3 aTo;
layout(location=2) in float aAlong;
layout(location=3) in float aSide;
layout(location=4) in float aHalfWidth;

uniform vec3 camera;
uniform vec2 forward;
uniform vec2 projection;
uniform vec2 viewport;
uniform float cameraPitch;
uniform float nearPlane;
uniform int pitchMode;

out float across;

vec4 projectPoint(vec3 point)
{
	vec2 relative = point.xy - camera.xy;
	vec2 right = vec2(forward.y, -forward.x);
	float horizontalDepth = dot(relative, forward);
	float side = dot(relative, right);
	float height = point.z - camera.z;
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

	return vec4(side * projection.x, vertical * projection.y, clipZ, depth);
}

void main()
{
	vec4 fromClip = projectPoint(aFrom);
	vec4 toClip = projectPoint(aTo);
	vec2 fromScreen = fromClip.xy / fromClip.w;
	vec2 toScreen = toClip.xy / toClip.w;
	vec2 direction = (toScreen - fromScreen) * viewport;
	vec2 normal = length(direction) > .001 ? normalize(vec2(-direction.y, direction.x)) : vec2(0.0, 1.0);
	vec4 point = mix(fromClip, toClip, aAlong);
	vec2 offset = normal * aSide * aHalfWidth * 2.0 / viewport;
	point.xy += offset * point.w;
	gl_Position = point;
	across = aSide;
}
