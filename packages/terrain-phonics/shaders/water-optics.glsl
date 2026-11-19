uniform vec3 uWaterTint;
// Surface transmission, reflection strength, extinction per metre, and foam coverage.
uniform vec4 uWaterOptics;

vec3 waterColumnLight(vec3 light, vec3 eyeToPoint, float eyeHeight, float level, float illumination)
{
	// Intersect the actual sightline to this fragment with the mean water plane.
	float eyeDepth = level - eyeHeight;
	float pointDepth = eyeDepth - eyeToPoint.y;
	if(max(eyeDepth, pointDepth) <= 0.0) return light;
	float wetFraction = min(eyeDepth, pointDepth) >= 0.0 ? 1.0
		: max(eyeDepth, pointDepth) / abs(eyeToPoint.y);
	float transmission = exp(-uWaterOptics.z * wetFraction * length(eyeToPoint));
	return mix(uWaterTint * illumination, light, transmission);
}
