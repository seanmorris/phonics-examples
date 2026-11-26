#version 300 es
precision highp float;
// WEATHER
uniform vec3 uEye;
uniform float uExposure;
in vec2 vDelta;
out vec4 color;
void main()
{
	vec2 cloud=cloudSample(uEye.xz+vDelta);
	float dy=uEye.y-uDeck.y;
	float fade=1.-smoothstep(35000.,60000.,length(vec3(vDelta,dy)));
	float alpha=cloud.x*fade*smoothstep(0.,22.,abs(dy));
	vec3 light=cloudRadiance(cloud.y,smoothstep(-20.,20.,dy));
	color=vec4(pow(1.-exp(-light*uExposure),vec3(1./2.2)),alpha);
}
