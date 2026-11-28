#version 300 es
precision highp float;
precision highp int;
uniform mat4 uViewProjection;
// ATMOSPHERE
uniform float uPixelScale, uStarVisibility, uStarAltitude;
uniform float uStarGain, uTwinkle, uStarTime, uMeteorAge;
uniform int uMeteorId;
flat out int vMeteor;
out vec2 vTrail;
out vec3 vColor;
out float vOpacity;

// Each ID is a permanent direction on the celestial hemisphere. Uniform height gives equal solid angle.
float randomUnit(uint value)
{
	value ^= value >> 16u;
	value *= 0x7feb352du;
	value ^= value >> 15u;
	value *= 0x846ca68bu;
	value ^= value >> 16u;
	return float(value >> 8u) / 16777216.0;
}

float visibility(float altitude)
{
	float fade = uStarVisibility * smoothstep(0.012,0.19,altitude);
	if(uAirLayerCount > 0.) fade *= exp(-terrainAirDepth(uStarAltitude,max(.001,altitude),1e20));
	return fade;
}

// A short tapered ribbon follows a great circle across the sky. All four corners remain at sky depth.
void meteor()
{
	uint seed = uint(uMeteorId)*7u+191u;
	float azimuth = 6.2831853*randomUnit(seed);
	float elevation = mix(.30,1.35,randomUnit(seed+1u));
	vec3 horizontal = vec3(cos(azimuth),0.,sin(azimuth));
	vec3 origin = cos(elevation)*horizontal + vec3(0.,sin(elevation),0.);
	vec3 down = sin(elevation)*horizontal - vec3(0.,cos(elevation),0.);
	vec3 level = vec3(-horizontal.z,0.,horizontal.x);
	float slant = mix(-1.18,1.18,randomUnit(seed+2u));
	vec3 along = down*cos(slant)+level*sin(slant);
	vec3 side = cross(origin,along);
	float front = float(gl_VertexID & 1);
	float lateral = 2.*float((gl_VertexID-2400)>>1)-1.;
	float travel = mix(.17,.44,randomUnit(seed+3u));
	float phase = max(0.,uMeteorAge-(1.-front)*.39)*travel;
	vec3 ray = cos(phase)*origin+sin(phase)*along + side*lateral*mix(.0004,.0017,front);
	vec4 clip = uViewProjection*vec4(ray,0.);
	gl_Position = vec4(clip.xy,clip.w,clip.w);
	vTrail = vec2(front,lateral);
	vColor = vec3(.83,.90,1.)*(1.-exp(-3.*uExposure));
	vOpacity = visibility(ray.y)*smoothstep(0.,.09,uMeteorAge)*(1.-smoothstep(.68,1.,uMeteorAge));
}

void main()
{
	vMeteor = gl_VertexID>=2400 ? 1 : 0;
	if(vMeteor!=0) { meteor(); return; }
	vTrail = vec2(0.);
	uint id = uint(gl_VertexID) * 4u + 1u;
	float azimuth = randomUnit(id) * 6.28318530718;
	float height = randomUnit(id + 1u);
	float radius = sqrt(1.0 - height * height);
	vec3 ray = vec3(radius * cos(azimuth), height, radius * sin(azimuth));
	vec4 clip = uViewProjection * vec4(ray, 0.0);
	gl_Position = vec4(clip.xy, clip.w, clip.w);

	float magnitude = pow(randomUnit(id + 2u), 4.0);
	gl_PointSize = (2.4 + 1.8 * magnitude) * uPixelScale;
	vec3 tint = mix(vec3(0.72,0.82,1.0), vec3(1.0,0.89,0.76), randomUnit(id + 3u));
	float gain = uStarGain;
	if(uTwinkle>0.) {
		// Integer cycles per 1024 seconds keep the animation continuous when its clock wraps.
		float phase = 6.2831853*randomUnit(id+9701u);
		float angle = (6.2831853/1024.)*uStarTime;
		float rate = floor(mix(460.,1180.,randomUnit(id+9702u)));
		float wave = .68*sin(angle*rate+phase)+.32*sin(angle*(rate+377.)+2.*phase);
		gain *= 1.+uTwinkle*mix(.94,.58,height)*wave;
	}
	vColor = sqrt(1.0 - exp(-uExposure * gain * tint * (0.10 + 2.0 * magnitude)));
	vOpacity = visibility(height);
}
