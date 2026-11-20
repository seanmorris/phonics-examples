#version 300 es
precision highp float;

layout(location=0) in vec3 aPosition;
layout(location=1) in vec3 aNormal;
layout(location=2) in vec2 aUv;
layout(location=3) in vec4 aPaint;      // sRGB base color and linear roughness
layout(location=4) in vec2 aSurface;    // metallic factor and surface tag (wheel, textured or moving surface)

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
// A race gate's opening resized from the mesh's own, in metres: the half width and the height it adds, then the water
// arch's scale across and up. A gate always sets the arch scales, 1 on the other kinds; vehicles leave them zero,
// which leaves every vertex where the mesh has it.
uniform vec4 uGateSize;
// The gate board's label half width on its -X and +X sides, then the width of one checker column on each side.
uniform vec4 uGateBoard;
// Checker columns each side of the board shows at the mesh's own size, then the columns the mesh holds per side.
uniform vec2 uGateColumns;
// Heights from the gate origin of the -X and +X posts' feet, then of the ground under each post.
uniform vec4 uGateFeet;
out vec3 vRelative, vNormal;
out vec2 vUv;
flat out vec4 vPaint;
flat out vec2 vSurface;

// Gate parts, from the low bits of the vertex tags that scripts/import_terrain_gates.py writes.
const int LABEL = 1, FRAME = 2, SQUARE = 3, CAP = 4, POST = 5, BASE = 6, ARCH = 7;

// Place a board vertex across a gate whose opening is uGateSize.x wider each side. The label keeps its size, the frame
// beyond each checker band moves out with the posts, and each band shows the whole columns that come nearest to
// filling it, each stretched a little to fit. The columns it does not show fold into its outer edge.
float boardX(float x, bool square)
{
	float side = x < 0.0 ? -1.0 : 1.0;
	float label = x < 0.0 ? uGateBoard.x : uGateBoard.y, column = x < 0.0 ? uGateBoard.z : uGateBoard.w;
	float along = abs(x) - label, band = uGateColumns.x * column;
	if(along <= 0.0) return x;
	if(!square && along > band - .001) return x + side * uGateSize.x;
	float width = band + uGateSize.x;
	float shown = clamp(floor(width / column + .5), 1.0, uGateColumns.y);
	return side * (label + min(along, shown * column) * width / (shown * column));
}

void main()
{
	vec3 position = aPosition, normal = aNormal;
	int tag = int(aSurface.y * 255.0 + .5);
	bool gate = uGateSize.z > 0.0 && tag >= 128 && tag < 224;
	if(gate)
	{
		int part = tag & 63;
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
			else if(part != LABEL) position.x = boardX(position.x, part == SQUARE);
			position.y += uGateSize.y;
		}
	}
	if(aSurface.y > 0.0 && aSurface.y < 0.5)
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
	int hinge = int(aSurface.y * 255.0 + .5) - 250;
	if(hinge >= 0 && hinge < SURFACES)
	{
		// Rodrigues' rotation about the hinge line, applied to the arm from the hinge and to the normal.
		vec3 axis = uHingeAxis[hinge], arm = position - uHingePoint[hinge];
		float c = cos(uHingeAngle[hinge]), s = sin(uHingeAngle[hinge]);
		position = uHingePoint[hinge] + arm * c + cross(axis, arm) * s + axis * dot(axis, arm) * (1.0 - c);
		normal = normal * c + cross(axis, normal) * s + axis * dot(axis, normal) * (1.0 - c);
	}
	vRelative = uOrigin + uRotation * position;
	vNormal = uRotation * normal;
	vUv = aUv + uUvOffset;
	vPaint = aPaint;
	// model.frag samples the atlas for a surface above one half. Bit 6 of a gate tag says whether its part does.
	vSurface = gate ? vec2(aSurface.x, (tag & 64) != 0 ? 1.0 : 0.0) : aSurface;
	gl_Position = uViewProjection * vec4(vRelative, 1.0);
}
