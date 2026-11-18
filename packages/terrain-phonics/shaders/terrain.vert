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
uniform float uSize, uMorph, uCraft;
uniform vec4 uEdges, uCorners;
out vec3 vRelative, vWorld, vNormal;
out vec3 vPaint0, vPaint1;
void main()
{
	vec3 p = aPosition;
	vec3 normal = aNormal;
	if(uCraft < 0.5)
	{
		bool left = p.x < 0.01, right = p.x > uSize - 0.01;
		bool top = p.z < 0.01, bottom = p.z > uSize - 0.01;
		float morph = max(uMorph, max(max(left ? uEdges.x : 0.0, right ? uEdges.y : 0.0),
			max(top ? uEdges.z : 0.0, bottom ? uEdges.w : 0.0)));
		if(left && top) morph = uCorners.x;
		if(right && top) morph = uCorners.y;
		if(left && bottom) morph = uCorners.z;
		if(right && bottom) morph = uCorners.w;
		p.y = mix(p.y, aParent, morph);
		normal = mix(normal, aParentNormal, morph);
	}
	else
	{
		float y = uCraftRotation.x, pitch = uCraftRotation.y, roll = uCraftRotation.z;
		mat3 ry = mat3(cos(y),0.0,-sin(y),0.0,1.0,0.0,sin(y),0.0,cos(y));
		mat3 rx = mat3(1.0,0.0,0.0,0.0,cos(pitch),sin(pitch),0.0,-sin(pitch),cos(pitch));
		mat3 rz = mat3(cos(roll),sin(roll),0.0,-sin(roll),cos(roll),0.0,0.0,0.0,1.0);
		p = ry * rx * rz * p;
		normal = ry * rx * rz * normal;
	}
	vRelative = p + uOrigin;
	vWorld = vRelative + uEye;
	vNormal = normal;
	vPaint0 = vec3(abs(aMaterial) < 0.1 ? 1.0 : 0.0,
		abs(aMaterial - 1.0) < 0.1 ? 1.0 : 0.0, abs(aMaterial - 2.0) < 0.1 ? 1.0 : 0.0);
	vPaint1 = vec3(abs(aMaterial - 3.0) < 0.1 ? 1.0 : 0.0,
		abs(aMaterial - 4.0) < 0.1 ? 1.0 : 0.0, abs(aMaterial - 5.0) < 0.1 ? 1.0 : 0.0);
	gl_Position = uViewProjection * vec4(vRelative, 1.0);
}
