#version 300 es
precision highp float;
out vec2 textureUv;

void main()
{
	vec2 position = vec2(float((gl_VertexID << 1) & 2), float(gl_VertexID & 2));
	textureUv = position;
	gl_Position = vec4(position * 2.0 - 1.0, 0.0, 1.0);
}
