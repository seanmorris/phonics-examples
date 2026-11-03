#version 300 es
precision highp float;

in float across;
out vec4 color;
uniform vec4 viewBlend;

void main()
{
	float coverage = 1.0 - smoothstep(.72, 1.0, abs(across));
	color = vec4(1.0, 1.0, 1.0, coverage);
	color.rgb = mix(color.rgb, viewBlend.rgb, viewBlend.a);
}
