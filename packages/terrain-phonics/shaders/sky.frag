#version 300 es
precision highp float;
precision highp int;
// ATMOSPHERE
in vec2 vScreen;
uniform vec3 uForward, uRight, uUp, uEye;
uniform vec2 uScreen;
out vec4 color;
// One digit of an 8×8 Bayer threshold. The fixed, zero-mean pattern survives pause and replay.
int bayerDigit(ivec2 p)
{
	return 2 * ((p.x ^ p.y) & 1) + (p.y & 1);
}

void main()
{
	vec3 ray = normalize(uForward + uRight * vScreen.x * uScreen.x * uScreen.y + uUp * vScreen.y * uScreen.y);
	vec3 display = terrainToneMap(skyRadiance(ray, uEye.y));
	if(uSunDirection.y < 0.0) {
		ivec2 p = ivec2(gl_FragCoord.xy);
		int rank = 16 * bayerDigit(p) + 4 * bayerDigit(p >> 1) + bayerDigit(p >> 2);
		float threshold = (float(rank) + 0.5) / 64.0 - 0.5;
		display += threshold * smoothstep(0.0, 0.01745, -uSunDirection.y) / 255.0;
	}
	color = vec4(display, 1.0);
}
