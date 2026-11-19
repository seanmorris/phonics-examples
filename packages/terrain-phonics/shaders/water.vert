#version 300 es
precision highp float;
precision highp int;
// WATER
layout(location=0) in vec3 aPosition;
uniform mat4 uViewProjection;
uniform vec3 uOrigin, uEye;
out vec3 vRelative, vWorld;
out float vGround;
void main()
{
	vec3 world = aPosition + uOrigin + uEye;
	vGround = world.y;
	world.y = terrainDirectionalSample(world.xz,uWaterTime).x + terrainFrameWakes(world.xz,vec2(0),vec2(0),false).x;
	vWorld = world;
	vRelative = world - uEye;
	gl_Position = uViewProjection * vec4(vRelative,1.0);
}
