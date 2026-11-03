#version 300 es
precision highp float;

uniform sampler2D portalView;
uniform vec2 viewport;
out vec4 color;

void main()
{
	if(!gl_FrontFacing) discard;
	// Reflecting the camera reverses its handedness. The world renderer derives a
	// conventional right vector from the reflected heading, so its capture is
	// horizontally opposite the basis a physical mirror presents to this view.
	vec2 coordinate = gl_FragCoord.xy / viewport;
	coordinate.x = 1.0 - coordinate.x;
	color = texture(portalView, coordinate);
}
