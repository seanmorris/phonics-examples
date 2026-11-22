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
vec4 roadMark(int code) {
	float angle = float(code&2047)*(6.28318530718/2048.);
	return vec4(float((code>>11)-4096)/2048.,cos(angle),sin(angle),1.);
}
void main()
{
	vec3 p = aPosition;
	vec3 normal = aNormal;
	float material=aMaterial;
	vRoadMark=vec4(0.);
	if(uCraft < 0.5)
	{
		#ifdef CLIPMAP
		ivec2 cell=clipStitchedCell(clipCell());
		float morph=clipMorph(cell);
		vec2 xz=vec2(cell)*uGridStep;
		vWorld=vec3(xz.x,clipHeight(cell,morph),xz.y);
		vRelative=vWorld-uEye;
		normal=clipNormal(cell,morph);
		ivec4 ink=ivec4(texelFetch(uInk,texelAt(cell),0)*255.+.5);
		material=float(ink.r)-1.;
		if(any(notEqual(ink.gba,ivec3(0)))) {
			vRoadMark=roadMark((ink.g<<16)|(ink.b<<8)|ink.a);
		}
#else
		// Patch meshes store the road coordinates in the otherwise categorical material attribute.
		if(material < -1. || material >= 2048.) {
			vRoadMark = roadMark(int(abs(material)));
			material = material < 0. ? 4. : -1.;
		}
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
	vPaint0 = vec3(abs(material) < 0.1 ? 1.0 : 0.0,
		abs(material - 1.0) < 0.1 ? 1.0 : 0.0, abs(material - 2.0) < 0.1 ? 1.0 : 0.0);
	vPaint1 = vec3(abs(material - 3.0) < 0.1 ? 1.0 : 0.0,
		abs(material - 4.0) < 0.1 ? 1.0 : 0.0, abs(material - 5.0) < 0.1 ? 1.0 : 0.0);
	gl_Position = uViewProjection * vec4(vRelative, 1.0);
}
