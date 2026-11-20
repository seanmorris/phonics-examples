#version 300 es
precision highp float;

layout(location=0) in vec3 aPosition;
layout(location=1) in vec3 aNormal;
layout(location=2) in vec2 aUv;
layout(location=3) in vec4 aPaint;      // sRGB base color and linear roughness
layout(location=4) in vec2 aSurface;    // metallic factor and surface tag (wheel, lamp, textured or moving surface)

uniform mat4 uViewProjection;
uniform mat3 uRotation;
uniform vec3 uOrigin;
uniform vec2 uWheelAngles; // spin and front steering, in radians
uniform vec4 uWheelLayout; // front/rear half track, half wheelbase, tire radius
uniform vec4 uWheelTravel; // spring displacement at front -X/+X, then rear -X/+X
// Tags 250 to 254 turn about these hinges by the right-hand rule. Other vehicles leave them unset.
const int SURFACES = 5;
uniform vec3 uHingePoint[SURFACES], uHingeAxis[SURFACES];
uniform float uHingeAngle[SURFACES];
// Shifts the texture lookup to another print in the atlas, as the race gates choose their role. Vehicles leave it zero.
uniform vec2 uUvOffset;
// Tags 2 to 7 are the Testarossa's lamps and the covers of its pop-up headlights. Tags 2 to 6 give off uLampGlow, the
// radiance of each lamp, which the fragment shader adds to the light the surface reflects. Tags 6 (the headlamps)
// and 7 (the covers) turn by uPodAngle about the line along X at the height and Z in uPodHinge. Other vehicles and the
// gates leave all three zero.
const int FIRST_LAMP = 2, LAMPS = 5, FIRST_POD = 6;
uniform vec3 uLampGlow[LAMPS];
uniform vec2 uPodHinge;
uniform float uPodAngle;
out vec3 vRelative, vNormal;
out vec2 vUv;
flat out vec4 vPaint;
flat out vec2 vSurface;
flat out vec3 vGlow;

void main()
{
	vec3 position = aPosition, normal = aNormal;
	int tag = int(aSurface.y * 255.0 + .5);
	if(tag == 1)
	{
		bool front = position.z > 0.0;
		vec3 hub = vec3(sign(position.x) * (front ? uWheelLayout.x : uWheelLayout.y),
			uWheelLayout.w, sign(position.z) * uWheelLayout.z);
		float spin = uWheelAngles.x, steer = front ? uWheelAngles.y : 0.0;
		mat2 rolling = mat2(cos(spin),sin(spin),-sin(spin),cos(spin));
		mat2 steering = mat2(cos(steer),-sin(steer),sin(steer),cos(steer));
		position -= hub;
		position.yz = rolling * position.yz;
		position.xz = steering * position.xz;
		normal.yz = rolling * normal.yz;
		normal.xz = steering * normal.xz;
		position += hub;
		position.y += front ? (hub.x < 0.0 ? uWheelTravel.x : uWheelTravel.y)
			: (hub.x < 0.0 ? uWheelTravel.z : uWheelTravel.w);
	}
	int hinge = tag - 250;
	if(hinge >= 0 && hinge < SURFACES)
	{
		// Rodrigues' rotation about the hinge line, applied to the arm from the hinge and to the normal.
		vec3 axis = uHingeAxis[hinge], arm = position - uHingePoint[hinge];
		float c = cos(uHingeAngle[hinge]), s = sin(uHingeAngle[hinge]);
		position = uHingePoint[hinge] + arm * c + cross(axis, arm) * s + axis * dot(axis, arm) * (1.0 - c);
		normal = normal * c + cross(axis, normal) * s + axis * dot(axis, normal) * (1.0 - c);
	}
	vGlow = tag >= FIRST_LAMP && tag < FIRST_LAMP + LAMPS ? uLampGlow[tag - FIRST_LAMP] : vec3(0.0);
	if(tag >= FIRST_POD && tag <= FIRST_POD + 1 && uPodAngle != 0.0)
	{
		mat2 turn = mat2(cos(uPodAngle), sin(uPodAngle), -sin(uPodAngle), cos(uPodAngle));
		position.yz = uPodHinge + turn * (position.yz - uPodHinge);
		normal.yz = turn * normal.yz;
	}
	vRelative = uOrigin + uRotation * position;
	vNormal = uRotation * normal;
	vUv = aUv + uUvOffset;
	vPaint = aPaint;
	vSurface = aSurface;
	gl_Position = uViewProjection * vec4(vRelative, 1.0);
}
