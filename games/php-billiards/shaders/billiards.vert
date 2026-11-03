#version 300 es
layout(location=0) in vec3 aPosition;
layout(location=1) in vec3 aNormal;
layout(location=2) in vec2 aUv;
// Instanced draws supply a column-major model matrix, tint, and ball-skin layer per instance.
layout(location=3) in vec4 aModel0;
layout(location=4) in vec4 aModel1;
layout(location=5) in vec4 aModel2;
layout(location=6) in vec4 aModel3;
layout(location=7) in vec4 aTint;
layout(location=8) in float aLayer;
uniform bool uInstanced;
uniform mat4 uModel;
uniform mat4 uViewProjection;
uniform mat3 uNormal;
out vec3 vPosition;
out vec3 vNormal;
out vec2 vUv;
out vec4 vTint;
flat out float vLayer;
void main()
{
	mat4 model=uInstanced?mat4(aModel0,aModel1,aModel2,aModel3):uModel;
	vec4 world=model*vec4(aPosition,1.0);
	vPosition=world.xyz;
	// Instanced meshes are uniformly scaled or unlit, so the model's upper 3x3 orients their normals.
	vNormal=normalize((uInstanced?mat3(model):uNormal)*aNormal);
	vUv=aUv;
	vTint=aTint;
	vLayer=aLayer;
	gl_Position=uViewProjection*world;
}
