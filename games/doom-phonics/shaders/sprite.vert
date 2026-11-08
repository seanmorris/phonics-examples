#version 300 es
precision highp float;
// One instance per sprite. The quad's six corners are expanded here along the camera's right vector, so the CPU
// uploads 13 floats per sprite instead of six full vertices.
layout(location=0) in vec2 aCenter;
// Left and right edges along the camera's right vector, then bottom and top heights.
layout(location=1) in vec4 aExtent;
// U at the left and right edges, V at the bottom and top.
layout(location=2) in vec4 aUv;
// Atlas texture, light level, opacity, and style mode (0 normal, 1 additive, 3 shaded).
layout(location=4) in float aStencil;
layout(location=3) in vec4 aStyle;

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
flat out int styleMode;
flat out vec3 stencil;

void main()
{
	// Corners in triangle order: left-bottom, right-bottom, right-top, left-bottom, right-top, left-top.
	int corner = gl_VertexID % 6;
	bool isRight = corner == 1 || corner == 2 || corner == 4;
	bool isTop = corner == 2 || corner == 4 || corner == 5;
	// Match the map projection: screen-right is clockwise in WAD space.
	vec2 right = vec2(forward.y, -forward.x);
	vec3 position = vec3(aCenter + right * (isRight ? aExtent.y : aExtent.x), isTop ? aExtent.w : aExtent.z);
	vec2 relative = position.xy - camera.xy;
	float horizontalDepth = dot(relative, forward);
	float side = dot(relative, right);
	float height = position.z - camera.z;
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
	textureUv = vec2(isRight ? aUv.y : aUv.x, isTop ? aUv.w : aUv.z);
	viewDepth = depth;
	textureId = int(round(aStyle.x));
	lightLevel = int(round(aStyle.y));
	opacity = aStyle.z;
	styleMode = int(round(aStyle.w));
	// The stencil colour arrives packed as r * 65536 + g * 256 + b, exact in a float.
	stencil = vec3(floor(aStencil / 65536.0), floor(mod(aStencil, 65536.0) / 256.0), mod(aStencil, 256.0)) / 255.0;
}
