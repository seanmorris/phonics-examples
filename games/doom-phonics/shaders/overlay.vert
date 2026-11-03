#version 300 es
precision highp float;
layout(location=0) in vec2 aPosition;
layout(location=1) in vec2 aUv;
layout(location=2) in float aTexture;
layout(location=3) in float aOpacity;
layout(location=4) in float aFixedColormap;

uniform vec2 viewport;
out vec2 textureUv;
flat out int textureId;
flat out float opacity;
flat out int useFixedColormap;

void main()
{
	vec2 clip = vec2(aPosition.x / viewport.x * 2.0 - 1.0, 1.0 - aPosition.y / viewport.y * 2.0);
	gl_Position = vec4(clip, 0.0, 1.0);
	textureUv = aUv;
	textureId = int(round(aTexture));
	opacity = aOpacity;
	useFixedColormap = int(round(aFixedColormap));
}
