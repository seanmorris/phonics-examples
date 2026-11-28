#version 300 es
precision highp float;
layout(location=0) in vec3 position;
layout(location=1) in vec3 normal;
layout(location=2) in vec2 atlasUv;
layout(location=3) in vec4 paint;
layout(location=4) in vec2 surface;
uniform mat4 vp;
uniform vec3 eye, sun, foot;
uniform vec2 tangent, feet;
uniform float day, widthDelta, gatePrintOffset;
uniform vec2 panelFit; // Road-width scale and lift of the gantry crossbars.
uniform vec4 textSlots[8]; // local left and width, atlas left and width
uniform vec4 textV; // Atlas V origin and height, green face scale, bottom of the green face.
out vec2 uv;
out vec3 relative;
out vec3 tint;
flat out vec3 sheetNormal;
out float elevation, brightness;
flat out float front;
flat out int part;

void main() {
	part=int(surface.y*255.+.5);
	vec3 p=position;
	uv=atlasUv;
	tint=paint.rgb;
	if(part==1) p.x+=clamp(p.x/7.062,-1.,1.)*widthDelta;
	else if(part>=2 && part<=4) {
		float h=p.x<0. ? feet.x : feet.y;
		p.x+=sign(p.x)*widthDelta;
		if(part>=3) p.y+=h;
	} else if(part==7 || part==8) {
		// Fit the original race print and whole checker columns between the posts.
		float label=p.x<0. ? 2.9774 : 3.0008, column=p.x<0. ? .32183 : .32052;
		float band=14.*column+widthDelta;
		float scale=clamp((label+band-4.*column)/label,.1,2.);
		float room=band-(scale-1.)*label;
		float columns=clamp(floor(room/(2.*column)+.5),1.,88.);
		if(part==7) p.x*=scale;
		else p.x=sign(p.x)*(label*scale+clamp((abs(p.x)-label)/(columns*column),0.,1.)*room);
		uv.y+=gatePrintOffset;
	} else if(part==9) {
		p.x+=sign(p.x)*widthDelta;
	} else if(part>=16) {
		vec4 slot=textSlots[part-16];
		uv=vec2(slot.z+p.x*slot.w,textV.x+(1.-p.y)*textV.y);
		p.x=slot.x+p.x*slot.y;
		// 48 px em, 48 px baseline in a 64 px glyph cell. Match the two rows printed on the mileage panel.
		p.y=7.94+(p.y*64.-16.)*.0238;
		p.x*=textV.z;
		p.y=textV.w+(p.y-textV.w)*textV.z;
	}
	if(part==1 || part==2) p.y+=panelFit.y;
	if(part==5 || part>=16) {
		p.x*=panelFit.x;
		p.y=7.15+(p.y-7.15)*panelFit.x;
	}
	vec2 right=vec2(-tangent.y,tangent.x);
	vec2 offset=right*p.x+tangent*p.z;
	relative=(foot-eye)+vec3(offset.x,p.y,offset.y);
	gl_Position=vp*vec4(relative,1.);
	elevation=foot.y+p.y;
	vec2 dir=right*normal.x+tangent*normal.z;
	sheetNormal=vec3(dir.x,normal.y,dir.y);
	brightness=mix(.10,.71+.29*max(dot(sheetNormal,sun),0.),day);
	if(part==9) {
		// Curved latex reflects the sky even when the driver sees the unlit side.
		vec3 n=normalize(sheetNormal), view=normalize(-relative);
		float sky=.5+.5*n.y, direct=max(dot(n,sun),0.);
		float reflection=smoothstep(.35,1.,reflect(-view,n).y);
		float glint=pow(max(dot(reflect(-sun,n),view),0.),32.);
		tint=paint.rgb*mix(.10+.08*sky,.37+.45*sky+.32*direct,day)
			+mix(.05,1.,day)*vec3(.75,.86,1.)*(.19*reflection+.48*glint);
	}
	front=dot(sheetNormal,-relative);
}
