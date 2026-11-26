#version 300 es
precision highp float;
precision highp int;
uniform mat4 uViewProjection;
uniform vec3 uEye,uRight,uUp;
uniform vec2 uWeatherOffset,uFall,uWind;
uniform vec2 uHeightBand;
out vec2 vCorner;
out float vAlpha;
uint mixBits(uint x) { x=(x^(x>>16u))*2246822519u; return (x^(x>>13u))*3266489917u; }
void main()
{
	uint id=uint(gl_InstanceID)*3u;
	vec3 seed=vec3(mixBits(id+1u),mixBits(id+2u),mixBits(id+3u))/4294967296.;
	bool snow=uFall.y>.5;
	vec2 drift=snow ? .45*vec2(sin(uFall.x*.8+seed.y*28.),cos(uFall.x*.8+seed.x*35.)) : vec2(0.);
	vec3 anchor=vec3(uWeatherOffset.x+drift.x,-uFall.x*(snow ? 1.5 : 16.),uWeatherOffset.y+drift.y);
	vec3 local=mod(seed*32.+anchor-uEye+16.,32.)-16.;
	float dist=length(local);
	float height=uEye.y+local.y;
	vAlpha=(1.-smoothstep(12.,16.,dist))*smoothstep(.65,2.,dist)
		*clamp((uHeightBand.y-height)*.5,0.,1.)*clamp((height-uHeightBand.x)*2.,0.,1.);
	vCorner=vec2(float(gl_VertexID&1),float(gl_VertexID>>1))*2.-1.;
	local+=uRight*vCorner.x*(snow ? .042 : .014)+(snow ? uUp*.042 : vec3(-uWind.x,16.,-uWind.y)*.02)*vCorner.y;
	gl_Position=uViewProjection*vec4(local,1.);
}
