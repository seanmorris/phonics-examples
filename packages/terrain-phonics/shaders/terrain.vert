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
#ifdef CLIPMAP
uniform sampler2D uTerrainShadowMap;
uniform float uTerrainShadowExtent;
uniform vec3 uSunDirection;
uniform int uTerrainShadowReady;
flat out float vSunCoverage;

float broadAt(vec2 world)
{
	vec2 uv = world/uTerrainShadowExtent+.5;
	return any(lessThan(uv,vec2(0.))) || any(greaterThan(uv,vec2(1.))) ? 1. : textureLod(uTerrainShadowMap,uv,0.).r;
}

// Each strip's last (provoking) vertex describes the actual triangle, including collapsed LOD edges.
// Once its edges span the overview texels, integrate coverage over the face instead of aliasing it.
float distantCoverage(ivec2 cell)
{
	if(uTerrainShadowReady == 0 || uSunDirection.y <= 0. || gl_VertexID < 2 || uGridStep < uTerrainShadowExtent/256.) return -1.;
	vec2 a = vec2(clipStitchedCell(cell))*uGridStep;
	vec2 b = vec2(clipStitchedCell(cell+ivec2(-1,0)))*uGridStep;
	bool upper = (gl_VertexID & 1) != 0;
	vec2 c = vec2(clipStitchedCell(cell+(upper ? ivec2(0,-1) : ivec2(-1,1))))*uGridStep;
	if(any(greaterThan(min(a,min(b,c)),vec2(.5*uTerrainShadowExtent))) ||
		any(lessThan(max(a,max(b,c)),vec2(-.5*uTerrainShadowExtent)))) return 1.;
	float x = broadAt(a), y = broadAt(b), z = broadAt(c);
	float center = broadAt((a+b+c)/3.);
	if(x == y && y == z && z == center) return x;
	float ab = x == y ? x : broadAt(.5*(a+b));
	float bc = y == z ? y : broadAt(.5*(b+c));
	float ca = z == x ? z : broadAt(.5*(c+a));
	return (x+y+z+ab+bc+ca+3.*center)/9.;
}
#endif
void main()
{
	vec3 p = aPosition;
	vec3 normal = aNormal;
	float material=aMaterial;
	#ifdef CLIPMAP
	vSunCoverage = -1.;
	#endif
	if(uCraft < 0.5)
	{
		#ifdef CLIPMAP
		ivec2 rawCell=clipCell();
		vSunCoverage = distantCoverage(rawCell);
		ivec2 cell=clipStitchedCell(rawCell);
		float morph=clipMorph(cell);
		vec2 xz=vec2(cell)*uGridStep;
		vWorld=vec3(xz.x,clipHeight(cell,morph),xz.y);
		vRelative=vWorld-uEye;
		normal=clipNormal(cell,morph);
		material=texelFetch(uInk,texelAt(cell),0).r*255.-1.;
#else
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
