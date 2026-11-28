// CPU parity: TerrainPhonics\Water. Units are meters and seconds; Y is up.
#define TERRAIN_MAX_WAVES 8
#define TERRAIN_MAX_WAKES 16
uniform float uWaterLevel;
uniform float uWaterTime;
uniform int uWaveCount;
uniform vec4 uWaveVectors[TERRAIN_MAX_WAVES];
uniform vec2 uWaveDynamics[TERRAIN_MAX_WAVES];
uniform int uWakeCount;
uniform vec4 uWakeVectors[TERRAIN_MAX_WAKES];

// Components: height, dHeight/dX, dHeight/dZ, dHeight/dTime.
vec4 terrainDirectionalSample(vec2 position, float time)
{
	vec4 result = vec4(uWaterLevel, 0.0, 0.0, 0.0);
	for(int i = 0; i < TERRAIN_MAX_WAVES; ++i)
	{
		if(i >= uWaveCount) break;
		vec4 wave = uWaveVectors[i];
		vec2 dynamics = uWaveDynamics[i];
		float phase = wave.z * dot(wave.xy, position) - dynamics.x * time + dynamics.y;
		float slope = wave.w * cos(phase);
		result += vec4(wave.w * sin(phase), slope * wave.z * wave.xy, -slope * dynamics.x);
	}
	return result;
}

vec4 terrainWaterSample(vec2 position, float time)
{
	vec4 result = terrainDirectionalSample(position,time);
	for(int i = 0; i < TERRAIN_MAX_WAKES; ++i)
	{
		if(i >= uWakeCount) break;
		vec4 wake = uWakeVectors[i];
		float age = time - wake.w;
		if(age <= 0.0 || age >= 8.0) continue;
		vec2 delta = position - wake.xy;
		float radiusSquared = dot(delta,delta) + 0.04;
		// Past 18 m from the wavefront its Gaussian envelope is below float32 range.
		float outer = 3.0 * age + 18.0;
		if(radiusSquared > outer * outer) continue;
		float radius = sqrt(radiusSquared);
		float q = radius - 3.0 * age;
		float fade = pow(sin(3.141592653589793 * age / 8.0), 2.0);
		float envelope = wake.z * exp(-0.45 * age - 0.35 * q * q);
		float phase = 2.2 * q;
		float radial = envelope * fade * (2.2 * cos(phase) - 0.7 * q * sin(phase));
		float fadeRate = 3.141592653589793 / 8.0 * sin(2.0 * 3.141592653589793 * age / 8.0);
		result += vec4(envelope * fade * sin(phase), radial * delta / radius,
			envelope * (fadeRate - 0.45 * fade) * sin(phase) - 3.0 * radial);
	}
	return result;
}

vec3 terrainWaterNormal(vec4 sampleValue)
{
	return normalize(vec3(-sampleValue.y, 1.0, -sampleValue.z));
}

// Render-time packets contain the center, wavefront radius, and age-attenuated amplitude.
uniform vec4 uFrameWakes[TERRAIN_MAX_WAKES], uWakeBounds;
uniform int uFrameWakeCount;
uniform vec2 uJetVelocities[TERRAIN_MAX_WAKES];

// Height, horizontal slopes and foam. The vertex shader only consumes height.
vec4 terrainFrameWakes(vec2 position, vec2 pixelX, vec2 pixelY, bool shading)
{
	vec4 result = vec4(0.0);
	if(uFrameWakeCount == 0 || any(lessThan(position,uWakeBounds.xy)) || any(greaterThan(position,uWakeBounds.zw))) return result;
	float footprint2 = max(dot(pixelX,pixelX),dot(pixelY,pixelY));
	for(int i = 0; i < TERRAIN_MAX_WAKES; ++i)
	{
		if(i >= uFrameWakeCount) break;
		vec4 packet = uFrameWakes[i];
		vec2 delta = position - packet.xy;
		float r2 = dot(delta,delta) + 0.04;
		float outer = packet.z + 18.0;
		if(r2 > outer * outer) continue;
		float r = sqrt(r2);
		float q = r - packet.z;
		float weight = 1.0;
		// Radial distance changes by at most the length of the pixel displacement.
		if(shading && footprint2 > 0.50979)
		{
			vec2 x = delta + pixelX, y = delta + pixelY;
			float span = 2.2 * max(abs(sqrt(dot(x,x)+0.04)-r),abs(sqrt(dot(y,y)+0.04)-r));
			weight -= smoothstep(1.57079632679,3.14159265359,span);
		}
		float envelope = weight * packet.w * exp(-0.35*q*q);
		float oscillation = sin(2.2*q);
		result.x += envelope * oscillation;
		if(!shading) continue;
		result.yz += envelope * (2.2*cos(2.2*q) - 0.7*q*oscillation) * delta/r;

		vec2 velocity = uJetVelocities[i];
		float speed2 = dot(velocity,velocity);
		float age = packet.z / 3.0;
		if(speed2 <= 4.0 || age >= 3.0) continue;
		// Each emission covers the short stretch traveled until the next stern sample.
		float along = clamp(dot(delta,velocity)/speed2,0.0,min(age,0.21));
		float ageHere = age - along;
		float lateral = length(delta - along*velocity);
		float edge = 0.44 + 2.8*ageHere;
		float arms = 1.0 - smoothstep(0.12,0.42+0.2*ageHere,abs(lateral-edge));
		float churn = (1.0-smoothstep(0.12,0.55+ageHere,lateral)) * exp(-1.9*ageHere);
		float foam = max(churn,arms) * (1.0-smoothstep(0.25,2.8,ageHere)) * smoothstep(4.0,90.0,speed2);
		result.w = max(result.w,foam);
	}
	return result;
}
