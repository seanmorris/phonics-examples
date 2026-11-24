#version 300 es
precision highp float;
precision highp int;
// LAYERS
// MATERIAL NOISE
uniform vec2 uCellOffset;
uniform float uStep;
uniform vec4 uRegions[3]; // local origin XZ, seed XY
layout(location=0) out vec4 west;
layout(location=1) out vec4 east;
layout(location=2) out vec4 fork;
layout(location=3) out vec4 scatter;
vec4 bakeRegion(vec2 world, vec4 region) {
	vec2 local = world-region.xy;
	return vec4(macroBands(local),.5+.5*grassStand(local,ivec2(region.zw)));
}
void main() {
	vec2 world=(floor(gl_FragCoord.xy)+uCellOffset)*uStep;
	west=bakeRegion(world,uRegions[0]);
	east=bakeRegion(world,uRegions[1]);
	fork=bakeRegion(world,uRegions[2]);
	scatter=.5+.5*scatterBands(world);
}
