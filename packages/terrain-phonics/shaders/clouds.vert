#version 300 es
precision highp float;
uniform mat4 uViewProjection;
uniform vec3 uEye;
uniform vec4 uDeck;
out vec2 vDelta;
void main()
{
	vec2 corner=vec2(float(gl_VertexID&1),float(gl_VertexID>>1))*2.-1.;
	vDelta=corner*60000.;
	gl_Position=uViewProjection*vec4(vDelta.x,uDeck.y-uEye.y,vDelta.y,1.);
}
