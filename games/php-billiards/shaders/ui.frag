#version 300 es
precision highp float;
uniform sampler2D uFont;
in vec2 vLocal;
in vec2 vHalf;
in vec2 vUv;
in float vRamp;
flat in vec4 vFill;
flat in vec4 vParams;
flat in vec4 vBorder;
flat in vec4 vExtra;
out vec4 pixel;

float box(vec2 p,vec2 extent,float radius) {
	radius=min(radius,min(extent.x,extent.y));
	vec2 q=abs(p)-extent+radius;
	return length(max(q,0.))+min(max(q.x,q.y),0.)-radius;
}

vec4 premultiply(vec4 color) { return vec4(color.rgb*color.a,color.a); }

void main() {
	float radius=vParams.x,border=vParams.y,mode=vParams.z,softness=vParams.w;

	if(mode==1.) {
		// Signed-distance glyph: 0.5 is the outline; softness widens the edge for shadows and glows.
		// Distance fields stay exact under point sampling; averaged mip levels erase strokes thinner than a texel.
		float distance=textureLod(uFont,vUv,0.0).r;
		float width=max(fwidth(distance)*.7,softness);
		pixel=premultiply(vFill)*smoothstep(.5-width,.5+width,distance);
		return;
	}

	float d=mode==2.?length(vLocal)-min(vHalf.x,vHalf.y):box(vLocal,vHalf,radius);

	if(softness>0.) {
		pixel=premultiply(vFill)*(1.-smoothstep(-softness,softness,d));
		return;
	}

	vec4 fill=mode==3.?mix(vFill,vExtra,clamp(vRamp,0.,1.)):vFill;
	float outer=clamp(.5-d,0.,1.);
	float inner=border>0.?clamp(.5-(d+border),0.,1.):outer;
	pixel=premultiply(fill)*inner+premultiply(vBorder)*(outer-inner);
}
