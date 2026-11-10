#version 300 es
precision highp float;
layout(location=0) in vec4 destination;
layout(location=1) in vec4 currentRectangle;
layout(location=2) in vec4 nextRectangle;
uniform vec2 viewport;
out vec2 uv;
flat out vec4 currentRect;
flat out vec4 nextRect;

void main() {
	vec2 corners[6]=vec2[6](
		vec2(0, 0), vec2(1, 0), vec2(0, 1), vec2(0, 1), vec2(1, 0), vec2(1, 1)
	);
	vec2 corner=corners[gl_VertexID];
	uv=corner;
	if(destination.z<0.) uv.x=1.-uv.x;
	if(destination.w<0.) uv.y=1.-uv.y;
	vec2 point=(destination.xy+corner*abs(destination.zw))/viewport;
	gl_Position=vec4(point.x*2.-1.,1.-point.y*2.,0.,1.);
	currentRect=currentRectangle;
	nextRect=nextRectangle;
}
