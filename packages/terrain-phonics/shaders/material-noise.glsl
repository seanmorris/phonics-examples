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

// A compact radial kernel on a triangular lattice. Its value and first derivative
// both vanish at its rim, so a vegetation or weathering boundary can cross it freely.
vec3 coverCorner(ivec2 cell, vec2 d)
{
	const vec2 dirs[8] = vec2[8](
		vec2(.92388,.38268), vec2(.38268,.92388), vec2(-.38268,.92388), vec2(-.92388,.38268),
		vec2(-.92388,-.38268), vec2(-.38268,-.92388), vec2(.38268,-.92388), vec2(.92388,-.38268));
	vec2 g = dirs[layerHash(cell)>>29];
	float w = max(.5-dot(d,d),0.), w2 = w*w, projection = dot(g,d);
	return vec3(w2*w2*projection, w2*w2*g - 8.*w2*w*projection*d);
}

// Signed simplex noise and its derivatives in the input coordinates. Salt the
// lattice indices to keep neighboring samples precise even with a large palette seed.
const float COVER_FREQUENCY = .66;
vec3 coverNoise(vec2 p)
{
	const float skew = .366025404, unskew = .211324865;
	p *= COVER_FREQUENCY;
	vec2 ij = floor(p + (p.x+p.y)*skew);
	vec2 d = p - ij + (ij.x+ij.y)*unskew;
	ivec2 cell = ivec2(ij) + ivec2(uPaletteOffset);
	ivec2 side = d.x > d.y ? ivec2(1,0) : ivec2(0,1);
	vec3 sampleValue = coverCorner(cell,d) + coverCorner(cell+side,d-vec2(side)+unskew)
		+ coverCorner(cell+1,d-1.+2.*unskew);
	return 35.*sampleValue*vec3(1.,COVER_FREQUENCY,COVER_FREQUENCY);
}

// Area-filter a band by the number of lattice intervals a pixel spans.
float coverBand(float footprint, float scale)
{
	return 1.-smoothstep(.18,1.55,COVER_FREQUENCY*footprint/scale);
}

uniform float uRockSlopeDamping; // Landform damping divided by slope scale squared.
float rockReliefWeight(float width, float smoothingWidth2)
{
	return width*width / (width*width + smoothingWidth2);
}

// Stretch the volume vertically, then slice it in two oblique directions.
// Both horizontal coordinates still vary on shallow ground, so a gully ends
// in the foothills instead of projecting a cliff streak across the meadow.
vec4 ribSample(vec3 p, vec2 scale)
{
	const mat3 turn = mat3(2.,2.,1., -2.,1.,2., 1.,-2.,2.)/3.;
	vec3 meters = scale.xyx, q = turn*(p/meters);
	vec3 a = coverNoise(q.xy), b = coverNoise(q.yz + vec2(-13.3,9.1));
	vec3 gradient = vec3(a.y,a.z+b.y,b.z)*turn/meters;
	return .70710678*vec4(a.x+b.x,gradient);
}

// Vary the proportion of two materials in a transition. Its mean comes from
// the terrain. When a noise band falls below a pixel it contributes zero bias.
float coverFraction(float area, float variation)
{
	float bias = clamp(variation,-.85,.85);
	return area*(1.+bias)/(1.+bias*(2.*area-1.));
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

// The dark trace and the signed slope of its shallow groove both end inside the
// band. Fade the slope once the two sides of the groove fall in the same pixel.
vec2 fractureProfile(float phase, int family)
{
	float center = .5 + .32*layerCorner(ivec2(int(floor(phase)),family) + ivec2(uPaletteOffset));
	float offset = fract(phase) - center;
	float aa = clamp(.5*fwidth(phase), .003, .13);
	const float width = .009;
	float trace = (1.-smoothstep(max(0.,width-aa),width+aa,abs(offset))) * min(1.,width/aa);
	float t = clamp(abs(offset)/.065, 0., 1.);
	float slope = sign(offset)*6.*t*(1.-t) * (1.-smoothstep(.02,.065,aa));
	return vec2(trace,slope);
}
