#version 300 es
precision highp float;
precision highp int;
out vec2 vScreen;
void main()
{
	vec2 p = vec2(float((gl_VertexID << 1) & 2), float(gl_VertexID & 2));
	vScreen = p * 2.0 - 1.0;
	gl_Position = vec4(vScreen, 1.0, 1.0);
}
