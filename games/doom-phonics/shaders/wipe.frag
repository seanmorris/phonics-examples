#version 300 es
precision highp float;
uniform sampler2D frameTexture;
uniform sampler2D columnOffsets;
uniform bool melt;
in vec2 textureUv;
out vec4 color;

void main()
{
	vec2 source = textureUv;

	if(melt)
	{
		int count = textureSize(columnOffsets, 0).x;
		int column = min(count - 1, int(floor(source.x * float(count))));
		source.y += texelFetch(columnOffsets, ivec2(column, 0), 0).r;

		if(source.y >= 1.0) discard;
	}

	color = texture(frameTexture, source);
}
