float hashCell(vec2 cell)
{
	vec3 p = fract(vec3(cell.xyx) * 0.1031);
	p += dot(p, p.yzx + 33.33);
	return fract((p.x + p.y) * p.z);
}
float smoothNoise(vec2 p)
{
	vec2 cell = floor(p), f = fract(p);
	vec2 w = f * f * f * (f * (f * 6.0 - 15.0) + 10.0);
	return mix(mix(hashCell(cell), hashCell(cell + vec2(1.0,0.0)), w.x),
		mix(hashCell(cell + vec2(0.0,1.0)), hashCell(cell + vec2(1.0)), w.x), w.y);
}
float landVariation(vec2 position, float footprint)
{
	float broad = (smoothNoise(position / 420.0) - 0.5)
		* (1.0 - smoothstep(95.0,420.0,footprint));
	float middle = (smoothNoise(position / 95.0 + 17.31) - 0.5)
		* (1.0 - smoothstep(20.0,95.0,footprint));
	float small = (smoothNoise(position / 22.0 - 53.73) - 0.5)
		* (1.0 - smoothstep(5.0,22.0,footprint));
	return broad * 1.2 + middle * 0.55 + small * 0.25;
}
// Project onto the two upright planes so weathering runs down exposed rock faces.
float cliffNoise(vec3 p, vec2 scale, float xWeight)
{
	return mix(smoothNoise(p.xy / scale), smoothNoise(p.zy / scale), xWeight);
}

// Value noise with its derivatives along both axes.
vec3 smoothNoiseGradient(vec2 p)
{
	vec2 cell = floor(p), f = fract(p);
	vec2 w = f * f * f * (f * (f * 6.0 - 15.0) + 10.0), slope = 30.0 * f * f * (f * (f - 2.0) + 1.0);
	float a = hashCell(cell), b = hashCell(cell + vec2(1.0,0.0)), c = hashCell(cell + vec2(0.0,1.0));
	float d = hashCell(cell + vec2(1.0)), k = a - b - c + d;
	return vec3(mix(mix(a, b, w.x), mix(c, d, w.x), w.y), slope * vec2(b - a + k*w.y, c - a + k*w.x));
}

// cliffNoise() with its gradient in world space: the value, then its derivatives along x, y and z.
vec4 cliffNoiseGradient(vec3 p, vec2 scale, float xWeight)
{
	vec3 front = smoothNoiseGradient(p.xy / scale), side = smoothNoiseGradient(p.zy / scale);
	return vec4(mix(front.x, side.x, xWeight), (1.0 - xWeight) * front.y / scale.x,
		mix(front.z, side.z, xWeight) / scale.y, xWeight * side.y / scale.x);
}

// Share of the ground that a patchy material covers. The noise mostly runs from -0.5 to 0.5, peaks near -0.9 and 0.9,
// and has been faded for the footprint; `detail` is how much of it is left. As it fades, the edge widens into a blend
// with about the same mean. The edge is never narrower than a pixel.
float patchy(float amount, float noise, float detail)
{
	float value = amount - 0.5 + noise;
	float edge = max(mix(0.5, 0.1, detail), fwidth(value));
	return smoothstep(-edge, edge, value);
}

// A patch whose amount must leave none at 0. The noise of patchy() reaches past 0.5, so it would still cover a few
// percent of the ground there; below an amount of 0.1 the cover also falls with the amount.
float sparse(float amount, float noise, float detail)
{
	return patchy(amount, noise, detail) * min(1.0, 10.0*amount);
}
