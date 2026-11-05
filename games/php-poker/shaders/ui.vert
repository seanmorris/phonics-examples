#version 300 es
precision highp float;
layout(location=0) in vec4 aRect;
layout(location=1) in vec4 aFill;
layout(location=2) in vec4 aParams;
layout(location=3) in vec4 aBorder;
layout(location=4) in vec4 aExtra;
uniform vec2 uViewport;
out vec2 vLocal;
out vec2 vHalf;
out vec2 vUv;
out float vRamp;
flat out vec4 vFill;
flat out vec4 vParams;
flat out vec4 vBorder;
flat out vec4 vExtra;
void main() {
	vec2 corners[6]=vec2[6](vec2(0,0),vec2(1,0),vec2(0,1),vec2(0,1),vec2(1,0),vec2(1,1));
	vec2 corner=corners[gl_VertexID];
	// Soft shadows bleed past their rectangle; grow the quad so the blur is not clipped.
	float grow=(aParams.z==1.||aParams.z==4.)?0.:aParams.w*2.+1.;
	vec2 point=aRect.xy-grow+corner*(aRect.zw+grow*2.);
	vHalf=aRect.zw*.5;
	vLocal=point-(aRect.xy+vHalf);
	vUv=mix(aExtra.xy,aExtra.zw,corner);
	vRamp=(point.y-aRect.y)/max(1.,aRect.w);
	vFill=aFill;vParams=aParams;vBorder=aBorder;vExtra=aExtra;
	vec2 clip=point/uViewport;
	gl_Position=vec4(clip.x*2.-1.,1.-clip.y*2.,0,1);
}
