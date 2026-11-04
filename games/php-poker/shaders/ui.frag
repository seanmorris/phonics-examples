#version 300 es
precision highp float;
uniform sampler2D uFont;
uniform sampler2D uAtlas;
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

	if(mode==4.) {
		// Card atlas texel, tinted by the fill and clipped to the rounded rectangle.
		vec4 texel=texture(uAtlas,vUv);
		float cover=clamp(.5-box(vLocal,vHalf,radius),0.,1.);
		pixel=premultiply(vec4(texel.rgb*vFill.rgb,texel.a*vFill.a))*cover;
		return;
	}

	float d;
	if(mode==2.) {
		// A circle, or with softness 1 an ellipse stretched to the rectangle; the ring width is measured on the minor axis.
		vec2 scaled=softness==1.?vLocal/vHalf*min(vHalf.x,vHalf.y):vLocal;
		d=length(scaled)-min(vHalf.x,vHalf.y);
		softness=0.;
	}
	else d=box(vLocal,vHalf,radius);

	float ramp=softness==1.?(vLocal.x+vHalf.x)/max(1.,2.*vHalf.x):vRamp;
	if(mode==3.)softness=0.;
	if(softness>0.) {
		pixel=premultiply(vFill)*(1.-smoothstep(-softness,softness,d));
		return;
	}

	vec4 fill=mode==3.?mix(vFill,vExtra,clamp(ramp,0.,1.)):vFill;
	float outer=clamp(.5-d,0.,1.);
	float inner=border>0.?clamp(.5-(d+border),0.,1.):outer;
	pixel=premultiply(fill)*inner+premultiply(vBorder)*(outer-inner);
}
