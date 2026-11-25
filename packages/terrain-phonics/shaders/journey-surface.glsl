// Mix linear surface properties first so the common sunlight, shadows, water and air run once.
uniform bool uBlendProfile;
// A landscape shows its detail from this share of the leading landscape's weight upward.
const float JOURNEY_DETAIL = .97;
vec3 journeySurface(vec3 world, vec3 normal, vec3 relative, float footprint, bool pavement,
	out vec3 litNormal, out vec2 finish, out vec3 bounce)
{
	#ifdef WORLD_MATERIAL_FIELDS
	vec2 cell=(world.xz-uFieldWindow.xy)/uFieldWindow.z;
	fieldResident=all(greaterThanEqual(cell,vec2(0.))) && all(lessThan(cell,vec2(1./uFieldWindow.w-1.)));
	fieldUV=(world.xz/uFieldWindow.z+.5)*uFieldWindow.w;
	#endif
	vec2 t = clamp(world.xz / uJoinWidth + .5, 0., 1.);
	// Break up the material boundary in world space. The envelope vanishes at both pure landscapes.
	bool blended=t.x>0. && t.x<1.;
	#if JOURNEY_REGIONS == 3
	blended=blended || (t.x>0. && t.y>0. && t.y<1.);
	#endif
	if(uBlendProfile && blended) {
		float fine=1.-smoothstep(36.,144.,footprint);
		vec4 bands =
			#ifdef WORLD_MATERIAL_FIELDS
			fieldResident ? 2.*textureLod(uMaterialFields,vec3(fieldUV,3.),0.)-1. :
			#endif
			scatterBands(world.xz);
		vec2 scatter=bands.xy + .45*fine*bands.zw;
		t=clamp(t+.20*t*(1.-t)*scatter,0.,1.);
	}
	vec2 fade = clamp(t*t*t*(t*(t*6.-15.)+10.), 0., 1.);
	vec3 weights = vec3(1.-fade.x, fade.x, 0.);
	#if JOURNEY_REGIONS == 3
	weights.yz = fade.x * vec2(1.-fade.y, fade.y);
	#endif
	// At the edge of a seam, even a trace of pigment would run a whole landscape's shader.
	// Ease its share back in above 0.1%, keeping the other materials in proportion.
	if(blended) {
		weights *= smoothstep(vec3(.001),vec3(.002),weights);
		weights /= dot(weights,vec3(1.));
	}
	vec3 albedo = vec3(0.);
	litNormal = vec3(0.);
	finish = vec2(0.);
	bounce = vec3(0.);
	#ifdef READ_SURFACE_SAMPLES
	litNormal = normal;
	if(!pavement && uCraft<.5 && !sampleSurface(normal,albedo,litNormal)) {
		// An isolated subpixel tip can miss the source grid completely. Use its own landscapes' base pigment.
		albedo = weights.x*palettes[0].colors[0]+weights.y*palettes[1].colors[0];
		#if JOURNEY_REGIONS == 3
		albedo += weights.z*palettes[2].colors[0];
		#endif
	}
	bounce=weights.x*palettes[0].bounce.rgb+weights.y*palettes[1].bounce.rgb;
	#if JOURNEY_REGIONS == 3
	bounce+=weights.z*palettes[2].bounce.rgb;
	#endif
	#else
	// Only the leading landscape at a pixel runs its whole shader, so a seam costs about what one landscape does.
	// The others add a plain color. Near an even split the runner-up's detail fades in, so that no line shows where
	// the lead changes hands.
	float lead = max(weights.x,max(weights.y,weights.z));
	vec3 detail = smoothstep(vec3(JOURNEY_DETAIL*lead),vec3(lead),weights);
	// SAMPLE REGIONS
	litNormal = pavement ? normal : normalize(litNormal);
	#endif
	return albedo;
}
