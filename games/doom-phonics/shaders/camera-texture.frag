#version 300 es
precision highp float;
precision highp int;

uniform sampler2D cameraView;
uniform vec2 textureSize;
uniform int surfaceSide;
uniform int cameraReady;
uniform int cameraComponent;

in vec4 textureUvs;
flat in ivec2 sectorComponents;
out vec4 color;

void main()
{
	if(cameraReady == 0) { color = vec4(0.0,0.0,0.0,1.0); return; }
	if(surfaceSide == 0 && !gl_FrontFacing) discard;
	if(surfaceSide == 1 && gl_FrontFacing) discard;
	int sectorComponent = gl_FrontFacing ? sectorComponents.x : sectorComponents.y;
	if(sectorComponent != cameraComponent) discard;
	vec2 units = surfaceSide == 1 ? textureUvs.zw : textureUvs.xy;
	vec2 uv = fract(units / max(vec2(1.0), textureSize));
	color = texture(cameraView, vec2(uv.x, 1.0 - uv.y));
}
