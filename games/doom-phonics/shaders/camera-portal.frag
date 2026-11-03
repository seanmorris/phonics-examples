#version 300 es
precision highp float;

uniform sampler2D portalView;
uniform vec2 viewport;
uniform bool portalReady;
out vec4 color;

void main()
{
	vec2 coordinate = gl_FragCoord.xy / viewport;
	color = portalReady ? texture(portalView, coordinate) : vec4(0.0, 0.0, 0.0, 1.0);
}
