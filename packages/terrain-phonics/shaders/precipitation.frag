#version 300 es
precision highp float;
uniform vec2 uFall;
uniform float uDay;
in vec2 vCorner;
in float vAlpha;
out vec4 color;
void main()
{
	bool snow=uFall.y>.5;
	float shape=1.-smoothstep(.18,1.,snow ? dot(vCorner,vCorner) : abs(vCorner.x));
	color=vec4(mix(vec3(.46,.57,.65),vec3(.92,.96,1.),float(snow))*mix(.48,1.,uDay),
		vAlpha*shape*(snow ? .87 : .52));
}
