#version 300 es
precision highp float;
layout(location=0) in vec2 aPosition;
layout(location=1) in vec2 aU;
layout(location=2) in vec2 aTextures;
layout(location=3) in vec2 aHeight;
layout(location=4) in vec3 aRightAnchor;
layout(location=5) in vec3 aLeftAnchor;
layout(location=6) in vec2 aSideSectors;

uniform sampler2D sectorHeights;
uniform vec3 camera;
uniform vec2 forward;
uniform vec2 projection;
uniform float cameraPitch;
uniform float nearPlane;
uniform int pitchMode;
// Bits 0/1 clip floor/ceiling; bits 2/3 include contact with the arrival portal.
uniform int clipSectorBounds;
uniform bool volumeProjected;
uniform float volumeHeightOffset;

out vec4 textureUvs;
out float viewDepth;
out vec2 sectorClearance;
flat out ivec2 textureIds;
flat out ivec2 lightLevels;
flat out ivec2 sectorComponents;

float sectorPlaneHeightAt(int sector, int plane, vec2 position)
{
	vec2 heights = texelFetch(sectorHeights, ivec2(sector, 0), 0).rg;
	vec3 slope = texelFetch(sectorHeights, ivec2(sector, plane + 1), 0).xyz;
	return heights[plane] + dot(slope, vec3(position, 1.0));
}

float sectorPlaneHeight(int sector, int plane)
{
	return sectorPlaneHeightAt(sector, plane, aPosition);
}

float planeHeight(int sector, int selector)
{
	if(selector < 2) return sectorPlaneHeight(sector, selector);
	// MapMesh MIN/MAX_FLOOR = 2/3, MIN/MAX_CEILING = 4/5. Resolve the
	// ordering each frame so initially flush or crossing sectors keep their walls.
	int plane = (selector - 2) / 2;
	float rightHeight = sectorPlaneHeight(int(round(aSideSectors.x)), plane);
	float leftHeight = sectorPlaneHeight(int(round(aSideSectors.y)), plane);
	return selector % 2 == 0 ? min(rightHeight, leftHeight) : max(rightHeight, leftHeight);
}

float textureV(vec3 anchor, float worldZ)
{
	if(anchor.x < 0.0) return anchor.z;
	return planeHeight(int(anchor.x + .5), int(anchor.y + .5)) + anchor.z - worldZ;
}

void main()
{
	int plane = int(aHeight.y + .5);
	float worldZ = planeHeight(int(aHeight.x + .5), plane);
	if(volumeProjected)
		worldZ = sectorPlaneHeightAt(int(aHeight.x + .5), plane, vec2(aU.x, -aRightAnchor.z)) + volumeHeightOffset;
	vec2 relative = aPosition - camera.xy;
	// WAD +Y is north, so screen-right is the clockwise perpendicular.
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
	textureUvs = vec4(aU.x, textureV(aRightAnchor, worldZ), aU.y, textureV(aLeftAnchor, worldZ));
	viewDepth = depth;
	textureIds = ivec2(round(aTextures));
	int rightSector = int(round(aSideSectors.x));
	int leftSector = int(round(aSideSectors.y));
	// Interpolate signed plane distances so sloped and moving target boundaries
	// also clip partially buried faces. Real sector surfaces own exact contact.
	sectorClearance = vec2(
		(clipSectorBounds & 1) != 0 ? worldZ - sectorPlaneHeight(rightSector, 0) + ((clipSectorBounds & 4) != 0 ? .002 : 0.0) : 1.0,
		(clipSectorBounds & 2) != 0 ? sectorPlaneHeight(rightSector, 1) - worldZ + ((clipSectorBounds & 8) != 0 ? .002 : 0.0) : 1.0
	);
	sectorComponents = ivec2(
		int(round(texelFetch(sectorHeights, ivec2(rightSector, 0), 0).a)),
		int(round(texelFetch(sectorHeights, ivec2(leftSector, 0), 0).a))
	);
	if(aRightAnchor.x < 0.0 && aLeftAnchor.x < 0.0)
	{
		rightSector = int(texelFetch(sectorHeights, ivec2(rightSector, plane + 1), 0).w + .5);
		leftSector = int(texelFetch(sectorHeights, ivec2(leftSector, plane + 1), 0).w + .5);
	}
	lightLevels = ivec2(
		int(round(texelFetch(sectorHeights, ivec2(rightSector, 0), 0).b)),
		int(round(texelFetch(sectorHeights, ivec2(leftSector, 0), 0).b))
	);
}
