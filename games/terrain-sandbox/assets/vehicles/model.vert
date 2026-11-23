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
uniform vec4 uWheelAngles; // front spin, front -X steer, front +X steer, rear spin in radians
uniform vec4 uWheelLayout; // front/rear half track, half wheelbase, tire radius
uniform vec4 uWheelTravel; // spring displacement at front -X/+X, then rear -X/+X
// Tags 250 to 254 turn about these hinges by the right-hand rule. Other vehicles leave them unset.
const int SURFACES = 5;
uniform vec3 uHingePoint[SURFACES], uHingeAxis[SURFACES];
uniform float uHingeAngle[SURFACES];
// Shifts the texture lookup to another print in the atlas, as the race gates choose their role. Vehicles leave it zero.
uniform vec2 uUvOffset;
// A race gate's opening resized from the mesh's own, in meters: the half width and the height it adds, then the water
// arch's scale across and up. A gate always sets the arch scales, 1 on the other kinds; vehicles leave them zero,
// which leaves every vertex where the mesh has it.
uniform vec4 uGateSize;
// The gate board's label half width on its -X and +X sides, then the width of one checker column on each side.
uniform vec4 uGateBoard;
// Checker columns each side of the board shows at the mesh's own size, then the columns the mesh holds per side.
// Z is the fitted label scale. W is the full print scale used for the checker pattern and board height.
uniform vec4 uGateColumns;
// Heights from the gate origin of the -X and +X posts' feet, then of the ground under each post.
uniform vec4 uGateFeet;
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
flat out float vLabelFit;
out float vBanner;

// Gate parts, from the low bits of the vertex tags that scripts/import_terrain_gates.py writes.
const int LABEL = 1, FRAME = 2, SQUARE = 3, CAP = 4, POST = 5, BASE = 6, ARCH = 7;

// Place a board vertex across a gate whose opening is uGateSize.x wider each side. The print scales, the frame
// beyond each checker band moves out with the posts, and each band shows the whole columns that come nearest to
// filling it, each stretched a little to fit. The columns it does not show fold into its outer edge.
float boardX(float x, bool square)
{
	float side = x < 0.0 ? -1.0 : 1.0;
	float label = x < 0.0 ? uGateBoard.x : uGateBoard.y, column = x < 0.0 ? uGateBoard.z : uGateBoard.w;
	float along = abs(x) - label, band = uGateColumns.x * column;
	if(along <= 0.0) return x * uGateColumns.z;
	if(!square && along > band - .001) return x + side * uGateSize.x;
	float width = band + uGateSize.x - label * (uGateColumns.z - 1.0);
	float shown = clamp(floor(width / (column * uGateColumns.w) + .5), 1.0, uGateColumns.y);
	return side * (label * uGateColumns.z + min(along, shown * column) * width / (shown * column));
}

void main()
{
	vec3 position = aPosition, normal = aNormal;
	int tag = int(aSurface.y * 255.0 + .5);
	bool gate = uGateSize.z > 0.0 && tag >= 128 && tag < 224;
	vLabelFit = 1.0;
	vBanner = -1.;
	if(gate)
	{
		int part = tag & 63;
		if(part == LABEL || part == SQUARE) vBanner = .5;
		// Coordinate in the printed water banner before stretching the arch to the gate size.
		if(part == ARCH) vBanner = (aPosition.y - (3.596+.15))/1.6;
		float side = position.x < 0.0 ? -1.0 : 1.0;
		if(part == ARCH)
		{
			// Below the water line the feet keep their depth, which the waves need rather than the opening.
			vec3 scale = vec3(uGateSize.z, position.y > 0.0 ? uGateSize.w : 1.0, sqrt(uGateSize.z * uGateSize.w));
			position *= scale;
			normal = normalize(normal / scale);
		}
		else if(part == POST)
		{
			// A post's top rises with the board, and its foot reaches below the ground under it.
			position.y = position.y > 0.0 ? position.y + uGateSize.y : (side < 0.0 ? uGateFeet.x : uGateFeet.y);
			position.x += side * uGateSize.x;
		}
		else if(part == BASE)
		{
			position.y += side < 0.0 ? uGateFeet.z : uGateFeet.w;
			position.x += side * uGateSize.x;
		}
		else
		{
			// The board, its label and the tabs on its ends rise with the top of the opening.
			if(part == CAP) position.x += side * uGateSize.x;
			else if(part == LABEL)
			{
				position.x *= uGateColumns.z;
				vLabelFit = uGateColumns.w / uGateColumns.z;
			}
			else position.x = boardX(position.x, part == SQUARE);
			position.y += uGateSize.y;
		}
	}
	if(tag == 1)
	{
		bool front = position.z > 0.0;
		vec3 hub = vec3(sign(position.x) * (front ? uWheelLayout.x : uWheelLayout.y),
			uWheelLayout.w, sign(position.z) * uWheelLayout.z);
		float spin = front ? uWheelAngles.x : uWheelAngles.w;
		float steer = front ? (hub.x < 0.0 ? uWheelAngles.y : uWheelAngles.z) : 0.0;
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
	// Deep red lens plastic in reflected light. The bulbs supply their own radiance.
	if(tag == FIRST_LAMP || tag == FIRST_LAMP+1) vPaint.rgb *= .48;
	// model.frag samples the atlas for a surface above one half. Bit 6 of a gate tag says whether its part does.
	vSurface = gate ? vec2(aSurface.x, (tag & 64) != 0 ? 1.0 : 0.0) : aSurface;
	gl_Position = uViewProjection * vec4(vRelative, 1.0);
}
