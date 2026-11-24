// Mix linear surface properties first so the common sunlight, shadows, water and air run once.
uniform bool uBlendProfile;
vec3 journeySurface(vec3 world, vec3 normal, vec3 relative, float footprint, bool pavement,
	out vec3 litNormal, out vec2 finish, out vec3 bounce)
{
	vec2 t = clamp(world.xz / uJoinWidth + .5, 0., 1.);
	// Break up the material boundary in world space. The envelope vanishes at both pure landscapes.
	bool blended=t.x>0. && t.x<1.;
	#if JOURNEY_REGIONS == 3
	blended=blended || (t.x>0. && t.y>0. && t.y<1.);
	#endif
	if(uBlendProfile && blended) {
		vec2 uv=world.xz/360.;
		float fine=1.-smoothstep(36.,144.,footprint);
		vec2 scatter=vec2(layerValueNoise(uv*.37),layerValueNoise(uv*.37+23.))
			+ .45*fine*vec2(layerValueNoise(uv+13.),layerValueNoise(uv-7.));
		t=clamp(t+.20*t*(1.-t)*scatter,0.,1.);
	}
	vec2 fade = clamp(t*t*t*(t*(t*6.-15.)+10.), 0., 1.);
	vec3 weights = vec3(1.-fade.x, fade.x, 0.);
	#if JOURNEY_REGIONS == 3
	weights.yz = fade.x * vec2(1.-fade.y, fade.y);
	#endif
	vec3 albedo = vec3(0.);
	litNormal = vec3(0.);
	finish = vec2(0.);
	bounce = vec3(0.);
	// SAMPLE REGIONS
	litNormal = pavement ? normal : normalize(litNormal);
	return albedo;
}
