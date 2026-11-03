#version 300 es
precision highp float;
uniform sampler2D scene;
in vec2 textureUv;
out vec4 color;

void main()
{
	color = texture(scene, textureUv);
}
