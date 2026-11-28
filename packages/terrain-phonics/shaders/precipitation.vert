#version 300 es
precision highp float;
precision highp int;
uniform mat4 uViewProjection;
uniform vec3 uEye,uRight,uUp;
uniform vec2 uWeatherOffset,uFall,uWind;
uniform vec2 uHeightBand;
uniform vec2 uView; // angle one pixel spans, viewport height in pixels
uniform vec3 uDrift; // the eye's own velocity in meters per second
out vec2 vCorner;
out float vAlpha;
out vec3 vFlake; // snow: quad size in flake radii and glow strength; both: depth from 0 near the eye to 1 far away
uint mixBits(uint x) { x=(x^(x>>16u))*2246822519u; return (x^(x>>13u))*3266489917u; }
void main()
{
	uint id=uint(gl_InstanceID)*3u;
	vec3 seed=vec3(mixBits(id+1u),mixBits(id+2u),mixBits(id+3u))/4294967296.;
	bool snow=uFall.y>.5;
	// Snow fills a cube 64 meters across around the eye, and rain one 48 meters across.
	float size=snow ? 64. : 48.;
	vec2 drift=snow ? .45*vec2(sin(uFall.x*.8+seed.y*28.),cos(uFall.x*.8+seed.x*35.)) : vec2(0.);
	vec3 anchor=vec3(uWeatherOffset.x+drift.x,-uFall.x*(snow ? 1.5 : 16.),uWeatherOffset.y+drift.y);
	vec3 local=mod(seed*size+anchor-uEye+.5*size,size)-.5*size;
	float dist=length(local);
	float height=uEye.y+local.y;
	vAlpha=(1.-smoothstep(.375*size,.5*size,dist))*smoothstep(.65,2.,dist)
		*clamp((uHeightBand.y-height)*.5,0.,1.)*clamp((height-uHeightBand.x)*2.,0.,1.);
	vCorner=vec2(float(gl_VertexID&1),float(gl_VertexID>>1))*2.-1.;
	float depth=smoothstep(1.5,.44*size,dist);
	float pixel=dist*uView.x; // meters one pixel spans where the flake or drop is
	vFlake=vec3(1.,0.,depth);
	if(snow) {
		// A flake's size on screen stays within a narrow range. How near it is shows in how bright it is and how far
		// it glows.
		float radius=clamp(.03,.0012*uView.y*pixel,.0034*uView.y*pixel);
		float reach=1.+3.*(1.-depth);
		vAlpha*=mix(1.,.35,depth);
		vFlake=vec3(reach,.6*(1.-depth),depth);
		local+=(uRight*vCorner.x+uUp*vCorner.y)*radius*reach;
	} else {
		// A streak is the path a drop covers in the thirtieth of a second the eye registers it: its fall and the wind,
		// less the eye's own motion, so rain slants toward a moving car. A streak stays about a pixel wide, and how
		// near it is shows in how bright it is.
		vec3 axis=vec3(uDrift.x-uWind.x,16.+uDrift.y,uDrift.z-uWind.y)/60.;
		vec3 side=normalize(cross(local,axis)+vec3(1e-5,0.,0.));
		vAlpha*=mix(.5,.12,depth);
		local+=side*clamp(.003,.0004*uView.y*pixel,.001*uView.y*pixel)*vCorner.x+axis*vCorner.y;
	}
	gl_Position=uViewProjection*vec4(local,1.);
}
