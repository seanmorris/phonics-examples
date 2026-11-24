// Mix linear surface properties first so the common sunlight, shadows, water and air run once.
vec3 journeySurface(vec3 world, vec3 normal, vec3 relative, float footprint, bool pavement,
	out vec3 litNormal, out vec2 finish, out vec3 bounce)
{
	vec2 t = clamp(world.xz / uJoinWidth + .5, 0., 1.);
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
