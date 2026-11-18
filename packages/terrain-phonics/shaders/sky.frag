#version 300 es
precision highp float;
precision highp int;
// ATMOSPHERE
in vec2 vScreen;
uniform vec3 uForward, uRight, uUp, uEye;
uniform vec2 uScreen;
out vec4 color;
void main()
{
	vec3 ray = normalize(uForward + uRight * vScreen.x * uScreen.x * uScreen.y + uUp * vScreen.y * uScreen.y);
	color = vec4(terrainToneMap(skyRadiance(ray, uEye.y)), 1.0);
}
