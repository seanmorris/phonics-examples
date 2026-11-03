#version 300 es
precision highp float;
precision highp int;

uniform sampler2D indexedAtlas;
uniform sampler2D atlasRects;
uniform sampler2D paletteMap;
uniform int paletteRows;
uniform int palettePage;
uniform int fixedLightMap;
uniform int skyTexture;
uniform float cameraAngle;
uniform float cameraPitch;
uniform vec2 projection;
uniform int pitchMode;

in vec2 screenPosition;
out vec4 color;
uniform vec4 viewBlend;

void main()
{
	vec4 rectangle = texelFetch(atlasRects, ivec2(skyTexture, 0), 0);
	ivec2 origin = ivec2(round(rectangle.xy));
	ivec2 size = max(ivec2(1), ivec2(round(rectangle.zw)));
	float viewRight = screenPosition.x / projection.x;
	float viewUp = screenPosition.y / projection.y;
	float pitchCos = cos(cameraPitch);
	float pitchSin = sin(cameraPitch);
	float worldForward = pitchCos - viewUp * pitchSin;
	float worldUp = pitchSin + viewUp * pitchCos;
	float horizontalLength = length(vec2(worldForward, viewRight));
	float yaw = cameraAngle - atan(viewRight, worldForward);
	float elevation = atan(worldUp, horizontalLength);
	float halfVerticalFov = atan(1.0 / projection.y);
	// Four texture repeats form the panorama. Its .85 vertical span now follows the
	// rotated view ray, so mouse look tilts the camera rather than shearing the image.
	float perspectiveV = .725 - elevation / halfVerticalFov * .425;
	float classicV = .725 - (screenPosition.y + tan(cameraPitch) * projection.y) * .425;
	float sourceV = clamp(pitchMode == 1 ? classicV : perspectiveV, 0.0, .99999);
	if(pitchMode == 1) yaw = cameraAngle - atan(viewRight);
	int x = int(floor(fract(yaw * 4.0 / 6.28318530718) * float(size.x)));
	int y = int(floor(sourceV * float(size.y)));
	vec2 indexed = texelFetch(indexedAtlas, origin + ivec2(x, y), 0).rg;
	int sourceIndex = int(round(indexed.r * 255.0));
	int colorMap = fixedLightMap >= 0 ? fixedLightMap : 0;
	color = texelFetch(paletteMap, ivec2(sourceIndex, palettePage * paletteRows + colorMap), 0);
	color.rgb = mix(color.rgb, viewBlend.rgb, viewBlend.a);
}
