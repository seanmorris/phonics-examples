#version 300 es
precision highp float;
// WEATHER
uniform vec3 uEye;
uniform float uExposure;
uniform vec3 uFlash[4]; // where each lit strike leaves the cloud, as X and Z in meters, and how brightly it shines
uniform int uFlashes;
uniform vec3 uDim; // the share of its own light the cloud loses while lightning is lit
uniform vec3 uFlashColor; // the color a strike lights the cloud with
in vec2 vDelta;
out vec4 color;
void main()
{
	vec2 world=uEye.xz+vDelta;
	vec2 cloud=cloudSample(world);
	float dy=uEye.y-uDeck.y;
	float fade=1.-smoothstep(35000.,60000.,length(vec3(vDelta,dy)));
	float alpha=cloud.x*fade*smoothstep(0.,22.,abs(dy));
	vec3 light=cloudRadiance(cloud.y,smoothstep(-20.,20.,dy));
	vec3 shown=pow(1.-exp(-light*uExposure),vec3(1./2.2));
	// While lightning is lit the cloud darkens as the land does, and each strike lights it again around its top:
	// fully at the bolt, a quarter as much 1,400 meters away and almost nothing past three kilometers. Thin cloud
	// passes more of that light than thick cloud, so the flash shows the deck's structure.
	if(uFlashes>0) {
		float lit=0.;
		for(int i=0;i<uFlashes;i++) {
			vec2 d=(world-uFlash[i].xy)/1400.;
			float near=1./(1.+dot(d,d));
			lit+=uFlash[i].z*near*near;
		}
		vec3 glow=uFlashColor*min(lit,1.)*mix(1.,.6,cloud.y);
		shown*=1.-uDim;
		shown+=glow*(1.-shown);
	}
	color=vec4(shown,alpha);
}
