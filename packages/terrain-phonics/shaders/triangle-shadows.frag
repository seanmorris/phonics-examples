#version 300 es
precision highp float;
precision highp int;
uniform highp sampler2D uCaster, uOverview, uVertices;
uniform vec3 uShadowGrid; // Lower-left world X/Z and metres per triangle cell.
uniform float uCasterStep, uReach, uExtent, uPlayHalf;
uniform vec3 uSun;
uniform bool uPacked, uRefine;
out vec4 color;

float elevation(highp sampler2D field, ivec2 cell)
{
	vec4 t = texelFetch(field, cell, 0);
	if(!uPacked) return t.r;
	uvec4 b = uvec4(t * 255. + .5);
	return uintBitsToFloat(b.x | (b.y << 8) | (b.z << 16) | (b.w << 24));
}

float heightAt(highp sampler2D field, vec2 cell, bool torus)
{
	ivec2 a = ivec2(floor(cell)), b = a + 1, last = textureSize(field,0) - 1;
	vec2 f = fract(cell);
	a = torus ? a & last : clamp(a,ivec2(0),last);
	b = torus ? b & last : clamp(b,ivec2(0),last);
	return mix(mix(elevation(field,a), elevation(field,ivec2(b.x,a.y)), f.x),
		mix(elevation(field,ivec2(a.x,b.y)), elevation(field,b), f.x), f.y);
}

// One horizontal step per caster texel. PHP guarantees the entire near ray is resident,
// including its bilinear neighbours. Continue on the fixed overview to meet the broad shadow.
float clearance(vec2 vertex, bool stopAtBlocker)
{
	vec2 world = uShadowGrid.xy + vertex * uShadowGrid.z;
	vec2 cell = world / uCasterStep;
	float horizontal = length(uSun.xz);
	if(horizontal < .001) return 2.;
	vec2 direction = uSun.xz / horizontal;
	float grade = uSun.y / horizontal;
	float y = heightAt(uCaster,cell,true) + max(.3, .02*uCasterStep);
	float margin = 2.;
	for(int i = 1; i <= 128; ++i)
	{
		if(float(i) > uReach) break;
		vec2 probe = cell+float(i)*direction;
		if(uPlayHalf > 0. && max(abs(probe.x),abs(probe.y))*uCasterStep > uPlayHalf) break;
		margin = min(margin,grade+(y-heightAt(uCaster,probe,true))/(float(i)*uCasterStep));
		if(margin <= -2. || (stopAtBlocker && margin < 0.)) return max(-2.,margin);
	}
	float stride = uExtent / float(textureSize(uOverview,0).x-1);
	for(int i = 1; i <= 32; ++i)
	{
		float run = uReach*uCasterStep + float(i)*stride;
		vec2 p = world + direction*run;
		if(max(abs(p.x),abs(p.y)) >= .5*uExtent) break;
		margin = min(margin,grade+(y-heightAt(uOverview,(p+.5*uExtent)/stride,false))/run);
		if(margin <= -2. || (stopAtBlocker && margin < 0.)) return max(-2.,margin);
	}
	return margin;
}

// Keep the signed difference between the sun slope and the highest blocker slope. Its zero
// crossing locates the shadow edge much more precisely than interpolating lit/dark flags.
float sharedMargin(ivec2 vertex)
{
	vec2 bytes = texelFetch(uVertices,vertex,0).rg;
	return dot(bytes,vec2(256.,1.))*(4./257.)-2.;
}

float crossing(vec2 a, vec2 b, float start, float end)
{
	float mid = clearance(.5*(a+b),false);
	return (start >= 0.) == (mid >= 0.)
		? .5+.5*mid/(mid-end) : .5*start/(start-mid);
}

void main()
{
	ivec2 pixel = ivec2(gl_FragCoord.xy);
	if(!uRefine)
	{
		int code = int(round((clearance(vec2(pixel),false)+2.)*(65535./4.)));
		color = vec4(vec2(code>>8,code&255)/255.,0.,1.);
		return;
	}
	bool upper = (pixel.x & 1) != 0;
	ivec2 origin = ivec2(pixel.x/2,pixel.y) + (upper ? 1 : 0);
	int side = upper ? -1 : 1;
	ivec2 vertices[3] = ivec2[3](origin,origin+ivec2(side,0),origin+ivec2(0,side));
	vec3 margins = vec3(sharedMargin(vertices[0]),sharedMargin(vertices[1]),sharedMargin(vertices[2]));
	bvec3 lit = greaterThanEqual(margins,vec3(0.));
	if(all(lit) || !any(lit))
	{
		// A narrow shadow or sunbeam can miss all three vertices. Split such an interior
		// feature into three triangles that share the centre and one edge of the original.
		vec2 center = vec2(origin)+vec2(float(side)/3.);
		// For three dark corners any blocker settles the centre. Only a lit centre needs its full margin.
		float middle = clearance(center,!lit.x);
		if((middle >= 0.) == lit.x)
			color = vec4(float(lit.x)/255.,0.,0.,0.);
		else
			color = vec4(float(middle >= 0. ? 9 : 8)/255.,
				crossing(center,vec2(vertices[0]),middle,margins.x),
				crossing(center,vec2(vertices[1]),middle,margins.y),
				crossing(center,vec2(vertices[2]),middle,margins.z));
		return;
	}
	int i = lit.y == lit.z ? 0 : (lit.x == lit.z ? 1 : 2);
	int j = (i+1)%3, k = (i+2)%3;
	color = vec4(float(2+i+(lit[i] ? 3 : 0))/255.,
		crossing(vec2(vertices[i]),vec2(vertices[j]),margins[i],margins[j]),
		crossing(vec2(vertices[i]),vec2(vertices[k]),margins[i],margins[k]),0.);
}
