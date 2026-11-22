#version 300 es
precision highp float;
precision highp int;
layout(location=0) in vec3 aPosition;
layout(location=1) in vec3 aNormal;
layout(location=2) in float aParent;
layout(location=3) in float aMaterial;
layout(location=4) in vec3 aParentNormal;
uniform mat4 uViewProjection;
uniform vec3 uOrigin, uEye, uCraftRotation;
uniform float uCraft;
// PATCH
out vec3 vRelative, vWorld, vNormal;
out vec3 vPaint0, vPaint1;
out vec4 vRoadMark;
out float vSplinePaint;
vec4 roadMark(int code) {
	float angle = float(code&2047)*(6.28318530718/2048.);
	return vec4(float((code>>11)-4096)/2048.,cos(angle),sin(angle),1.);
}
#ifdef CLIPMAP
void clipPaint(ivec2 cell,out vec3 paint0,out vec4 paint1,out vec4 mark) {
	ivec4 ink=ivec4(texelFetch(uInk,texelAt(cell),0)*255.+.5);
	paint0=vec3(equal(ivec3(ink.r),ivec3(1,2,3)));
	int code=(ink.g<<16)|(ink.b<<8)|ink.a;
	paint1=vec4(float(ink.r==4),float(ink.r==5),0.,float(ink.r==5 && code!=0));
	mark=code==0 ? vec4(0.) : roadMark(code);
}
#endif
void meshPaint(float material) {
	vPaint0 = vec3(lessThan(abs(vec3(material)-vec3(0.,1.,2.)),vec3(.1)));
	vPaint1 = vec3(lessThan(abs(vec3(material)-vec3(3.,4.,5.)),vec3(.1)));
}
void main()
{
	vec3 p = aPosition;
	vec3 normal = aNormal;
	float material=aMaterial;
	vRoadMark=vec4(0.);
	vSplinePaint=0.;
	if(uCraft < 0.5)
	{
		#ifdef CLIPMAP
		ivec2 cell=clipStitchedCell(clipCell());
		float morph=clipMorph(cell);
		vec2 xz=vec2(cell)*uGridStep;
		vWorld=vec3(xz.x,clipHeight(cell,morph),xz.y);
		vRelative=vWorld-uEye;
		normal=clipNormal(cell,morph);
		vec4 paint;
		clipPaint(cell,vPaint0,paint,vRoadMark);
		ivec2 odd=cell&1;
		if(morph>0. && any(notEqual(odd,ivec2(0)))) {
			// Match the same parent triangle as clipHeight. Decode categories and cyclic dash phase before mixing.
			ivec2 edge=ivec2(odd.x,-odd.y);
			vec3 a0,b0; vec4 a1,b1,ma,mb;
			clipPaint(cell-edge,a0,a1,ma);
			clipPaint(cell+edge,b0,b1,mb);
			vPaint0=mix(vPaint0,.5*(a0+b0),morph);
			paint=mix(paint,.5*(a1+b1),morph);
			vRoadMark=mix(vRoadMark,.5*(ma+mb),morph);
		}
		vPaint1=paint.xyz;
		vSplinePaint=paint.w;
#else
		// Patch meshes store the road coordinates in the otherwise categorical material attribute.
		if(material < -1. || material >= 2048.) {
			vRoadMark = roadMark(int(abs(material)));
			vSplinePaint = float(material < 0.);
			material = material < 0. ? 4. : -1.;
		}
		meshPaint(material);
		ivec2 cell = patchCell();
		vec2 xz = patchWorld(cell);
		float morph = patchMorph(cell);
		vWorld = vec3(xz.x, mix(p.y,aParent,morph), xz.y);
		vRelative = vWorld - uEye;
		normal = mix(normal, aParentNormal, morph);
#endif
	}
	else
	{
		meshPaint(material);
		float y = uCraftRotation.x, pitch = uCraftRotation.y, roll = uCraftRotation.z;
		mat3 ry = mat3(cos(y),0.0,-sin(y),0.0,1.0,0.0,sin(y),0.0,cos(y));
		mat3 rx = mat3(1.0,0.0,0.0,0.0,cos(pitch),sin(pitch),0.0,-sin(pitch),cos(pitch));
		mat3 rz = mat3(cos(roll),sin(roll),0.0,-sin(roll),cos(roll),0.0,0.0,0.0,1.0);
		p = ry * rx * rz * p;
		normal = ry * rx * rz * normal;
		vRelative = p + uOrigin;
		vWorld = vRelative + uEye;
	}
	vNormal = normal;
	gl_Position = uViewProjection * vec4(vRelative, 1.0);
}
