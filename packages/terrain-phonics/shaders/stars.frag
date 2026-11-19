#version 300 es
precision highp float;
in vec3 vColor;
in float vOpacity;
out vec4 color;

void main()
{
	vec2 offset = 2.0 * gl_PointCoord - 1.0;
	float profile = max(0.0, 1.0 - dot(offset,offset));
	color = vec4(vColor, vOpacity * profile * profile * profile);
}
