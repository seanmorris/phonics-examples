#version 300 es
precision highp float;
uniform sampler2D atlas;
uniform float blend;
in vec2 uv;
flat in vec4 currentRect;
flat in vec4 nextRect;
out vec4 outputColor;

vec4 pixel(vec4 rectangle) {
	vec2 point=min(floor(uv*rectangle.zw),rectangle.zw-1.);
	return texelFetch(atlas,ivec2(rectangle.xy+point),0);
}

void main() {
	vec4 color=pixel(currentRect);
	if(blend>0.) color=mix(color,pixel(nextRect),blend);
	if(color.a==0.) discard;
	outputColor=color;
}
