#version 300 es
precision highp float;
flat in int vMeteor;
in vec2 vTrail;
in vec3 vColor;
in float vOpacity;
out vec4 color;

void main()
{
	if(vMeteor!=0) {
		float streak = vTrail.x*vTrail.x*(1.-smoothstep(.05,1.,abs(vTrail.y)));
		color = vec4(vColor,vOpacity*streak);
		return;
	}
	vec2 offset = 2.0 * gl_PointCoord - 1.0;
	float profile = max(0.0, 1.0 - dot(offset,offset));
	color = vec4(vColor, vOpacity * profile * profile * profile);
}
