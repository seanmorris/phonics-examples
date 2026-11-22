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
out vec3 vRoadOffset;
vec3 roadOffset(ivec2 code) { return vec3((vec2(code)-2048.)/32.,1.); }
void main()
{
	vec3 p = aPosition;
	vec3 normal = aNormal;
	float material=aMaterial;
	vRoadOffset=vec3(0.);
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
		if(ink.r==5 && any(notEqual(ink.gba,ivec3(0)))) {
			ivec2 code=ivec2((ink.g<<4)|(ink.a>>4),(ink.b<<4)|(ink.a&15));
			vRoadOffset=roadOffset(code);
		}
#else
		// Patch meshes store both offsets in the otherwise categorical material attribute.
		if(material < -1.) {
			int code = int(-material);
			vRoadOffset = roadOffset(ivec2(code >> 12, code & 4095));
			material = 4.;
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
