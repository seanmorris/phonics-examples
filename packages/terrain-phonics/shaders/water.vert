#version 300 es
precision highp float;
precision highp int;
// WATER
// PATCH
layout(location=0) in vec3 aPosition;
layout(location=2) in float aParent;
uniform mat4 uViewProjection;
uniform vec3 uEye;
out vec3 vRelative, vWorld;
out float vGround;

float waveHeight(vec2 xz)
{
	return terrainDirectionalSample(xz,uWaterTime).x + terrainFrameWakes(xz,vec2(0),vec2(0),false).x;
}
void main()
{
	#ifdef CLIPMAP
	ivec2 cell=clipStitchedCell(clipCell());
	vec2 xz=vec2(cell)*uGridStep;
	float morph=clipMorph(cell);
#else
	ivec2 cell = patchCell();
	vec2 xz = patchWorld(cell);
	float morph = patchMorph(cell);
	#endif
	float height = waveHeight(xz);
	ivec2 odd = cell & ivec2(1);
	if(morph > 0.0 && any(notEqual(odd, ivec2(0))))
	{
		// Every new vertex bisects a parent edge or its X/-Z triangle diagonal.
		ivec2 edge = ivec2(odd.x,-odd.y);
		#ifdef CLIPMAP
		float parent=.5*(waveHeight(xz-vec2(edge)*uGridStep)+waveHeight(xz+vec2(edge)*uGridStep));
#else
		float parent = 0.5 * (waveHeight(patchWorld(cell-edge)) + waveHeight(patchWorld(cell+edge)));
		#endif
		height = mix(height,parent,morph);
	}
	#ifdef CLIPMAP
	vGround=clipHeight(cell,morph);
#else
	vGround = mix(aPosition.y,aParent,morph);
#endif
	vWorld = vec3(xz.x,height,xz.y);
	vRelative = vWorld - uEye;
	gl_Position = uViewProjection * vec4(vRelative,1.0);
}
